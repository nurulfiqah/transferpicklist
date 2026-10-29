<?php
// ── Timing debug: add &debug=1 to the URL (admins only) to see how long each step takes ──
$_tplT0 = microtime(true); $_tplTimes = array(); $_tplLast = $_tplT0;
function tplT($label) { global $_tplTimes, $_tplLast; $now = microtime(true); $_tplTimes[] = array($label, $now - $_tplLast); $_tplLast = $now; }
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
ini_set('memory_limit', '512M');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
// PHP 8.1+ makes mysqli throw on query errors by default; restore the
// legacy return-false-on-error behavior so a query problem shows up as a
// normal page issue instead of an uncaught fatal error crashing the export.
mysqli_report(MYSQLI_REPORT_OFF);
tplT('Login check + database connect (outer app)');
$_tpl_proc_access = ((int)$transferpicklist_autho >= 1) || ((int)$department === 18);
if (!$_tpl_proc_access) { redirect('/odb/permission.php'); }

$listType       = isset($_GET['list']) && $_GET['list'] === 'slowmoving' ? 'slowmoving' : 'nonmoving';
$filterSearch   = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$filterOutlet   = isset($_GET['outlet']) ? trim($_GET['outlet']) : '';
$filterItem     = '';
$filterTrend    = isset($_GET['trend'])     ? trim($_GET['trend'])     : '';
$filterType     = isset($_GET['type'])      ? trim($_GET['type'])      : '';
$filterPriority = isset($_GET['priority'])  ? trim($_GET['priority'])  : '';
$baseUrl        = 'transferpicklist_procurement.php?list=' . $listType;

// Item descriptions + model, loaded ONLY for the item codes actually needed
// (current page / export set) — loading the whole `simple` table on every page
// open was a major cause of slow page loads.
$item_arr  = array(); // item_code => name
$model_arr = array(); // item_code => model
function tplLoadSimple($conn, $inSql, &$item_arr, &$model_arr) {
    $res = $conn->query("SELECT item_code, name, model FROM simple WHERE item_code IN ($inSql)");
    if ($res) { while ($r = $res->fetch_assoc()) { $item_arr[$r['item_code']] = $r['name']; $model_arr[$r['item_code']] = $r['model']; } }
}
// HQ / Non-HQ type filter as a subquery instead of a giant IN (...) list built in PHP
function tplTypeFilterSql($col, $filterType) {
    return " AND $col IN (SELECT item_code FROM simple WHERE " . ($filterType === 'non_hq' ? "model = 'Store/HQ Order'" : "model != 'Store/HQ Order'") . ")";
}

// Nonmoving UNION — regular + ethical (same pattern as slowmoving). Defined
// here (before the export block below) because the nonmoving CSV export
// needs it too — it was previously only defined further down the file,
// after the export block, so exporting nonmoving crashed with "Table
// 'odb.n' doesn't exist" (the undefined variable interpolated as empty).
$_nmEthicalExists = $conn->query("SHOW TABLES LIKE 'transferpicklist_nonmoving_ethical'")->num_rows > 0;
$nmUnion = $_nmEthicalExists
    ? "(SELECT outlet_id, item_code, closing_stock, avg_monthly, timestamp, 'regular' AS category FROM transferpicklist_nonmoving UNION ALL SELECT outlet_id, item_code, closing_stock, avg_monthly, timestamp, 'ethical' AS category FROM transferpicklist_nonmoving_ethical)"
    : "(SELECT outlet_id, item_code, closing_stock, avg_monthly, timestamp, 'regular' AS category FROM transferpicklist_nonmoving)";
// Nonmoving avg_monthly = Tableau's "Average Past 6 Months Quantity Sold",
// stored as-is by hyper/nonmoving_sync.py / nonmoving_ethical_sync.py — NOT
// recomputed from transferpicklist_past3months.

