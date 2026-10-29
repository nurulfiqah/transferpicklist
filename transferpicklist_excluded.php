<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }
require_once('../minmax/minmax_campaign_sync.php');
require_once(__DIR__ . '/transferpicklist_exclusion_log.php');
tplExLogEnsure($conn);

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS transferpicklist_excluded (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) NOT NULL,
    outlet_code VARCHAR(20) NOT NULL,
    note VARCHAR(200) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pair (item_code, outlet_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

// Add missing columns if table was created with older schema
$chkNote = $conn->query("SHOW COLUMNS FROM transferpicklist_excluded LIKE 'note'");
if ($chkNote && $chkNote->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_excluded ADD COLUMN note VARCHAR(200) NOT NULL DEFAULT ''");
}
$chkAt = $conn->query("SHOW COLUMNS FROM transferpicklist_excluded LIKE 'created_at'");
if ($chkAt && $chkAt->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_excluded ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
}
// "Excluded At — by who" (see database/migrations/2026-10-29_excluded_created_by.sql)
$chkBy = $conn->query("SHOW COLUMNS FROM transferpicklist_excluded LIKE 'created_by'");
if ($chkBy && $chkBy->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_excluded ADD COLUMN created_by VARCHAR(100) NULL AFTER note");
}
$_exBy = (isset($nama_staff) && $nama_staff !== '') ? $nama_staff : '';

// ── Helper: parse .xlsx using ZipArchive (no external library needed) ──
function readXlsxRows($filePath) {
    if (!class_exists('ZipArchive')) { return array(); }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) { return array(); }

    // Load shared strings
    $sharedStrings = array();
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $t = '';
                if (isset($si->t)) { $t = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) { $t .= (string)$r->t; } } }
                $sharedStrings[] = $t;
            }
        }
    }

    // Load sheet1
    $rows = array();
    $shXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$shXml) { return array(); }

    $sh = simplexml_load_string($shXml);
    if (!$sh || !isset($sh->sheetData->row)) { return array(); }

    foreach ($sh->sheetData->row as $row) {
        $rowData = array();
        foreach ($row->c as $cell) {
            $type  = isset($cell['t']) ? (string)$cell['t'] : '';
            $val   = isset($cell->v)   ? (string)$cell->v   : '';
            if ($type === 's') { $val = isset($sharedStrings[(int)$val]) ? $sharedStrings[(int)$val] : ''; }
            $rowData[] = trim($val);
        }
        $rows[] = $rowData;
    }
    return $rows;
}

// ── Helper: insert pairs from parsed rows ──
function insertExcludedPairs($conn, $rows, $note, $skipHeader) {
    global $_exBy;
    $added = 0; $skip = 0; $err = 0; $addedPairs = array();
    $now   = date('Y-m-d H:i:s');
    $en    = $conn->real_escape_string($note);
    foreach ($rows as $i => $row) {
        if ($skipHeader && $i === 0) { continue; }
        if (count($row) < 2) { $err++; continue; }
        $ic = strtoupper(trim($row[0]));
        $oc = strtoupper(trim($row[1]));
        if ($ic === '' || $oc === '') { $err++; continue; }
        $ei = $conn->real_escape_string($ic);
        $eo = $conn->real_escape_string($oc);
        $eby = $conn->real_escape_string($_exBy);
        $conn->query("INSERT IGNORE INTO transferpicklist_excluded (item_code, outlet_code, note, created_by, created_at) VALUES ('$ei', '$eo', '$en', '$eby', '$now')");
        if ($conn->affected_rows > 0) {
            $added++;
            $addedPairs[] = $ic . ' × ' . $oc;
            mmSyncCampaignAdd($conn, $oc, $ic, MM_SYNC_TAG_PER_OUTLET);
        } else {
            $skip++;
        }
    }
    tplExLog($conn, 'per_outlet', 'insert', $addedPairs, $note);
    return array($added, $skip, $err);
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // (Single Entry / Bulk Paste removed — pairs are added only via Import from Excel/CSV.)
    if ($action === 'download_template') {
        ob_clean();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="excluded_pairs_template.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('Item Code', 'Outlet Code', 'Note'));
        fputcsv($out, array('100368', 'NSBAM1', 'Example'));
        fputcsv($out, array('100449', 'QKCBK1', ''));
        fclose($out);
        exit;

    } elseif ($action === 'import_file') {
        $note       = trim(isset($_POST['import_note']) ? $_POST['import_note'] : '');
        $skipHeader = isset($_POST['skip_header']) && $_POST['skip_header'] === '1';

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== 0) {
            $msg = "<span style='color:#c62828;'>No file uploaded or upload error.</span>";
        } else {
            $tmpPath = $_FILES['import_file']['tmp_name'];
            $origName = strtolower($_FILES['import_file']['name']);
            $ext = pathinfo($origName, PATHINFO_EXTENSION);

            if ($ext === 'xlsx') {
                $rows = readXlsxRows($tmpPath);
                list($added, $skip, $err) = insertExcludedPairs($conn, $rows, $note, $skipHeader);
                $msg = "<span style='color:green;'>Excel import: Added <b>$added</b> pair(s).</span>";
                if ($skip > 0) { $msg .= " <span style='color:#888;'>$skip already existed.</span>"; }
                if ($err  > 0) { $msg .= " <span style='color:#c62828;'>$err row(s) skipped (missing data).</span>"; }
            } elseif ($ext === 'csv') {
                $rows = array();
                $handle = fopen($tmpPath, 'r');
                // Strip BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }
                while (($row = fgetcsv($handle)) !== false) { $rows[] = $row; }
                fclose($handle);
                list($added, $skip, $err) = insertExcludedPairs($conn, $rows, $note, $skipHeader);
                $msg = "<span style='color:green;'>CSV import: Added <b>$added</b> pair(s).</span>";
                if ($skip > 0) { $msg .= " <span style='color:#888;'>$skip already existed.</span>"; }
                if ($err  > 0) { $msg .= " <span style='color:#c62828;'>$err row(s) skipped.</span>"; }
            } else {
                $msg = "<span style='color:#c62828;'>Unsupported file type. Please upload .xlsx or .csv only.</span>";
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0) {
            $pairRes = $conn->query("SELECT item_code, outlet_code FROM transferpicklist_excluded WHERE id=$id");
            $pair    = $pairRes ? $pairRes->fetch_assoc() : null;
            $conn->query("DELETE FROM transferpicklist_excluded WHERE id=$id");
            if ($pair) {
                mmSyncCampaignRemove($conn, $pair['outlet_code'], $pair['item_code']);
                tplExLog($conn, 'per_outlet', 'delete', array($pair['item_code'] . ' × ' . $pair['outlet_code']));
            }
            $msg = "<span style='color:green;'>Removed. This item+outlet pair will now be <b>included</b> in the next pick list generation.</span>";
        }

    // (Clear All removed — pairs are removed one by one with the bin.)
    }
}

