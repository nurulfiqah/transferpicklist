<?php
// Tableau CSV Archive viewer — browse / preview / download the raw CSVs that
// every hyper/*_sync.py saves per outlet per run (see hyper/csv_archive.py).
// Layout: hyper/csv_archive/<dashboard>/<YYYYMMDD>/<outlet>[_<part>][_<HHMMSS>].csv
// (older runs may still sit in <YYYYMMDD_HHMMSS> folders until the next sync merges them)
ob_start(); // lock_adv.php may echo before the download headers below are sent
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
// Admin (transferpicklist_autho >= 1) or Procurement (department 18) — same rule as the Procurement List page
$_tpl_archive_access = ((int)$transferpicklist_autho >= 1) || ((int)$department === 18);
if (!$_tpl_archive_access) { redirect('/odb/permission.php'); }

$archiveRoot = __DIR__ . '/hyper/csv_archive';

// Block direct URL access to the raw files — they must go through this page's
// access check. (Apache honours .htaccess; created once if missing.)
if (is_dir($archiveRoot) && !file_exists($archiveRoot . '/.htaccess')) {
    @file_put_contents($archiveRoot . '/.htaccess', "Require all denied\nDeny from all\n");
}

$dashLabels = array(
    'itemgrade'          => 'Item Grade',
    'nonmoving'          => 'Non-Moving (6M)',
    'nonmoving_ethical'  => 'Non-Moving Ethical (6M)',
    'slowmoving'         => 'Slow Moving',
    'slowmoving_ethical' => 'Slow Moving Ethical',
    'past3months'        => 'Past 3 Months',
);

// ── Input (strictly validated — names only, never paths) ──────────────
$d  = isset($_GET['d']) ? $_GET['d'] : '';
$r  = isset($_GET['r']) ? $_GET['r'] : '';
$f  = isset($_GET['f']) ? $_GET['f'] : '';
$q  = isset($_GET['q']) ? strtoupper(trim($_GET['q'])) : '';
if (!preg_match('/^[a-z0-9_]+$/', $d))              { $d = ''; }
if (!preg_match('/^\d{8}(_\d{6})?$/', $r))         { $r = ''; }
if (!preg_match('/^[A-Za-z0-9_.-]+\.csv$/', $f))    { $f = ''; }
if (!preg_match('/^[A-Z0-9_]*$/', $q))              { $q = ''; }

function csvPath($root, $d, $r = '', $f = '') {
    $p = $root . '/' . $d . ($r !== '' ? '/' . $r : '') . ($f !== '' ? '/' . $f : '');
    $real = realpath($p);
    $rootReal = realpath($root);
    if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0) { return false; }
    return $real;
}
function runLabel($r) {
    if (strlen($r) === 8) {
        $t = DateTime::createFromFormat('Ymd', $r);
        return $t ? $t->format('d M Y (D)') : $r;
    }
    $t = DateTime::createFromFormat('Ymd_His', $r);
    return $t ? $t->format('d M Y, h:i:s A') : $r;
}
function fmtSize($b) {
    if ($b >= 1048576) { return number_format($b / 1048576, 1) . ' MB'; }
    if ($b >= 1024)    { return number_format($b / 1024, 1) . ' KB'; }
    return $b . ' B';
}
function listDirs($path, $pattern) {
    $out = array();
    if (is_dir($path)) {
        foreach (scandir($path) as $n) {
            if ($n[0] !== '.' && is_dir($path . '/' . $n) && preg_match($pattern, $n)) { $out[] = $n; }
        }
    }
    return $out;
}
function listCsv($path) {
    $out = array();
    if (is_dir($path)) {
        foreach (scandir($path) as $n) {
            if (preg_match('/\.csv$/i', $n) && is_file($path . '/' . $n)) { $out[] = $n; }
        }
    }
    sort($out);
    return $out;
}
function outletOf($file) {
    // "NS2BA2.csv" / "NS2BA2_flat_down.csv" / "NS2BA2_flat_down_170042.csv" -> NS2BA2
    $base = preg_replace('/\.csv$/i', '', $file);
    $base = preg_replace('/_\d{6}$/', '', $base);   // later sync the same day
    return preg_replace('/_(flat_down|up)$/', '', $base);
}

