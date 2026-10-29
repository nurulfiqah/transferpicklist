<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
if ((int)$transferpicklist_autho < 3) { redirect('/odb/permission.php'); }

$filterOutlet   = isset($_GET['outlet'])    ? trim($_GET['outlet'])    : '';
$filterItem     = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
$filterMonth    = isset($_GET['month'])     ? trim($_GET['month'])     : '';
$perPage        = 50;
$page           = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset         = ($page - 1) * $perPage;

// Export CSV
if (isset($_GET['export'])) {
    ob_end_clean();
    ini_set('memory_limit', '512M');

    $where = '1=1';
    if ($filterOutlet !== '') { $where .= " AND o.code = '" . $conn->real_escape_string($filterOutlet) . "'"; }
    if ($filterItem   !== '') { $where .= " AND p.item_code LIKE '%" . $conn->real_escape_string($filterItem) . "%'"; }
    if ($filterMonth  !== '') { $where .= " AND p.month_year = '" . $conn->real_escape_string($filterMonth) . "'"; }

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
    fputcsv($out, array('#', 'Outlet Code', 'Outlet ACMM', 'Month', 'Item Code', 'Item Name', 'Last Month Closing', 'Qty Stock'));
    $i = 1;
    while ($r = $res->fetch_assoc()) {
        $acmm = !empty($r['acmm_id']) ? "'" . str_pad((int)$r['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
        fputcsv($out, array($i++, $r['outlet_code'], $acmm, $r['month_year'], $r['item_code'], $r['item_name'], $r['last_month_closing'], $r['qty_stock']));
    }
    fclose($out);
    exit;
}

// Build WHERE
$where = '1=1';
if ($filterOutlet !== '') { $where .= " AND o.code = '" . $conn->real_escape_string($filterOutlet) . "'"; }
if ($filterItem   !== '') { $where .= " AND p.item_code LIKE '%" . $conn->real_escape_string($filterItem) . "%'"; }
if ($filterMonth  !== '') { $where .= " AND p.month_year = '" . $conn->real_escape_string($filterMonth) . "'"; }

// Stats
$totalRes   = $conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_past3months p JOIN outlet o ON o.id = p.outlet_id WHERE $where");
$totalItems = $totalRes ? (int)$totalRes->fetch_assoc()['cnt'] : 0;
$totalPages = max(1, ceil($totalItems / $perPage));

// Outlet list for dropdown
$outletRes  = $conn->query("SELECT DISTINCT o.code FROM transferpicklist_past3months p JOIN outlet o ON o.id = p.outlet_id ORDER BY o.code");
$outletList = array();
while ($r = $outletRes->fetch_assoc()) { $outletList[] = $r['code']; }

// Month list for dropdown
$monthRes  = $conn->query("SELECT DISTINCT month_year FROM transferpicklist_past3months ORDER BY month_year DESC");
$monthList = array();
while ($r = $monthRes->fetch_assoc()) { $monthList[] = $r['month_year']; }

// Main query
$rows = array();
$res  = $conn->query("
    SELECT o.code as outlet_code, o.acmm_id, p.month_year, p.item_code,
           s.name as item_name, p.last_month_closing, p.qty_stock
    FROM transferpicklist_past3months p
    JOIN outlet o ON o.id = p.outlet_id
    LEFT JOIN simple s ON s.item_code = p.item_code
    WHERE $where
    ORDER BY o.code, p.month_year DESC, p.item_code
    LIMIT $perPage OFFSET $offset
");
while ($r = $res->fetch_assoc()) { $rows[] = $r; }

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
<style>
    .stat-box { display:inline-block; background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:12px 20px; margin-right:12px; margin-bottom:10px; text-align:center; min-width:140px; }
    .stat-box .stat-num { font-size:22px; font-weight:bold; color:#1565c0; }
    .stat-box .stat-lbl { font-size:12px; color:#666; margin-top:2px; }
    .tbl-wrap { overflow-x:auto; }
    table.data-tbl { width:100%; border-collapse:collapse; font-size:13px; }
    table.data-tbl th { background:#2c3e50; color:#fff; padding:9px 10px; text-align:left; white-space:nowrap; }
    table.data-tbl td { padding:7px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
    table.data-tbl tr:hover td { background:#f5f8ff; }
    .outlet-code { font-weight:600; color:#1565c0; }
    .filter-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:15px; }
    .filter-row label { display:block; font-size:11px; color:#888; margin-bottom:3px; }
    .filter-row select, .filter-row input[type=text] { padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:13px; height:32px; }
    .pagination { margin-top:15px; display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
    .pagination a, .pagination span { padding:5px 10px; border:1px solid #ddd; border-radius:4px; font-size:13px; text-decoration:none; color:#333; }
    .pagination a:hover { background:#eaf0fb; }
    .pagination .active { background:#1565c0; color:#fff; border-color:#1565c0; font-weight:bold; }
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Past 3 Months Data</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

    <!-- Stats -->
    <div style="margin-bottom:15px;">
        <div class="stat-box">
            <div class="stat-num"><?php echo number_format(count($monthList)); ?></div>
            <div class="stat-lbl">Months in DB</div>
        </div>
        <div class="stat-box">
            <div class="stat-num"><?php echo number_format(count($outletList)); ?></div>
            <div class="stat-lbl">Outlets</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#1565c0;"><?php echo number_format($totalItems); ?></div>
            <div class="stat-lbl">Records (filtered)</div>
        </div>
    </div>

    <!-- Filter -->
    <form method="get" action="transferpicklist_past3months_list.php">
        <div class="filter-row">
            <div>
                <label>Outlet</label>
                <select name="outlet" style="width:150px;">
                    <option value="">-- All Outlets --</option>
                    <?php foreach ($outletList as $oc): ?>
                    <option value="<?php echo htmlspecialchars($oc); ?>" <?php echo $filterOutlet === $oc ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($oc); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Month</label>
                <select name="month" style="width:130px;">
                    <option value="">-- All Months --</option>
                    <?php foreach ($monthList as $m): ?>
                    <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $filterMonth === $m ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($m); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Item Code</label>
                <input type="text" name="item_code" value="<?php echo htmlspecialchars($filterItem); ?>" placeholder="e.g. 100005719" style="width:160px;">
            </div>
            <div>
                <button type="submit" class="alpro-btn alpro-btn-blue">Search</button>
                <a href="transferpicklist_past3months_list.php" class="alpro-btn alpro-btn-grey">Clear</a>
                <?php $expP = $currentParams; $expP['export'] = '1'; ?>
                <a href="<?php echo p3mUrl($expP); ?>" class="alpro-btn alpro-btn-grey" style="color:#1565c0;">&#8595; Export CSV</a>
            </div>
        </div>
    </form>

    <?php if (empty($rows)): ?>
    <div class="alpro-alert alpro-success">No records found.</div>
    <?php else: ?>

    <div style="margin-bottom:8px; font-size:13px; color:#555;">
        Showing <b><?php echo number_format($offset + 1); ?></b>–<b><?php echo number_format(min($offset + $perPage, $totalItems)); ?></b>
        of <b><?php echo number_format($totalItems); ?></b> records
    </div>

    <div class="tbl-wrap">
    <table class="data-tbl">
        <thead>
            <tr>
                <th>#</th>
                <th>Outlet</th>
                <th>Month</th>
                <th>Item Code</th>
                <th>Item Description</th>
                <th>Last Month Closing</th>
                <th>Qty Stock</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rowNum     = $offset + 1;
        $lastOutlet = null;
        foreach ($rows as $r):
            $acmm      = !empty($r['acmm_id']) ? str_pad((int)$r['acmm_id'], 4, '0', STR_PAD_LEFT) : '';
            $isNew     = ($r['outlet_code'] !== $lastOutlet);
            $lastOutlet = $r['outlet_code'];
        ?>
        <tr <?php echo $isNew && $rowNum > $offset + 1 ? 'style="border-top:2px solid #cde;"' : ''; ?>>
            <td style="color:#999;"><?php echo $rowNum++; ?></td>
            <td>
                <span class="outlet-code"><?php echo htmlspecialchars($r['outlet_code']); ?></span>
                <?php if ($acmm): ?>
                <span style="color:#999; font-size:11px;">(<?php echo $acmm; ?>)</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap;"><?php echo htmlspecialchars($r['month_year']); ?></td>
            <td style="font-family:monospace;"><?php echo htmlspecialchars($r['item_code']); ?></td>
            <td><?php echo htmlspecialchars($r['item_name'] ? $r['item_name'] : '—'); ?></td>
            <td style="text-align:right; font-weight:bold;"><?php echo number_format((float)$r['last_month_closing'], 0); ?></td>
            <td style="text-align:right; color:#1565c0; font-weight:bold;"><?php echo number_format((float)$r['qty_stock'], 0); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $prevP = $currentParams; $prevP['page'] = $page - 1;
        $nextP = $currentParams; $nextP['page'] = $page + 1;
        $lastP = $currentParams; $lastP['page'] = $totalPages;
        $firstP = $currentParams; $firstP['page'] = 1;
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="<?php echo p3mUrl($firstP); ?>">&laquo;&laquo; First</a>
        <a href="<?php echo p3mUrl($prevP); ?>">&laquo; Prev</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 3);
        $end   = min($totalPages, $page + 3);
        for ($p = $start; $p <= $end; $p++):
            $pP = $currentParams; $pP['page'] = $p;
        ?>
        <?php if ($p === $page): ?>
        <span class="active"><?php echo $p; ?></span>
        <?php else: ?>
        <a href="<?php echo p3mUrl($pP); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?php echo p3mUrl($nextP); ?>">Next &raquo;</a>
        <a href="<?php echo p3mUrl($lastP); ?>">Last &raquo;&raquo;</a>
        <?php endif; ?>
        <span style="color:#888; border:none;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