// Load list with pagination
$rows    = array();
$search  = isset($_GET['q'])    ? trim($_GET['q'])    : '';
$perPage = tplPerPage(50);
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;
$where   = '';
if ($search !== '') {
    $es    = $conn->real_escape_string($search);
    $where = "WHERE item_code LIKE '%$es%' OR outlet_code LIKE '%$es%' OR note LIKE '%$es%'";
}
$totalItems = 0;
$cntRes = $conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_excluded $where");
if ($cntRes) { $totalItems = (int)$cntRes->fetch_assoc()['cnt']; }
$totalPages = max(1, ceil($totalItems / $perPage));

// ============================================================
// EXPORT XLS — respects the Search filter above, no LIMIT (every matching row)
// ============================================================
if (isset($_GET['export'])) {
    if (ob_get_level() > 0) { ob_end_clean(); }
    $exportRes = $conn->query("SELECT item_code, outlet_code, note, created_by, created_at FROM transferpicklist_excluded $where ORDER BY created_at DESC");
    $fname = 'transferpicklist_excluded_' . date('Ymd_His') . '.xls';
    $xls  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?mso-application progid=\"Excel.Sheet\"?>\n";
    $xls .= "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    $xls .= "<Worksheet ss:Name=\"Excluded Pairs\"><Table>\n<Row>";
    foreach (array('#', 'Item Code', 'Outlet Code', 'Note', 'Excluded At', 'Excluded By') as $c) {
        $xls .= "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($c) . "</Data></Cell>";
    }
    $xls .= "</Row>\n";
    $i = 1;
    if ($exportRes) {
        while ($er = $exportRes->fetch_assoc()) {
            $xls .= "<Row>"
                . "<Cell><Data ss:Type=\"Number\">" . $i++ . "</Data></Cell>"
                . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['item_code']) . "</Data></Cell>"
                . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['outlet_code']) . "</Data></Cell>"
                . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['note']) . "</Data></Cell>"
                . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['created_at']) . "</Data></Cell>"
                . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars((string)$er['created_by']) . "</Data></Cell>"
                . "</Row>\n";
        }
    }
    $xls .= "</Table></Worksheet></Workbook>";
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($xls));
    echo $xls;
    exit;
}