// ── Download one file ─────────────────────────────────────────────────
if ($d !== '' && $r !== '' && $f !== '' && isset($_GET['dl'])) {
    $path = csvPath($archiveRoot, $d, $r, $f);
    if ($path === false || !is_file($path)) { http_response_code(404); die('File not found.'); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $d . '_' . $r . '_' . $f . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Download a whole run as ZIP ───────────────────────────────────────
if ($d !== '' && $r !== '' && isset($_GET['zip'])) {
    $dir = csvPath($archiveRoot, $d, $r);
    if ($dir === false || !is_dir($dir)) { http_response_code(404); die('Run not found.'); }
    if (!class_exists('ZipArchive')) { die('ZIP is not available on this server (PHP zip extension is off). Download files one by one instead.'); }
    $tmp = tempnam(sys_get_temp_dir(), 'tplzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach (listCsv($dir) as $n) { $zip->addFile($dir . '/' . $n, $n); }
    $zip->close();
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $d . '_' . $r . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ── Gather what to show ───────────────────────────────────────────────
$dashboards = array();   // for the overview cards
foreach (listDirs($archiveRoot, '/^[a-z0-9_]+$/') as $dn) {
    $runs = listDirs($archiveRoot . '/' . $dn, '/^\d{8}(_\d{6})?$/');
    rsort($runs);
    $dashboards[$dn] = $runs;
}

$runs = ($d !== '' && isset($dashboards[$d])) ? $dashboards[$d] : array();
if ($d !== '' && !isset($dashboards[$d])) { $d = ''; $r = ''; }
if ($r !== '' && !in_array($r, $runs, true)) { $r = ''; }

// Preview
$preview = null;
if ($d !== '' && $r !== '' && $f !== '' && isset($_GET['view'])) {
    $path = csvPath($archiveRoot, $d, $r, $f);
    if ($path !== false && is_file($path)) {
        $maxRows = 300;
        $preview = array('header' => array(), 'rows' => array(), 'total' => 0, 'size' => filesize($path));
        $fh = fopen($path, 'r');
        $first = true;
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($first) {
                if (isset($row[0])) { $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]); }
                $preview['header'] = $row;
                $first = false;
                continue;
            }
            if ($row === array(null)) { continue; }
            $preview['total']++;
            if (count($preview['rows']) < $maxRows) { $preview['rows'][] = $row; }
        }
        fclose($fh);
    }
}

// Files in the selected run
$files = array();
if ($d !== '' && $r !== '') {
    $dir = csvPath($archiveRoot, $d, $r);
    if ($dir !== false) {
        foreach (listCsv($dir) as $n) {
            if ($q !== '' && strpos(strtoupper(outletOf($n)), $q) === false) { continue; }
            $files[] = array('name' => $n, 'outlet' => outletOf($n), 'size' => filesize($dir . '/' . $n), 'mtime' => filemtime($dir . '/' . $n));
        }
    }
}

// Outlet search across ALL runs of a dashboard (evidence over time)
$history = array();
if ($d !== '' && $r === '' && $q !== '') {
    foreach ($runs as $rn) {
        $dir = csvPath($archiveRoot, $d, $rn);
        if ($dir === false) { continue; }
        foreach (listCsv($dir) as $n) {
            if (strtoupper(outletOf($n)) === $q || strpos(strtoupper(outletOf($n)), $q) !== false) {
                $history[] = array('run' => $rn, 'name' => $n, 'outlet' => outletOf($n), 'size' => filesize($dir . '/' . $n));
            }
        }
    }
}

function selfUrl($params) {
    $params = array_filter($params, function($v) { return $v !== '' && $v !== null; });
    return 'transferpicklist_csv_archive.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Tableau CSV Archive</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.arc-cards { display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:18px; }
.arc-card  { display:block; min-width:0; background:#fff; border:1px solid #ddd; border-radius:6px; padding:10px 12px; text-decoration:none; color:inherit; }
.arc-card:hover { border-color:#1976d2; background:#f5f9ff; }
.arc-card.active { border:2px solid #1976d2; background:#eef5ff; }
.arc-card h3 { margin:0 0 8px 0; font-size:11px; color:#555; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
.arc-card .big { font-size:22px; font-weight:bold; color:#1976d2; }
.arc-card .meta { font-size:10px; color:#888; margin-top:6px; }
@media (max-width: 900px) { .arc-cards { grid-template-columns:repeat(3, 1fr); } }
@media (max-width: 480px) { .arc-cards { grid-template-columns:repeat(2, 1fr); } }
.alpro-box p, .arc-note, .arc-crumb { text-align:left; }
/* Text columns left-aligned; columns marked .alpro-center (#, counts, sizes, times, buttons) stay centred */
.alpro-box table.myTable th,
.alpro-box table.myTable td { text-align:left; }
.alpro-box table.myTable th.alpro-center,
.alpro-box table.myTable td.alpro-center { text-align:center; }
/* Filter bar — same look as the Procurement List page (.prc-filter-bar) */
.arc-filter-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;}
.arc-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.arc-filter-bar input[type=text],.arc-filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.arc-filter-bar input[type=text]:focus,.arc-filter-bar select:focus{border-color:#1976d2;outline:none;}
.arc-filter-grow{flex:1 1 0;min-width:0;}
.arc-filter-fixed{flex:0 0 auto;white-space:nowrap;}
.arc-row-btn{padding:3px 10px;font-size:12px;}
.arc-crumb { font-size:13px; margin-bottom:12px; color:#555; }
.arc-crumb a { color:#1976d2; text-decoration:none; }
.arc-note { font-size:12px; color:#777; margin-bottom:12px; }
.arc-preview-wrap { overflow:auto; max-height:600px; border:1px solid #ddd; border-radius:4px; }
.arc-preview-wrap table { font-size:12px; }
.arc-preview-wrap th { position:sticky; top:0; z-index:1; }
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Tableau CSV Archive</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

    <div class="arc-note">
        Raw CSV exactly as Tableau sent it, saved by every sync — one folder per day, one file per outlet
        (Slow Moving: <b>_flat_down</b> and <b>_up</b> files per outlet). If an outlet is synced again the same day, the later file gets the sync time added, e.g. <b>QKC3M1_170042.csv</b> = synced at 5:00:42 PM. Use it as evidence of what Tableau showed at that time.
        <br><b>Folders older than 5 months are deleted automatically</b> — download anything you need to keep longer.
    </div>

    <?php if (empty($dashboards)): ?>
        <p>No CSV files saved yet. Files appear here after the next sync runs.</p>
    <?php else: ?>

    <!-- Dashboard cards -->
    <div class="arc-cards">
        <?php foreach ($dashLabels as $key => $label): $dRuns = isset($dashboards[$key]) ? $dashboards[$key] : array(); ?>
        <a class="arc-card <?php echo $d === $key ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(selfUrl(array('d' => $key))); ?>">
            <h3><?php echo htmlspecialchars($label); ?></h3>
            <div class="big"><?php echo count($dRuns); ?></div>
            <div class="meta">
                <?php echo count($dRuns) === 1 ? 'day' : 'days'; ?><br>
                <?php echo $dRuns ? 'Latest: ' . htmlspecialchars(runLabel($dRuns[0])) : 'No data yet'; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($d !== ''): ?>

    <div class="arc-crumb">
        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d))); ?>"><?php echo htmlspecialchars(isset($dashLabels[$d]) ? $dashLabels[$d] : $d); ?></a>
        <?php if ($r !== ''): ?>
            &rsaquo; <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $r))); ?>"><?php echo htmlspecialchars(runLabel($r)); ?></a>
        <?php endif; ?>
        <?php if ($preview !== null): ?>
            &rsaquo; <b><?php echo htmlspecialchars($f); ?></b>
        <?php endif; ?>
    </div>

    <!-- Run picker + outlet search -->
    <form method="get">
    <input type="hidden" name="d" value="<?php echo htmlspecialchars($d); ?>">
    <div class="arc-filter-bar">
        <div class="arc-filter-grow">
            <span class="arc-filter-label">Sync Date</span>
            <select name="r">
                <option value="">-- All Dates (search outlet) --</option>
                <?php foreach ($runs as $rn): ?>
                <option value="<?php echo htmlspecialchars($rn); ?>" <?php echo $r === $rn ? 'selected' : ''; ?>><?php echo htmlspecialchars(runLabel($rn)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="arc-filter-grow">
            <span class="arc-filter-label">Outlet Code</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="e.g. QKC3M1">
        </div>
        <div class="arc-filter-fixed">
            <span class="arc-filter-label">&nbsp;</span>
            <input type="submit" value="Search" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
            <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d))); ?>" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">Clear</a>
            <?php if ($r !== ''): ?>
            <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $r, 'zip' => 1))); ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">&#8659; Download Day (ZIP)</a>
            <?php endif; ?>
        </div>
    </div>
    </form>

    <?php if ($preview !== null): ?>
        <!-- Preview -->
        <p style="font-size:13px;">
            <b><?php echo htmlspecialchars($f); ?></b> — <?php echo number_format($preview['total']); ?> data row(s), <?php echo fmtSize($preview['size']); ?>
            <?php if ($preview['total'] > count($preview['rows'])): ?>
                (showing first <?php echo count($preview['rows']); ?> — download for the full file)
            <?php endif; ?>
            &nbsp;<a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $r, 'f' => $f, 'dl' => 1))); ?>" class="alpro-btn alpro-btn-blue arc-row-btn">Download CSV</a>
        </p>
        <?php if ($preview['total'] === 0): ?>
            <p style="color:#888;">Empty file — Tableau returned no data for this outlet on this date.</p>
        <?php else: ?>
        <div class="arc-preview-wrap">
            <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
                <thead><tr><th>#</th><?php foreach ($preview['header'] as $h): ?><th><?php echo htmlspecialchars($h); ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($preview['rows'] as $i => $row): ?>
                    <tr><td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td><?php foreach ($row as $c): ?><td><?php echo htmlspecialchars((string)$c); ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php elseif ($r !== ''): ?>
        <!-- Files in one run -->
        <p style="font-size:13px;"><?php echo count($files); ?> file(s)<?php echo $q !== '' ? ' matching <b>' . htmlspecialchars($q) . '</b>' : ''; ?> on this date.</p>
        <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
            <thead><tr><th width="40" class="alpro-center">#</th><th>Outlet</th><th>File</th><th class="alpro-center">Size</th><th class="alpro-center">Saved at</th><th class="alpro-center" width="190">Action</th></tr></thead>
            <tbody>
            <?php foreach ($files as $i => $fi): ?>
                <tr>
                    <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
                    <td class="alpro-mono" style="font-weight:bold;"><?php echo htmlspecialchars($fi['outlet']); ?></td>
                    <td class="alpro-mono"><?php echo htmlspecialchars($fi['name']); ?></td>
                    <td class="alpro-center"><?php echo $fi['size'] === 0 ? '<span style="color:#aaa;">empty</span>' : fmtSize($fi['size']); ?></td>
                    <td class="alpro-center"><?php echo date('d M Y, h:i A', $fi['mtime']); ?></td>
                    <td class="alpro-center">
                        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $r, 'f' => $fi['name'], 'view' => 1))); ?>" class="alpro-btn alpro-btn-grey arc-row-btn">Preview</a>
                        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $r, 'f' => $fi['name'], 'dl' => 1))); ?>" class="alpro-btn alpro-btn-blue arc-row-btn">Download</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($files)): ?>
                <tr><td colspan="6" class="alpro-center" style="color:#888;">No files<?php echo $q !== '' ? ' for that outlet' : ''; ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

    <?php elseif ($q !== ''): ?>
        <!-- One outlet across all runs -->
        <p style="font-size:13px;"><b><?php echo htmlspecialchars($q); ?></b> on every saved date of <?php echo htmlspecialchars(isset($dashLabels[$d]) ? $dashLabels[$d] : $d); ?> — compare how Tableau changed over time.</p>
        <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
            <thead><tr><th width="40" class="alpro-center">#</th><th>Sync date</th><th>File</th><th class="alpro-center">Size</th><th class="alpro-center" width="190">Action</th></tr></thead>
            <tbody>
            <?php foreach ($history as $i => $h): ?>
                <tr>
                    <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars(runLabel($h['run'])); ?></td>
                    <td class="alpro-mono"><?php echo htmlspecialchars($h['name']); ?></td>
                    <td class="alpro-center"><?php echo $h['size'] === 0 ? '<span style="color:#aaa;">empty</span>' : fmtSize($h['size']); ?></td>
                    <td class="alpro-center">
                        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $h['run'], 'f' => $h['name'], 'view' => 1))); ?>" class="alpro-btn alpro-btn-grey arc-row-btn">Preview</a>
                        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $h['run'], 'f' => $h['name'], 'dl' => 1))); ?>" class="alpro-btn alpro-btn-blue arc-row-btn">Download</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($history)): ?>
                <tr><td colspan="5" class="alpro-center" style="color:#888;">No saved files for that outlet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

    <?php else: ?>
        <!-- Runs list -->
        <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
            <thead><tr><th width="40" class="alpro-center">#</th><th>Sync date</th><th class="alpro-center">Files</th><th class="alpro-center">Total size</th><th class="alpro-center" width="260">Action</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $i => $rn):
                $dir = csvPath($archiveRoot, $d, $rn);
                $rf = $dir !== false ? listCsv($dir) : array();
                $sz = 0; foreach ($rf as $n) { $sz += filesize($dir . '/' . $n); }
            ?>
                <tr>
                    <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
                    <td><b><?php echo htmlspecialchars(runLabel($rn)); ?></b></td>
                    <td class="alpro-center"><?php echo count($rf); ?></td>
                    <td class="alpro-center"><?php echo fmtSize($sz); ?></td>
                    <td class="alpro-center">
                        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $rn))); ?>" class="alpro-btn alpro-btn-grey arc-row-btn">Open</a>
                        <a href="<?php echo htmlspecialchars(selfUrl(array('d' => $d, 'r' => $rn, 'zip' => 1))); ?>" class="alpro-btn alpro-btn-green arc-row-btn">Download ZIP</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($runs)): ?>
                <tr><td colspan="5" class="alpro-center" style="color:#888;">Nothing saved for this dashboard yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php else: ?>
        <p style="color:#777;">Click a dashboard above to see its saved dates.</p>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