// ============================================================
// EXPORT CSV
// ============================================================
if (isset($_GET['export'])) {
    ob_end_clean();
    ini_set('memory_limit', '512M');

    if ($listType === 'slowmoving') {
        set_time_limit(300);

        $where = "1=1"; // NEW-grade items are removed at sync time (see $_newExclude note below)
        if ($filterSearch !== '') {
            $esc      = $conn->real_escape_string($filterSearch);
            $searchOr = array("o.code LIKE '%$esc%'", "s.item_code LIKE '%$esc%'");
            $dCodes   = array();
            $dRes2 = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
            while ($d = $dRes2->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
            if (!empty($dCodes)) { $searchOr[] = "s.item_code IN (" . implode(',', $dCodes) . ")"; }
            $where .= " AND (" . implode(' OR ', $searchOr) . ")";
        }
        if ($filterTrend !== '') { $where .= " AND s.trend_type = '" . $conn->real_escape_string($filterTrend) . "'"; }
        if ($filterType !== '') { $where .= tplTypeFilterSql('s.item_code', $filterType); }
        if ($filterPriority !== '') {
            $oc = strtoupper($filterPriority);
            if ($oc === 'SABAH')       { $where .= " AND o.code LIKE 'SKK%'"; }
            elseif ($oc === 'SARAWAK')    { $where .= " AND o.code LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
            elseif ($oc === 'PENINSULAR') { $where .= " AND o.code NOT LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
        }

        $exportFilterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
        if ($exportFilterCategory !== '') { $where .= " AND s.category = '" . $conn->real_escape_string($exportFilterCategory) . "'"; }

        $exportUnion = "(SELECT outlet_id, item_code, trend_type, last_month_closing, avg_monthly, moi, adjusted_overstock_qty, timestamp, 'regular' AS category FROM transferpicklist_slowmoving UNION ALL SELECT outlet_id, item_code, trend_type, last_month_closing, avg_monthly, moi, adjusted_overstock_qty, timestamp, 'ethical' AS category FROM transferpicklist_slowmoving_ethical)";

        // Models only for items that are actually in the slow moving lists
        tplLoadSimple($conn, "SELECT item_code FROM transferpicklist_slowmoving UNION SELECT item_code FROM transferpicklist_slowmoving_ethical", $item_arr, $model_arr);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="slowmoving_' . date('Ymd') . '.csv"');
        if (ob_get_level()) ob_end_clean();
        $out = fopen('php://output', 'w');
        fputcsv($out, array('#', 'Outlet Short Code', 'Outlet Code', 'Outlet Name', 'Item Code', 'Category', 'Type', 'Trend', 'Last Month Closing Stock Qty', 'Avg Past 3M Qty Sold', 'Avg Past 3M MOI', 'Adjusted Overstock Qty', 'Last Sync'));

        $conn->real_query("
            SELECT o.code as outlet_code, o.acmm_id, o.comp_name as outlet_name,
                   s.item_code, s.trend_type, s.timestamp, s.category,
                   s.last_month_closing, s.avg_monthly, s.moi, s.adjusted_overstock_qty
            FROM $exportUnion s
            JOIN outlet o ON o.id = s.outlet_id
            WHERE $where
            ORDER BY s.avg_monthly DESC, s.outlet_id, s.item_code
        ");
        $res = $conn->use_result();
        $rowNum = 1;
        while ($row = $res->fetch_assoc()) {
            $acmm      = !empty($row['acmm_id']) ? "'" . str_pad((int)$row['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
            $itemModel = isset($model_arr[$row['item_code']]) ? $model_arr[$row['item_code']] : '';
            $typeLabel = ($itemModel === 'Store/HQ Order') ? 'Non-HQ' : 'HQ';
            fputcsv($out, array(
                $rowNum++,
                $row['outlet_code'], $acmm, $row['outlet_name'],
                $row['item_code'],
                isset($row['category']) ? ucfirst($row['category']) : 'Regular',
                $typeLabel,
                $row['trend_type'],
                number_format((float)$row['last_month_closing'], 0, '.', ''),
                number_format((float)$row['avg_monthly'], 2, '.', ''),
                number_format((float)$row['moi'], 2, '.', ''),
                number_format((float)$row['adjusted_overstock_qty'], 0, '.', ''),
                $row['timestamp']
            ));
            // Flush every 500 rows so browser receives data progressively
            if ($rowNum % 500 === 0) { flush(); }
        }
        fclose($out);

    } else {
        $where = "1=1"; // NEW-grade items are removed at sync time (see $_newExclude note below)
        if ($filterSearch !== '') {
            $esc      = $conn->real_escape_string($filterSearch);
            $searchOr = array("o.code LIKE '%$esc%'", "n.item_code LIKE '%$esc%'");
            $dCodes   = array();
            $dRes2 = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
            while ($d = $dRes2->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
            if (!empty($dCodes)) { $searchOr[] = "n.item_code IN (" . implode(',', $dCodes) . ")"; }
            $where .= " AND (" . implode(' OR ', $searchOr) . ")";
        }
        if ($filterType !== '') { $where .= tplTypeFilterSql('n.item_code', $filterType); }
        if ($filterPriority !== '') {
            $oc = strtoupper($filterPriority);
            if ($oc === 'SABAH')       { $where .= " AND o.code LIKE 'SKK%'"; }
            elseif ($oc === 'SARAWAK')    { $where .= " AND o.code LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
            elseif ($oc === 'PENINSULAR') { $where .= " AND o.code NOT LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
        }

        // Descriptions/models only for items that are actually in the non moving lists
        tplLoadSimple($conn, "SELECT item_code FROM transferpicklist_nonmoving"
            . ($_nmEthicalExists ? " UNION SELECT item_code FROM transferpicklist_nonmoving_ethical" : ""), $item_arr, $model_arr);
        $exportDesc  = $item_arr;
        $exportModel = $model_arr;

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="nonmoving_reorder_zero_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('#', 'Outlet Short Code', 'Outlet Code', 'Outlet Name', 'Item Code', 'Item Description', 'Category', 'Type', 'Priority', 'Closing Stock (Qty)', 'Avg Past 6M Qty Sold', 'MOI', 'Last Sync'));

        // Stream directly — no $allRows array
        $res = $conn->query("
            SELECT o.code as outlet_code, o.acmm_id, o.comp_name as outlet_name,
                   n.item_code, n.closing_stock, n.timestamp, n.category,
                   n.avg_monthly
            FROM $nmUnion n
            JOIN outlet o ON o.id = n.outlet_id
            WHERE $where
            ORDER BY n.avg_monthly DESC, n.outlet_id, n.item_code
        ");
        $rowNum = 1;
        while ($row = $res->fetch_assoc()) {
            $desc        = isset($exportDesc[$row['item_code']]) ? $exportDesc[$row['item_code']] : '';
            $typeLabel   = isset($exportModel[$row['item_code']]) && $exportModel[$row['item_code']] === 'Store/HQ Order' ? 'Non-HQ' : 'HQ';
            $acmm        = !empty($row['acmm_id']) ? "'" . str_pad((int)$row['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
            $oc2         = strtoupper($row['outlet_code']);
            $priority    = strpos($oc2, 'SKK') === 0 ? 'Sabah' : (strpos($oc2, 'Q') === 0 ? 'Sarawak' : 'Peninsular');
            $categoryLabel = isset($row['category']) && $row['category'] === 'ethical' ? 'Ethical' : 'Regular';
            $avgMonthly  = isset($row['avg_monthly']) && $row['avg_monthly'] !== null ? number_format((float)$row['avg_monthly'], 2, '.', '') : '0.00';
            $monthsStock = (float)$avgMonthly > 0 ? number_format((float)$row['closing_stock'] / (float)$avgMonthly, 2) : '0.00';
            fputcsv($out, array($rowNum++, $row['outlet_code'], $acmm, $row['outlet_name'], $row['item_code'], $desc, $categoryLabel, $typeLabel, $priority, $row['closing_stock'], $avgMonthly, $monthsStock, $row['timestamp']));
        }
        fclose($out);
    }
    exit;
}

// ============================================================
// QUERY — WHERE clause
// ============================================================
$perPage = 50;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// NEW-grade items are NOT re-checked here. They are removed from the non/slow moving
// tables at sync time — by nonmoving(_ethical)_sync.py / slowmoving(_ethical)_sync.py
// when they save an outlet, and by itemgrade_sync.py (remove_new_from_lists) when
// Item Grade is re-synced later. Re-checking every row against ~13M itemgrade rows on
// each page load took ~60s on production for the same result.
$_newExclude   = "1=1";
$_newExcludeNM = "1=1";

// ($nmUnion already defined earlier, above the EXPORT CSV block, so the
// nonmoving export path can use it too.)

if ($listType === 'slowmoving') {

    $filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';

    // Combined UNION of regular + ethical slow moving
    $smUnion = "(
        SELECT outlet_id, item_code, trend_type, last_month_closing, avg_monthly, moi, adjusted_overstock_qty, timestamp, 'regular' AS category
        FROM transferpicklist_slowmoving
        UNION ALL
        SELECT outlet_id, item_code, trend_type, last_month_closing, avg_monthly, moi, adjusted_overstock_qty, timestamp, 'ethical' AS category
        FROM transferpicklist_slowmoving_ethical
    )";

    $where = $_newExclude;
    if ($filterSearch !== '') {
        $esc      = $conn->real_escape_string($filterSearch);
        $searchOr = array("o.code LIKE '%$esc%'", "s.item_code LIKE '%$esc%'");
        $dCodes   = array();
        $dRes = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
        while ($d = $dRes->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
        if (!empty($dCodes)) { $searchOr[] = "s.item_code IN (" . implode(',', $dCodes) . ")"; }
        $where .= " AND (" . implode(' OR ', $searchOr) . ")";
    }
    if ($filterTrend    !== '') { $where .= " AND s.trend_type = '" . $conn->real_escape_string($filterTrend) . "'"; }
    if ($filterCategory !== '') { $where .= " AND s.category = '" . $conn->real_escape_string($filterCategory) . "'"; }
    if ($filterType !== '') { $where .= tplTypeFilterSql('s.item_code', $filterType); }
    if ($filterOutlet !== '') { $where .= " AND o.code = '" . $conn->real_escape_string($filterOutlet) . "'"; }
    if ($filterPriority !== '') {
        $oc = strtoupper($filterPriority);
        if ($oc === 'SABAH')          { $where .= " AND o.code LIKE 'SKK%'"; }
        elseif ($oc === 'SARAWAK')    { $where .= " AND o.code LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
        elseif ($oc === 'PENINSULAR') { $where .= " AND o.code NOT LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
    }

    $totalItems = (int)$conn->query("SELECT COUNT(*) as cnt FROM $smUnion s JOIN outlet o ON o.id = s.outlet_id WHERE $where")->fetch_assoc()['cnt'];
    tplT('Slow Moving: count rows');
    $totalPages = max(1, ceil($totalItems / $perPage));

    $items_res = $conn->query("
        SELECT s.outlet_id, o.code as outlet_code, o.acmm_id, s.item_code, s.trend_type, s.category,
               s.last_month_closing, s.avg_monthly, s.moi, s.adjusted_overstock_qty
        FROM $smUnion s
        JOIN outlet o ON o.id = s.outlet_id
        WHERE $where
        ORDER BY s.avg_monthly DESC, s.outlet_id, s.item_code
        LIMIT $perPage OFFSET $offset
    ");
    $pageRows = array();
    $_codes   = array();
    while ($r = $items_res->fetch_assoc()) { $pageRows[] = $r; $_codes[$r['item_code']] = true; }
    tplT('Slow Moving: load 50 rows');
    $p3m_arr  = array();

    $statsRes = $conn->query("SELECT COUNT(DISTINCT outlet_id) as total_outlets, COUNT(*) as total_items, COUNT(DISTINCT item_code) as unique_items FROM $smUnion s");
    $stats    = $statsRes->fetch_assoc();
    tplT('Stats box (outlets / items / unique)');
    $syncRes  = $conn->query("SELECT GREATEST(COALESCE((SELECT MAX(last_sync) FROM transferpicklist_slowmoving_status WHERE status=1), '1970-01-01'), COALESCE((SELECT MAX(last_sync) FROM transferpicklist_slowmoving_ethical_status WHERE status=1), '1970-01-01')) as latest");
    $syncDate = ($r = $syncRes->fetch_assoc()) ? $r['latest'] : '';
    tplT('Last sync date');

} else {

    $filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';

    $where = $_newExcludeNM;
    if ($filterSearch !== '') {
        $esc      = $conn->real_escape_string($filterSearch);
        $searchOr = array("o.code LIKE '%$esc%'", "n.item_code LIKE '%$esc%'");
        $dCodes   = array();
        $dRes = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
        while ($d = $dRes->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
        if (!empty($dCodes)) { $searchOr[] = "n.item_code IN (" . implode(',', $dCodes) . ")"; }
        $where .= " AND (" . implode(' OR ', $searchOr) . ")";
    }
    if ($filterType !== '') { $where .= tplTypeFilterSql('n.item_code', $filterType); }
    if ($filterOutlet   !== '') { $where .= " AND o.code = '" . $conn->real_escape_string($filterOutlet) . "'"; }
    if ($filterCategory !== '') { $where .= " AND n.category = '" . $conn->real_escape_string($filterCategory) . "'"; }
    if ($filterPriority !== '') {
        $oc = strtoupper($filterPriority);
        if ($oc === 'SABAH')          { $where .= " AND o.code LIKE 'SKK%'"; }
        elseif ($oc === 'SARAWAK')    { $where .= " AND o.code LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
        elseif ($oc === 'PENINSULAR') { $where .= " AND o.code NOT LIKE 'Q%' AND o.code NOT LIKE 'SKK%'"; }
    }

    $totalItems = (int)$conn->query("SELECT COUNT(*) as cnt FROM $nmUnion n JOIN outlet o ON o.id = n.outlet_id WHERE $where")->fetch_assoc()['cnt'];
    tplT('Non Moving: count rows');
    $totalPages = max(1, ceil($totalItems / $perPage));

    $items_res = $conn->query("
        SELECT n.outlet_id, o.code as outlet_code, o.acmm_id, n.item_code, n.closing_stock, n.avg_monthly, n.category
        FROM $nmUnion n
        JOIN outlet o ON o.id = n.outlet_id
        WHERE $where
        ORDER BY n.outlet_id, n.item_code
        LIMIT $perPage OFFSET $offset
    ");
    $pageRows  = array();
    $_codes    = array();
    while ($r = $items_res->fetch_assoc()) {
        $pageRows[] = $r;
        $_codes[$r['item_code']] = true;
    }
    tplT('Non Moving: load 50 rows');
    $p3m_arr = array();

    $statsRes = $conn->query("SELECT COUNT(DISTINCT outlet_id) as total_outlets, COUNT(*) as total_items, COUNT(DISTINCT item_code) as unique_items FROM $nmUnion n");
    $stats    = $statsRes->fetch_assoc();
    tplT('Stats box (outlets / items / unique)');
    $syncRes  = $conn->query("SELECT GREATEST(COALESCE((SELECT MAX(last_sync) FROM transferpicklist_nonmoving_status WHERE status=1), '1970-01-01'), COALESCE((SELECT MAX(last_sync) FROM transferpicklist_nonmoving_ethical_status WHERE status=1), '1970-01-01')) as latest");
    $syncDate = ($r = $syncRes->fetch_assoc()) ? $r['latest'] : '';
    tplT('Last sync date');
}

// Item descriptions + models for the rows on THIS page only (max $perPage codes)
if (!empty($_codes)) {
    tplLoadSimple($conn, implode(',', array_map(function($c) use ($conn) { return "'" . $conn->real_escape_string($c) . "'"; }, array_keys($_codes))), $item_arr, $model_arr);
}
tplT('Item names for the 50 rows');

$pgBase = $baseUrl
    . ($filterSearch   !== '' ? '&search='   . urlencode($filterSearch)   : '')
    . ($filterOutlet   !== '' ? '&outlet='   . urlencode($filterOutlet)   : '')
    . ($filterType     !== '' ? '&type='     . urlencode($filterType)     : '')
    . ($filterPriority !== '' ? '&priority=' . urlencode($filterPriority) : '')
    . ($filterTrend    !== '' ? '&trend='    . urlencode($filterTrend)    : '')
    . '&';
?>
<!DOCTYPE html>
<html>
<head>
<title>Procurement List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Procurement List</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>


<!-- STATS -->
<div class="alpro-box">
    <div class="alpro-grid">
        <div class="alpro-field">
            <label class="alpro-muted">Total Outlets</label>
            <div style="font-size:22px;font-weight:bold;"><?php echo (int)$stats['total_outlets']; ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Total Records</label>
            <div style="font-size:22px;font-weight:bold;color:#c62828;"><?php echo number_format((int)$stats['total_items']); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Unique Items</label>
            <div style="font-size:22px;font-weight:bold;color:#f57c00;"><?php echo number_format((int)$stats['unique_items']); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Data As Of</label>
            <div style="font-size:16px;font-weight:bold;"><?php echo $syncDate ? date('d M Y', strtotime($syncDate)) : '—'; ?></div>
        </div>
    </div>
    <div class="alpro-alert alpro-warn" style="margin-top:10px;">
        <?php if ($listType === 'slowmoving'): ?>
            This list shows <b>slow-moving items</b> per outlet (flat or downward sales trend). Procurement should review reorder points for these items.
        <?php else: ?>
            This list shows <b>6-month non-moving items</b> per outlet. Procurement should set reorder point to <b>0</b> for these items so logistics will not replenish them.
            <br><br>
            <b>Avg Past 6M Qty Sold</b> = taken as-is from Tableau (Outlet 6M Non Moving Stock Qty) &nbsp;|&nbsp;
            <b>MOI</b> = Closing Stock ÷ Avg Past 6M Qty Sold &nbsp;|&nbsp;
            <b>MOI 0.00</b> = item had <b>zero sales</b> in the past 6 months — these are the most critical items and should be prioritised for reorder point reset to 0.
        <?php endif; ?>
    </div>
</div>

<!-- FILTER + EXPORT -->
<?php
if ($listType === 'slowmoving') {
    $_outletRes = $conn->query("SELECT DISTINCT o.code FROM (SELECT outlet_id FROM transferpicklist_slowmoving UNION SELECT outlet_id FROM transferpicklist_slowmoving_ethical) t JOIN outlet o ON o.id = t.outlet_id ORDER BY o.code");
} else {
    $_outletRes = $conn->query("SELECT DISTINCT o.code FROM transferpicklist_nonmoving t JOIN outlet o ON o.id = t.outlet_id ORDER BY o.code");
}
$_outletList  = array();
while ($_or = $_outletRes->fetch_assoc()) { $_outletList[] = $_or['code']; }
tplT('Outlet dropdown list');
?>
<style>
.prc-filter-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;}
.prc-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.prc-filter-bar input[type=text],.prc-filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.prc-filter-bar input[type=text]:focus,.prc-filter-bar select:focus{border-color:#1976d2;outline:none;}
.prc-filter-grow{flex:1 1 0;min-width:0;}
.prc-filter-fixed{flex:0 0 auto;white-space:nowrap;}
</style>
<form method="get" action="transferpicklist_procurement.php">
<input type="hidden" name="list" value="<?php echo $listType; ?>">
<div class="prc-filter-bar">
    <div class="prc-filter-grow">
        <span class="prc-filter-label">Search (Outlet Code / Item Code / Description)</span>
        <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Outlet code, item code or description...">
    </div>
    <div style="flex:0 0 130px;">
        <span class="prc-filter-label">Outlet Code</span>
        <select name="outlet">
            <option value="">-- All --</option>
            <?php foreach ($_outletList as $_oc): ?>
            <option value="<?php echo htmlspecialchars($_oc); ?>" <?php echo $filterOutlet === $_oc ? 'selected' : ''; ?>><?php echo htmlspecialchars($_oc); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="flex:0 0 110px;">
        <span class="prc-filter-label">Type</span>
        <select name="type">
            <option value="">-- All --</option>
            <option value="hq"     <?php echo $filterType === 'hq'     ? 'selected' : ''; ?>>HQ</option>
            <option value="non_hq" <?php echo $filterType === 'non_hq' ? 'selected' : ''; ?>>Non-HQ</option>
        </select>
    </div>
    <div style="flex:0 0 130px;">
        <span class="prc-filter-label">Priority</span>
        <select name="priority">
            <option value="">-- All --</option>
            <option value="peninsular" <?php echo $filterPriority === 'peninsular' ? 'selected' : ''; ?>>Peninsular</option>
            <option value="sabah"      <?php echo $filterPriority === 'sabah'      ? 'selected' : ''; ?>>Sabah</option>
            <option value="sarawak"    <?php echo $filterPriority === 'sarawak'    ? 'selected' : ''; ?>>Sarawak</option>
        </select>
    </div>
    <div style="flex:0 0 130px;">
        <span class="prc-filter-label">Category</span>
        <select name="category">
            <option value="">-- All --</option>
            <option value="regular" <?php echo (isset($filterCategory) && $filterCategory === 'regular') ? 'selected' : ''; ?>>Regular</option>
            <option value="ethical" <?php echo (isset($filterCategory) && $filterCategory === 'ethical') ? 'selected' : ''; ?>>Ethical</option>
        </select>
    </div>
    <?php if ($listType === 'slowmoving'): ?>
    <div style="flex:0 0 140px;">
        <span class="prc-filter-label">Trend</span>
        <select name="trend">
            <option value="">-- All Trend --</option>
            <option value="flat_down" <?php echo $filterTrend === 'flat_down' ? 'selected' : ''; ?>>Flat Down</option>
            <option value="up"        <?php echo $filterTrend === 'up'        ? 'selected' : ''; ?>>Up</option>
        </select>
    </div>
    <?php endif; ?>
    <div class="prc-filter-fixed">
        <span class="prc-filter-label">&nbsp;</span>
        <input type="submit" value="Search" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
        <a href="transferpicklist_procurement.php?list=<?php echo $listType; ?>" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">Clear</a>
        <a href="transferpicklist_procurement.php?list=<?php echo $listType; ?>&export=1<?php
            echo $filterSearch   !== '' ? '&search='   . urlencode($filterSearch)   : '';
            echo $filterOutlet   !== '' ? '&outlet='   . urlencode($filterOutlet)   : '';
            echo $filterType     !== '' ? '&type='     . urlencode($filterType)     : '';
            echo $filterPriority !== '' ? '&priority=' . urlencode($filterPriority) : '';
            echo $filterTrend    !== '' ? '&trend='    . urlencode($filterTrend)    : '';
        ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">&#8659; Export CSV</a>
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
        <?php if ($listType === 'slowmoving'): ?>
        <th width="80">Category</th>
        <th width="80">Type</th>
        <th>Last Month Closing Stock Qty</th>
        <th>Avg Past 3M Qty Sold</th>
        <th>Avg Past 3M MOI</th>
        <th>Adjusted Overstock Qty</th>
        <th>Trend</th>
        <?php else: ?>
        <th>Item Description</th>
        <th width="80">Category</th>
        <th width="80">Type</th>
        <th width="90">Priority</th>
        <th>Qty (Closing Stock)</th>
        <th>Avg Past 6M Qty Sold</th>
        <th>MOI</th>
        <?php endif; ?>
    </tr>
    <?php if (empty($pageRows)): ?>
    <tr><td colspan="9" class="alpro-center alpro-muted">No records found.</td></tr>
    <?php endif; ?>
    <?php $rn = $offset + 1; foreach ($pageRows as $row): ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rn++; ?></td>
        <td class="alpro-mono" style="font-weight:bold;">
            <?php echo htmlspecialchars($row['outlet_code']); ?>
            <?php if (!empty($row['acmm_id'])): ?>
                <span class="alpro-muted" style="font-weight:normal;font-size:11px;">(<?php echo str_pad((int)$row['acmm_id'], 4, '0', STR_PAD_LEFT); ?>)</span>
            <?php endif; ?>
        </td>
        <td class="alpro-mono"><?php echo htmlspecialchars($row['item_code']); ?></td>
        <?php if ($listType === 'slowmoving'): ?>
        <td class="alpro-center">
            <?php if (isset($row['category']) && $row['category'] === 'ethical'): ?>
                <span style="background:#7b1fa2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Ethical</span>
            <?php else: ?>
                <span style="background:#455a64;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Regular</span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php if (isset($model_arr[$row['item_code']]) && $model_arr[$row['item_code']] === 'Store/HQ Order'): ?>
                <span style="background:#f57c00;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Non-HQ</span>
            <?php else: ?>
                <span style="background:#1976d2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">HQ</span>
            <?php endif; ?>
        </td>
        <td class="alpro-center"><?php echo number_format((float)$row['last_month_closing'], 0); ?></td>
        <td class="alpro-center"><?php echo $row['avg_monthly'] !== null ? number_format((float)$row['avg_monthly'], 2) : '—'; ?></td>
        <td class="alpro-center"><?php echo number_format((float)$row['moi'], 2); ?></td>
        <td class="alpro-center"><?php echo number_format((float)$row['adjusted_overstock_qty'], 0); ?></td>
        <td class="alpro-center">
            <?php
            $trend = isset($row['trend_type']) ? $row['trend_type'] : '';
            if (stripos($trend, 'up') !== false) {
                echo '<span style="color:#388e3c;">' . htmlspecialchars($trend) . '</span>';
            } elseif (stripos($trend, 'down') !== false) {
                echo '<span style="color:#c62828;">' . htmlspecialchars($trend) . '</span>';
            } else {
                echo '<span style="color:#f57c00;">' . htmlspecialchars($trend ? $trend : '—') . '</span>';
            }
            ?>
        </td>
        <?php else: ?>
        <td><?php $desc = isset($item_arr[$row['item_code']]) ? $item_arr[$row['item_code']] : ''; echo htmlspecialchars($desc ? $desc : '—'); ?></td>
        <td class="alpro-center">
            <?php if (isset($row['category']) && $row['category'] === 'ethical'): ?>
                <span style="background:#7b1fa2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Ethical</span>
            <?php else: ?>
                <span style="background:#455a64;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Regular</span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php if (isset($model_arr[$row['item_code']]) && $model_arr[$row['item_code']] === 'Store/HQ Order'): ?>
                <span style="background:#f57c00;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Non-HQ</span>
            <?php else: ?>
                <span style="background:#1976d2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">HQ</span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php
            $oc = strtoupper($row['outlet_code']);
            if (in_array((int)$row['acmm_id'], array(1004,1005,1016))):
            ?><span style="color:#c62828;">Warehouse</span><?php
            elseif (strpos($oc, 'SKK') === 0):
            ?><span style="color:#7b1fa2;">Sabah</span><?php
            elseif (strpos($oc, 'Q') === 0):
            ?><span style="color:#0288d1;">Sarawak</span><?php
            else:
            ?><span style="color:#1976d2;">Peninsular</span><?php
            endif; ?>
        </td>
        <?php $avgMonthly = (float)$row['avg_monthly']; ?>
        <td class="alpro-center"><?php echo number_format((float)$row['closing_stock'], 0); ?></td>
        <td class="alpro-center"><?php echo number_format($avgMonthly, 2); ?></td>
        <td class="alpro-center">
            <?php
            $avg = $avgMonthly;
            $cs  = (float)$row['closing_stock'];
            if ($avg > 0) {
                echo '<span style="color:#c62828;font-weight:bold;">' . number_format($cs / $avg, 2) . '</span>';
            } else {
                echo '<span style="color:#c62828;font-weight:bold;">0.00</span>';
            }
            ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<!-- PAGINATION -->
<?php if ($totalPages > 1): ?>
<div style="margin:10px 0; text-align:center;">
    <a href="<?php echo $pgBase; ?>page=1" class="alpro-btn alpro-btn-grey">&laquo;&laquo; First</a>
    <?php if ($page > 1): ?>
        <a href="<?php echo $pgBase; ?>page=<?php echo $page-1; ?>" class="alpro-btn alpro-btn-grey">&laquo; Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1,$page-3); $p <= min($totalPages,$page+3); $p++): ?>
        <a href="<?php echo $pgBase; ?>page=<?php echo $p; ?>"
           class="alpro-btn <?php echo $p===$page ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"><?php echo $p; ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
        <a href="<?php echo $pgBase; ?>page=<?php echo $page+1; ?>" class="alpro-btn alpro-btn-grey">Next &raquo;</a>
    <?php endif; ?>
    <a href="<?php echo $pgBase; ?>page=<?php echo $totalPages; ?>" class="alpro-btn alpro-btn-grey">Last &raquo;&raquo;</a>
    <span class="alpro-muted" style="margin-left:10px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalItems); ?> records)</span>
</div>
<?php endif; ?>

<?php
tplT('Build page HTML');
if (isset($_GET['debug']) && (int)$transferpicklist_autho >= 1):
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