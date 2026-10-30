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
$_tpl_p3m_access = ((int)$transferpicklist_autho === 1) || ((int)$department === 18);
if (!$_tpl_p3m_access) { redirect('/odb/permission.php'); }

// Read-only page: release the PHP session lock now, so a slow request here never makes the
// user's other pages wait (PHP lets only one request per session hold the session at a time).
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
tplT('Login check + database connect (outer app)');

$filterOutlet   = isset($_GET['outlet'])    ? trim($_GET['outlet'])    : '';
$filterItem     = isset($_GET['item_code']) ? trim($_GET['item_code']) : ''; // item code OR description
$filterMonth    = isset($_GET['month'])     ? trim($_GET['month'])     : '';
$perPage        = tplPerPage(50);
$page           = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset         = ($page - 1) * $perPage;

// Build WHERE (shared by the page and the CSV export)
// Search matches item code OR item description (same as the Non/Slow Moving search).
$where = '1=1';
if ($filterOutlet !== '') {
    // outlet code -> id once, so the filter can use the (outlet_id, month_year, item_code) index
    $oRes = $conn->query("SELECT id FROM outlet WHERE code = '" . $conn->real_escape_string($filterOutlet) . "' LIMIT 1");
    $oRow = $oRes ? $oRes->fetch_assoc() : null;
    $where .= $oRow ? " AND p.outlet_id = " . (int)$oRow['id'] : " AND 1=0";
}
if ($filterMonth  !== '') { $where .= " AND p.month_year = '" . $conn->real_escape_string($filterMonth) . "'"; }
if ($filterItem   !== '') {
    // Look up matching descriptions ONCE in simple, then filter by that literal code list.
    // (An IN (SELECT ... FROM simple) subquery here took minutes on a 2M-row table.)
    $escIt   = $conn->real_escape_string($filterItem);
    $orParts = array("p.item_code LIKE '%$escIt%'");
    $dRes    = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$escIt%'");
    $dCodes  = array();
    while ($dRes && $d = $dRes->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
    if (!empty($dCodes)) { $orParts[] = "p.item_code IN (" . implode(',', $dCodes) . ")"; }
    $where .= " AND (" . implode(' OR ', $orParts) . ")";
}

// Export CSV
if (isset($_GET['export'])) {
    ob_end_clean();
    ini_set('memory_limit', '512M');
    $res = $conn->query("
        SELECT o.code as outlet_code, o.acmm_id, p.month_year, p.item_code,
               s.name as item_name, p.last_month_closing, p.qty_stock
        FROM transferpicklist_past3months p
        JOIN outlet o ON o.id = p.outlet_id
        LEFT JOIN simple s ON s.item_code = p.item_code
        WHERE $where
        ORDER BY o.code, p.month_year DESC, p.item_code
    ");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="past3months_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    // qty_stock holds Tableau's "Quantity Sold" (see hyper/past3m_sync.py) — labelled accordingly
    fputcsv($out, array('#', 'Outlet Code', 'Outlet ACMM', 'Month', 'Item Code', 'Item Name', 'Last Month Closing Stock Qty', 'Qty Sold'));
    $i = 1;
    while ($res && $r = $res->fetch_assoc()) {
        $acmm = !empty($r['acmm_id']) ? "'" . str_pad((int)$r['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
        fputcsv($out, array($i++, $r['outlet_code'], $acmm, $r['month_year'], $r['item_code'], $r['item_name'], $r['last_month_closing'], $r['qty_stock']));
    }
    fclose($out);
    exit;
}

// Count (filtered)
$totalRes   = $conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_past3months p WHERE $where");
$totalItems = $totalRes ? (int)$totalRes->fetch_assoc()['cnt'] : 0;
tplT('Count records');
$totalPages = max(1, ceil($totalItems / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Outlet list for dropdown
$outletRes  = $conn->query("SELECT DISTINCT o.code FROM transferpicklist_past3months p JOIN outlet o ON o.id = p.outlet_id ORDER BY o.code");
$outletList = array();
while ($outletRes && $r = $outletRes->fetch_assoc()) { $outletList[] = $r['code']; }
tplT('Outlet dropdown');

// Month list for dropdown
$monthRes  = $conn->query("SELECT DISTINCT month_year FROM transferpicklist_past3months ORDER BY month_year DESC");
$monthList = array();
while ($monthRes && $r = $monthRes->fetch_assoc()) { $monthList[] = $r['month_year']; }
tplT('Month dropdown');

// Data as of = last successful Past 3 Months sync
$syncDate = '';
$syncRes  = $conn->query("SELECT MAX(last_sync) AS latest FROM transferpicklist_past3months_status WHERE status = 1");
if ($syncRes && $sr = $syncRes->fetch_assoc()) { $syncDate = $sr['latest']; }
tplT('Data as of');

// Main query, in two cheap steps (sorting ~2M joined rows by outlet CODE took 85s on production):
//  a) this page's ids straight from uq_outlet_month_item (outlet_id, month_year, item_code),
//     which is already in display order — no JOIN, no sort, no full-row reads;
//  b) the full rows + outlet + description for just those ids.
// Order = outlet (by id), month, item code. With an item search the item_code index is left
// free to narrow the rows first.
$rows   = array();
$idxHint = ($filterItem === '') ? 'FORCE INDEX (uq_outlet_month_item)' : '';
$ids    = array();
$idRes  = $conn->query("
    SELECT p.id FROM transferpicklist_past3months p $idxHint
    WHERE $where
    ORDER BY p.outlet_id, p.month_year, p.item_code
    LIMIT $perPage OFFSET $offset
");
while ($idRes && $r = $idRes->fetch_assoc()) { $ids[] = (int)$r['id']; }
if (!empty($ids)) {
    $res = $conn->query("
        SELECT o.code as outlet_code, o.acmm_id, p.month_year, p.item_code,
               s.name as item_name, p.last_month_closing, p.qty_stock
        FROM transferpicklist_past3months p
        JOIN outlet o ON o.id = p.outlet_id
        LEFT JOIN simple s ON s.item_code = p.item_code
        WHERE p.id IN (" . implode(',', $ids) . ")
        ORDER BY p.outlet_id, p.month_year, p.item_code
    ");
    while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; }
}
tplT('Load 50 rows (sorted by outlet, month, item)');

// URL builder
function p3mUrl($params) {
    $base = 'transferpicklist_past3months_list.php';
    return $params ? $base . '?' . http_build_query($params) : $base;
}
$currentParams = array();
if ($filterOutlet !== '') { $currentParams['outlet']    = $filterOutlet; }
if ($filterItem   !== '') { $currentParams['item_code'] = $filterItem; }
if ($filterMonth  !== '') { $currentParams['month']     = $filterMonth; }
?>
<!DOCTYPE html>
<html>
<head>
<title>Past 3 Months Data</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Past 3 Months Data</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<!-- STATS -->
<div class="alpro-box">
    <div class="alpro-grid">
        <div class="alpro-field">
            <label class="alpro-muted">Total Outlets</label>
            <div style="font-size:22px;font-weight:bold;"><?php echo number_format(count($outletList)); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Total Records<?php echo $currentParams ? ' (filtered)' : ''; ?></label>
            <div style="font-size:22px;font-weight:bold;color:#c62828;"><?php echo number_format($totalItems); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Months</label>
            <div style="font-size:22px;font-weight:bold;color:#f57c00;"><?php echo number_format(count($monthList)); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Data As Of</label>
            <div style="font-size:16px;font-weight:bold;"><?php echo $syncDate ? date('d M Y', strtotime($syncDate)) : '—'; ?></div>
        </div>
    </div>
    <?php tplTableauLinks('past3months'); ?>
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
<form method="get" action="transferpicklist_past3months_list.php">
<div class="prc-filter-bar">
    <div class="prc-filter-grow">
        <span class="prc-filter-label">Search (Item Code / Description)</span>
        <input type="text" name="item_code" value="<?php echo htmlspecialchars($filterItem); ?>" placeholder="Item code or description...">
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
        <span class="prc-filter-label">Month</span>
        <select name="month">
            <option value="">-- All --</option>
            <?php foreach ($monthList as $m): ?>
            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $filterMonth === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="prc-filter-fixed">
        <span class="prc-filter-label">&nbsp;</span>
        <input type="submit" value="Search" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
        <a href="transferpicklist_past3months_list.php" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">Clear</a>
        <?php $expP = $currentParams; $expP['export'] = '1'; ?>
        <a href="<?php echo htmlspecialchars(p3mUrl($expP)); ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">&#8659; Export CSV</a>
    </div>
</div>
</form>

<!-- TABLE -->
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th>Outlet Code</th>
        <th width="90">Month</th>
        <th>Item Code</th>
        <th>Item Description</th>
        <th>Last Month Closing Stock Qty</th>
        <th>Qty Sold</th>
    </tr>
    <?php if (empty($rows)): ?>
    <tr><td colspan="7" class="alpro-center alpro-muted">No records found.</td></tr>
    <?php endif; ?>
    <?php $rn = $offset + 1; foreach ($rows as $r):
        $acmm = !empty($r['acmm_id']) ? str_pad((int)$r['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
    ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rn++; ?></td>
        <td class="alpro-mono" style="font-weight:bold;">
            <?php echo htmlspecialchars($r['outlet_code']); ?>
            <?php if ($acmm): ?><span class="alpro-muted" style="font-size:11px; font-weight:normal;"> (<?php echo $acmm; ?>)</span><?php endif; ?>
        </td>
        <td class="alpro-center"><?php echo htmlspecialchars($r['month_year']); ?></td>
        <td class="alpro-mono"><?php echo htmlspecialchars($r['item_code']); ?></td>
        <td><?php echo htmlspecialchars($r['item_name'] ? $r['item_name'] : '—'); ?></td>
        <td class="alpro-center"><?php echo number_format((float)$r['last_month_closing'], 0); ?></td>
        <td class="alpro-center" style="font-weight:bold; color:#1565c0;"><?php echo number_format((float)$r['qty_stock'], 0); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<!-- PAGINATION -->
<?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } // shared list footer (transferpicklist_pager.php) ?>

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