$res = $conn->query("SELECT id, item_code, outlet_code, note, created_by, created_at FROM transferpicklist_excluded $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }

// Item descriptions for the rows on this page (one lookup)
$exItemNames = array();
if (!empty($rows)) {
    $exIn = implode(',', array_map(function($r) use ($conn) { return "'" . $conn->real_escape_string($r['item_code']) . "'"; }, $rows));
    $nRes = $conn->query("SELECT item_code, name FROM simple WHERE item_code IN ($exIn)");
    while ($nRes && $n = $nRes->fetch_assoc()) { $exItemNames[$n['item_code']] = $n['name']; }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Excluded Pairs – Pick List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Excluded Item+Outlet Pairs – Pick List Generation</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

<p style="font-size:13px; color:#555; margin-bottom:14px;">
    Pairs listed here are <b>skipped during pick list generation</b> — the item code will not be transferred <b>from that specific outlet only</b>. Other outlets can still send the same item.<br>
    To block an item code from <b>all outlets</b>, use <a href="transferpicklist_excluded_items.php">Excluded Items (Global)</a>.
</p>

<?php if ($msg): ?>
<div style="padding:8px 12px; background:#f5f5f5; border-left:3px solid #1976d2; margin-bottom:14px; font-size:13px;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Import Excel/CSV (the only way to add exclusion pairs) — same layout as Excluded Items (Global) -->
<fieldset style="margin-bottom:16px;">
    <legend style="font-weight:bold; font-size:13px;">&#8679; Import from Excel (.xlsx) or CSV</legend>
    <p style="font-size:12px; color:#555; margin:0 0 10px;">
        File columns: <b>A = Item Code</b>, <b>B = Outlet Code</b>. Extra columns are ignored.
        Excel: save as <b>.xlsx</b> &nbsp;|&nbsp; CSV: comma-separated, UTF-8. Imported items are immediately removed from the sync tables.
    </p>
    <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <form method="post" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; flex:1 1 auto; margin:0;">
            <input type="hidden" name="action" value="import_file">
            <div style="flex:0 0 280px;">
                <label style="font-size:11px; color:#888;">File (.xlsx or .csv)</label><br>
                <input type="file" name="import_file" accept=".xlsx,.csv" class="alpro-input" style="width:100%;">
            </div>
            <div style="flex:1; min-width:180px;">
                <label style="font-size:11px; color:#888;">Note for all (optional)</label><br>
                <input type="text" name="import_note" class="alpro-input" style="width:100%;" placeholder="e.g. July exclusion list">
            </div>
            <div style="flex:0 0 auto;">
                <label style="font-size:12px; display:flex; align-items:center; gap:4px; height:32px; white-space:nowrap;">
                    <input type="checkbox" name="skip_header" value="1" checked> Skip header row
                </label>
            </div>
            <div style="flex:0 0 auto;">
                <button type="submit" class="alpro-btn alpro-btn-blue">Import</button>
            </div>
        </form>
        <form method="post" style="margin:0; flex:0 0 auto;">
            <input type="hidden" name="action" value="download_template">
            <button type="submit" class="alpro-btn alpro-btn-grey">&#8659; Download Template</button>
        </form>
    </div>
</fieldset>

<!-- Search -->
<form method="get" style="display:flex; gap:8px; margin-bottom:10px; align-items:flex-end;">
    <div style="flex:1;">
        <label style="font-size:11px; color:#888;">Search</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="alpro-input" style="width:100%;" placeholder="Item code, outlet code or note...">
    </div>
    <div>
        <button type="submit" class="alpro-btn alpro-btn-blue">Search</button>
        <a href="transferpicklist_excluded.php" class="alpro-btn alpro-btn-grey">Reset</a>
        <a href="transferpicklist_excluded.php?q=<?php echo urlencode($search); ?>&export=1" class="alpro-btn alpro-btn-grey">&#8595; Export to Excel</a>
    </div>
</form>

<!-- Table -->
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th width="120">Outlet Code</th>
        <th width="130">Item Code</th>
        <th>Item Description</th>
        <th width="200">Excluded At</th>
        <th width="60" class="alpro-center">Action</th>
    </tr>
    <?php if (empty($rows)): ?>
    <tr><td colspan="6" class="alpro-center alpro-muted">No exclusion pairs added.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r):
        $exDesc = isset($exItemNames[$r['item_code']]) && $exItemNames[$r['item_code']] !== '' ? $exItemNames[$r['item_code']] : '';
    ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $offset + $i + 1; ?></td>
        <td><b style="font-family:monospace; color:#1976d2;"><?php echo htmlspecialchars($r['outlet_code']); ?></b></td>
        <td><b style="font-family:monospace;"><?php echo htmlspecialchars($r['item_code']); ?></b></td>
        <td>
            <?php echo htmlspecialchars($exDesc !== '' ? $exDesc : '—'); ?>
            <?php if (!empty($r['note'])): ?>
            <div style="font-size:11px; color:#888;">Note: <?php echo htmlspecialchars($r['note']); ?></div>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;">
            <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($r['created_at']))); ?>
            <div style="font-size:11px; color:#888;"><?php echo !empty($r['created_by']) ? 'by ' . htmlspecialchars($r['created_by']) : '&nbsp;'; ?></div>
        </td>
        <td class="alpro-center">
            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this exclusion?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" title="Remove" style="background:none; border:none; cursor:pointer; padding:0; font-size:18px; line-height:1; opacity:0.75;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.75">&#128465;</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } // shared list footer (transferpicklist_pager.php) ?>


</div>
<?php tplExLogRender($conn, 'per_outlet'); ?>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
