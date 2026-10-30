<?php
// ── Timing debug: add &debug=1 to the URL (admins only) to see how long each step takes ──
$_tplT0 = microtime(true); $_tplTimes = array(); $_tplLast = $_tplT0;
function tplT($label) { global $_tplTimes, $_tplLast; $now = microtime(true); $_tplTimes[] = array($label, $now - $_tplLast); $_tplLast = $now; }
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
require_once(__DIR__ . '/transferpicklist_tableau_links.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
mysqli_report(MYSQLI_REPORT_OFF);
// Super Admin, Super Admin (transferpicklist_autho = 1) or Procurement (department 18) — read-only page
$_tpl_ig_access = ((int)$transferpicklist_autho === 1) || ((int)$department === 18);
if (!$_tpl_ig_access) { redirect('/odb/permission.php'); }
tplT('Login check + database connect (outer app)');

// transferpicklist_itemgrade is very large (~13M rows on production), so every query
// here is written to use an index: outlet filter -> uniq_outlet_item(outlet_id, item_code),
// grade filter -> idx_grade_outlet(item_grade, ...), item code search -> idx_item_code
// (prefix match only — a "contains" LIKE '%x%' would scan the whole table).

$filterOutlet = isset($_GET['outlet']) ? trim($_GET['outlet']) : '';
$filterGrade  = isset($_GET['grade'])  ? trim($_GET['grade'])  : '';
$filterSearch = isset($_GET['search']) ? trim($_GET['search']) : ''; // item code (starts with) OR description
$perPage      = tplPerPage(50);
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$hasFilter = ($filterOutlet !== '' || $filterGrade !== '' || $filterSearch !== '');

// Build WHERE
$where = array('1=1');
if ($filterOutlet !== '') {
    $oRes = $conn->query("SELECT id FROM outlet WHERE code = '" . $conn->real_escape_string($filterOutlet) . "' AND recycle = 0 LIMIT 1");
    $oRow = $oRes ? $oRes->fetch_assoc() : null;
    $where[] = $oRow ? "i.outlet_id = " . (int)$oRow['id'] : "1=0";
}
if ($filterGrade !== '') {
    $where[] = "i.item_grade = '" . $conn->real_escape_string($filterGrade) . "'";
}
if ($filterSearch !== '') {
    $escS    = $conn->real_escape_string($filterSearch);
    $orParts = array("i.item_code LIKE '$escS%'");
    // Description matches: look up codes ONCE in simple, then filter by that literal list
    $dRes   = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$escS%'");
    $dCodes = array();
    while ($dRes && $d = $dRes->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
    if (!empty($dCodes)) { $orParts[] = "i.item_code IN (" . implode(',', $dCodes) . ")"; }
    $where[] = "(" . implode(' OR ', $orParts) . ")";
}
$whereSql = implode(' AND ', $where);

// URL builder
function igUrl($params) {
    $base = 'transferpicklist_itemgrade_list.php';
    return $params ? $base . '?' . http_build_query($params) : $base;
}
$currentParams = array();
if ($filterOutlet !== '') { $currentParams['outlet'] = $filterOutlet; }
if ($filterGrade  !== '') { $currentParams['grade']  = $filterGrade; }
if ($filterSearch !== '') { $currentParams['search'] = $filterSearch; }

// Export CSV — only with a filter (an unfiltered export would be ~13M rows)
$exportBlocked = false;
if (isset($_GET['export'])) {
    if (!$hasFilter) {
        $exportBlocked = true;
    } else {
        ob_end_clean();
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="itemgrade_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('#', 'Outlet Code', 'Outlet ACMM', 'Item Code', 'Item Description', 'Item Grade', 'Item Model', 'Last Sync'));
        $conn->real_query("
            SELECT o.code AS outlet_code, o.acmm_id, i.item_code, s.name AS item_name, i.item_grade, i.item_model, i.timestamp
            FROM transferpicklist_itemgrade i
            JOIN outlet o ON o.id = i.outlet_id
            LEFT JOIN simple s ON s.item_code = i.item_code
            WHERE $whereSql
            ORDER BY i.outlet_id, i.item_code
        ");
        $res = $conn->use_result();
        $n = 1;
        while ($res && $r = $res->fetch_assoc()) {
            $acmm = !empty($r['acmm_id']) ? "'" . str_pad((int)$r['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
            fputcsv($out, array($n++, $r['outlet_code'], $acmm, $r['item_code'], $r['item_name'], $r['item_grade'], $r['item_model'], $r['timestamp']));
            if ($n % 1000 === 0) { flush(); }
        }
        fclose($out);
        exit;
    }
}

// Count — exact when filtered (indexed); unfiltered = table estimate (exact COUNT(*) of
// ~13M rows takes seconds on every page load)
$countIsEstimate = false;
if ($hasFilter) {
    $cRes       = $conn->query("SELECT COUNT(*) AS cnt FROM transferpicklist_itemgrade i WHERE $whereSql");
    $totalItems = $cRes ? (int)$cRes->fetch_assoc()['cnt'] : 0;
} else {
    $cRes       = $conn->query("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transferpicklist_itemgrade'");
    $totalItems = $cRes ? (int)$cRes->fetch_assoc()['TABLE_ROWS'] : 0;
    $countIsEstimate = true;
}
$totalPages = max(1, (int)ceil($totalItems / $perPage));
// Without a filter only the first IG_MAX_UNFILTERED_PAGES pages can be opened — choose an
// Outlet / Grade / Search to see the rest.
define('IG_MAX_UNFILTERED_PAGES', 200);
$pagesCapped = false;
if (!$hasFilter && $totalPages > IG_MAX_UNFILTERED_PAGES) {
    $totalPages  = IG_MAX_UNFILTERED_PAGES;
    $pagesCapped = true;
}
tplT('Count records');
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Page rows, in two cheap steps:
//  a) the ids of this page, read from an index only — no JOIN (joining outlet here let the
//     optimizer sort all ~13M rows first) and no full-row reads while skipping the OFFSET;
//  b) the full rows for just those ids, plus outlet code/ACMM for them.
// No grade/search filter -> force uniq_outlet_item(outlet_id, item_code), which is already in
// display order. With a grade filter idx_grade_outlet(item_grade, outlet_id, item_code) is in
// order too; with a search the item_code index narrows the rows first.
$rows   = array();
$codes  = array();
$idxHint = ($filterGrade === '' && $filterSearch === '') ? 'FORCE INDEX (uniq_outlet_item)' : '';
$ids    = array();
$idRes  = $conn->query("
    SELECT i.id
    FROM transferpicklist_itemgrade i $idxHint
    WHERE $whereSql
    ORDER BY i.outlet_id, i.item_code
    LIMIT $perPage OFFSET $offset
");
while ($idRes && $r = $idRes->fetch_assoc()) { $ids[] = (int)$r['id']; }
if (!empty($ids)) {
    $res = $conn->query("
        SELECT i.outlet_id, i.item_code, i.item_grade, i.item_model, i.timestamp
        FROM transferpicklist_itemgrade i
        WHERE i.id IN (" . implode(',', $ids) . ")
        ORDER BY i.outlet_id, i.item_code
    ");
    $outletIds = array();
    while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; $codes[$r['item_code']] = true; $outletIds[(int)$r['outlet_id']] = true; }
    $outletInfo = array();
    $oRes = $conn->query("SELECT id, code, acmm_id FROM outlet WHERE id IN (" . implode(',', array_keys($outletIds)) . ")");
    while ($oRes && $o = $oRes->fetch_assoc()) { $outletInfo[(int)$o['id']] = $o; }
    foreach ($rows as $k => $r) {
        $o = isset($outletInfo[(int)$r['outlet_id']]) ? $outletInfo[(int)$r['outlet_id']] : null;
        $rows[$k]['outlet_code'] = $o ? $o['code'] : '';
        $rows[$k]['acmm_id']     = $o ? $o['acmm_id'] : '';
    }
}
tplT('Load 50 rows');

// Descriptions for the 50 rows only
$itemNames = array();
if (!empty($codes)) {
    $in = implode(',', array_map(function($c) use ($conn) { return "'" . $conn->real_escape_string($c) . "'"; }, array_keys($codes)));
    $sRes = $conn->query("SELECT item_code, name FROM simple WHERE item_code IN ($in)");
    while ($sRes && $s = $sRes->fetch_assoc()) { $itemNames[$s['item_code']] = $s['name']; }
}
tplT('Item descriptions for the 50 rows');

// Dropdowns: outlets from the small sync-status table; grades via the grade index
$outletList = array();
$olRes = $conn->query("SELECT outlet_code FROM transferpicklist_itemgrade_status ORDER BY outlet_code");
while ($olRes && $r = $olRes->fetch_assoc()) { $outletList[] = $r['outlet_code']; }
tplT('Outlet dropdown');
$gradeList = array();
$_prevGrade = '';
for ($_g = 0; $_g < 100; $_g++) {   // "next grade after the previous one" — one index lookup each
    $gRes = $conn->query("SELECT MIN(item_grade) AS g FROM transferpicklist_itemgrade WHERE item_grade > '" . $conn->real_escape_string($_prevGrade) . "'");
    $gRow = $gRes ? $gRes->fetch_assoc() : null;
    if (!$gRow || $gRow['g'] === null) { break; }
    $gradeList[] = $gRow['g'];
    $_prevGrade  = $gRow['g'];
}
tplT('Grade dropdown');

// Stats: synced outlets + data as of
$syncedOutlets = 0;
$syncDate      = '';
$stRes = $conn->query("SELECT SUM(status = 1) AS synced, MAX(CASE WHEN status = 1 THEN last_sync END) AS latest FROM transferpicklist_itemgrade_status");
if ($stRes && $st = $stRes->fetch_assoc()) { $syncedOutlets = (int)$st['synced']; $syncDate = $st['latest']; }
tplT('Stats (synced outlets / data as of)');
?>
<!DOCTYPE html>
<html>
<head>
<title>Item Grade Data</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Item Grade Data</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<!-- STATS -->
<div class="alpro-box">
    <div class="alpro-grid">
        <div class="alpro-field">
            <label class="alpro-muted">Synced Outlets</label>
            <div style="font-size:22px;font-weight:bold;"><?php echo number_format($syncedOutlets); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Total Records<?php echo $hasFilter ? ' (filtered)' : ''; ?></label>
            <div style="font-size:22px;font-weight:bold;color:#c62828;"><?php echo ($countIsEstimate ? '≈ ' : '') . number_format($totalItems); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Grades</label>
            <div style="font-size:22px;font-weight:bold;color:#f57c00;"><?php echo number_format(count($gradeList)); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Data As Of</label>
            <div style="font-size:16px;font-weight:bold;"><?php echo $syncDate ? date('d M Y', strtotime($syncDate)) : '—'; ?></div>
        </div>
    </div>
    <?php tplTableauLinks('itemgrade'); ?>
    <?php if ($exportBlocked): ?>
    <div class="alpro-alert alpro-error" style="margin-top:10px;">
        Please choose an <b>Outlet</b>, a <b>Grade</b> or enter a <b>Search</b> before exporting — the full Item Grade table is too large to download in one file.
    </div>
    <?php endif; ?>
</div>

<!-- FILTER + EXPORT -->
<style>
.prc-filter-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;}
.prc-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.prc-filter-bar input[type=text],.prc-filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.prc-filter-bar input[type=text]:focus,.prc-filter-bar select:focus{border-color:#1976d2;outline:none;}
.prc-filter-grow{flex:1 1 0;min-width:0;}
.prc-filter-fixed{flex:0 0 auto;white-space:nowrap;}
</style>
<form method="get" action="transferpicklist_itemgrade_list.php">
<div class="prc-filter-bar">
    <div class="prc-filter-grow">
        <span class="prc-filter-label">Search (Item Code / Description)</span>
        <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Item code (starts with) or description...">
    </div>
    <div style="flex:0 0 150px;">
        <span class="prc-filter-label">Outlet Code</span>
        <select name="outlet">
            <option value="">-- All --</option>
            <?php foreach ($outletList as $oc): ?>
            <option value="<?php echo htmlspecialchars($oc); ?>" <?php echo $filterOutlet === $oc ? 'selected' : ''; ?>><?php echo htmlspecialchars($oc); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="flex:0 0 130px;">
        <span class="prc-filter-label">Grade</span>
        <select name="grade">
            <option value="">-- All --</option>
            <?php foreach ($gradeList as $g): ?>
            <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $filterGrade === $g ? 'selected' : ''; ?>><?php echo htmlspecialchars($g); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="prc-filter-fixed">
        <span class="prc-filter-label">&nbsp;</span>
        <input type="submit" value="Search" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
        <a href="transferpicklist_itemgrade_list.php" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">Clear</a>
        <?php $expP = $currentParams; $expP['export'] = '1'; ?>
        <a href="<?php echo htmlspecialchars(igUrl($expP)); ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;"
           <?php echo $hasFilter ? '' : 'title="Choose an outlet, grade or search first"'; ?>>&#8659; Export CSV</a>
    </div>
</div>
</form>

<!-- TABLE -->
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th>Outlet Code</th>
        <th>Item Code</th>
        <th>Item Description</th>
        <th width="90">Item Grade</th>
        <th>Item Model</th>
        <th width="130">Last Sync</th>
    </tr>
    <?php if (empty($rows)): ?>
    <tr><td colspan="7" class="alpro-center alpro-muted">No records found.</td></tr>
    <?php endif; ?>
    <?php $rn = $offset + 1; foreach ($rows as $r):
        $acmm = !empty($r['acmm_id']) ? str_pad((int)$r['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
        $isNew = strtoupper((string)$r['item_grade']) === 'NEW';
    ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rn++; ?></td>
        <td class="alpro-mono" style="font-weight:bold;">
            <?php echo htmlspecialchars($r['outlet_code']); ?>
            <?php if ($acmm): ?><span class="alpro-muted" style="font-size:11px; font-weight:normal;"> (<?php echo $acmm; ?>)</span><?php endif; ?>
        </td>
        <td class="alpro-mono"><?php echo htmlspecialchars($r['item_code']); ?></td>
        <td><?php echo htmlspecialchars(isset($itemNames[$r['item_code']]) && $itemNames[$r['item_code']] !== '' ? $itemNames[$r['item_code']] : '—'); ?></td>
        <td class="alpro-center">
            <?php if ($isNew): ?>
            <span style="background:#c62828;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">NEW</span>
            <?php else: ?>
            <b><?php echo htmlspecialchars($r['item_grade'] !== null && $r['item_grade'] !== '' ? $r['item_grade'] : '—'); ?></b>
            <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($r['item_model'] ? $r['item_model'] : '—'); ?></td>
        <td class="alpro-center" style="font-size:12px;"><?php echo $r['timestamp'] ? htmlspecialchars(date('d M Y H:i', strtotime($r['timestamp']))) : '—'; ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<!-- PAGINATION -->
<?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage, $countIsEstimate); } // shared list footer (transferpicklist_pager.php) ?>
<?php if ($pagesCapped): ?>
<div class="alpro-muted" style="font-size:12px; text-align:right; margin-top:4px;">Only the first <?php echo IG_MAX_UNFILTERED_PAGES; ?> pages are shown without a filter — choose an Outlet, Grade or Search to see the rest.</div>
<?php endif; ?>

<?php
tplT('Build page HTML');
if (isset($_GET['debug']) && (int)$transferpicklist_autho === 1):
    $_tplTotal = microtime(true) - $_tplT0;
?>
<div class="alpro-box" style="margin-top:12px; font-size:12px;">
    <b>Timing debug</b> — total <b><?php echo number_format($_tplTotal, 2); ?>s</b> (server side)
    <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF" style="margin-top:6px;">
        <tr><th style="text-align:left;">Step</th><th class="alpro-center" width="110">Seconds</th><th class="alpro-center" width="90">% of total</th></tr>
        <?php foreach ($_tplTimes as $_tt): ?>
        <tr<?php echo $_tt[1] >= 1 ? ' style="background:#fff3e0;"' : ''; ?>>
            <td><?php echo htmlspecialchars($_tt[0]); ?></td>
            <td class="alpro-center"><b><?php echo number_format($_tt[1], 3); ?></b></td>
            <td class="alpro-center"><?php echo $_tplTotal > 0 ? round($_tt[1] / $_tplTotal * 100) : 0; ?>%</td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
