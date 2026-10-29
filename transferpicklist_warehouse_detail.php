<?php
ini_set('memory_limit', '256M');
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
$_wh_det_access = ((int)$transferpicklist_autho === 1) || ((int)$department === 18);
if (!$_wh_det_access) { redirect('/odb/permission.php'); }

$picklistNo = isset($_GET['picklist_no']) ? trim($_GET['picklist_no']) : '';
$itemCode   = isset($_GET['item_code'])   ? trim($_GET['item_code'])   : '';
$region     = isset($_GET['region'])      ? trim($_GET['region'])      : '';

$regionAcmm = array(
    'peninsular' => array(1001, 1004),
    'sabah'      => array(1016, 1013),
    'sarawak'    => array(1005, 1002),
);
$regionLabel = array(
    'peninsular' => 'Semenanjung',
    'sabah'      => 'Sabah',
    'sarawak'    => 'Sarawak',
);

if ($picklistNo === '' || $itemCode === '' || !isset($regionAcmm[$region])) {
    die("Invalid parameters.");
}

$acmmList = implode(',', $regionAcmm[$region]);
$escIc    = $conn->real_escape_string($itemCode);
$escPl    = $conn->real_escape_string($picklistNo);
$label    = $regionLabel[$region];

// Product info — from URL params (passed by main table), fallback to simple table
$si = array(
    'name'                => isset($_GET['name'])         ? trim($_GET['name'])         : '',
    'model'               => isset($_GET['model'])        ? trim($_GET['model'])        : '',
    'hq_return_policy'    => isset($_GET['hq'])           ? trim($_GET['hq'])           : '',
    'product_ownership'   => isset($_GET['ownership'])    ? trim($_GET['ownership'])    : '',
    'procurement_officer' => isset($_GET['officer_code']) ? trim($_GET['officer_code']) : '',
    'reorder_point'       => isset($_GET['reorder'])      ? trim($_GET['reorder'])      : '',
);
$officerName = isset($_GET['officer_name']) ? trim($_GET['officer_name']) : '';
if ($si['name'] === '' && $si['model'] === '' && $si['hq_return_policy'] === '') {
    $siRes = $conn->query("SELECT name, model, hq_return_policy, product_ownership, procurement_officer, reorder_point FROM simple WHERE item_code = '$escIc' LIMIT 1");
    if ($siRes && $sr = $siRes->fetch_assoc()) {
        $si['name']                = isset($sr['name'])                ? $sr['name']                : '';
        $si['model']               = isset($sr['model'])               ? $sr['model']               : '';
        $si['hq_return_policy']    = isset($sr['hq_return_policy'])    ? $sr['hq_return_policy']    : '';
        $si['product_ownership']   = isset($sr['product_ownership'])   ? $sr['product_ownership']   : '';
        $si['procurement_officer'] = isset($sr['procurement_officer']) ? $sr['procurement_officer'] : '';
        $si['reorder_point']       = isset($sr['reorder_point'])       ? $sr['reorder_point']       : '';
    }
}
$isHq = (strtoupper(trim($si['hq_return_policy'])) === 'HQ');

// Filter by outlet
$filterOutletDet = isset($_GET['filter_outlet']) ? trim($_GET['filter_outlet']) : '';

// Load all outlet options for this item/picklist/region (for dropdown)
$outletOptions = array();
$optRes = $conn->query("
    SELECT DISTINCT fo.code as outlet_code, fo.comp_name as outlet_name
    FROM transferpicklist_item i
    JOIN transferpicklist_picklist p ON p.id = i.picklist_id
    JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList)
    LEFT JOIN outlet fo ON fo.id = i.from_outlet_id
    WHERE p.picklist_no   = '$escPl'
      AND i.item_code     = '$escIc'
      AND i.priority_type = 'warehouse'
      AND i.recycle       = 0
    ORDER BY fo.code
");
if ($optRes) { while ($r = $optRes->fetch_assoc()) { $outletOptions[] = $r; } }

// Outlet breakdown
$rows = array();
$outletWhere = '';
if ($filterOutletDet !== '') {
    $escFo = $conn->real_escape_string($filterOutletDet);
    $outletWhere = " AND fo.code = '$escFo'";
}
$dtRes = $conn->query("
    SELECT fo.code as outlet_code, fo.acmm_id as outlet_acmm, fo.comp_name as outlet_name, SUM(i.qty_transfer) as qty
    FROM transferpicklist_item i
    JOIN transferpicklist_picklist p ON p.id = i.picklist_id
    JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList)
    LEFT JOIN outlet fo ON fo.id = i.from_outlet_id
    WHERE p.picklist_no   = '$escPl'
      AND i.item_code     = '$escIc'
      AND i.priority_type = 'warehouse'
      AND i.recycle       = 0
      $outletWhere
    GROUP BY fo.code, fo.acmm_id, fo.comp_name
    ORDER BY fo.code
");
if ($dtRes) { while ($r = $dtRes->fetch_assoc()) { $rows[] = $r; } }

$totalQty = 0;
foreach ($rows as $r) { $totalQty += (float)$r['qty']; }
?>
<!DOCTYPE html>
<html>
<head>
<title>Warehouse Returns — Detail</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.tbl-wrap { overflow-x:auto; }
table.wh-tbl { width:100%; border-collapse:collapse; font-size:12px; }
table.wh-tbl th { background:#e8ecef; color:#2c3e50; padding:5px 8px; text-align:left; white-space:nowrap; border-bottom:2px solid #cfd8dc; }
table.wh-tbl td { padding:2px 8px; line-height:1.25; border-bottom:1px solid #eee; vertical-align:middle; }
table.wh-tbl tr:hover td { background:#f5f8ff; }
.badge-hq  { background:#1565c0; color:#fff; border-radius:3px; padding:2px 6px; font-size:11px; font-weight:bold; }
.badge-nhq { background:#546e7a; color:#fff; border-radius:3px; padding:2px 6px; font-size:11px; font-weight:bold; }
.region-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; background:#1565c0; color:#fff; }
.detail-header { background:#fff; border:1px solid #ddd; border-radius:6px; padding:4px 0; margin-bottom:14px; font-size:13px; }
table.info-tbl { width:100%; border-collapse:collapse; }
table.info-tbl td { padding:7px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
table.info-tbl td.lbl { color:#888; font-size:11px; width:140px; white-space:nowrap; }
table.info-tbl td.val { font-weight:600; color:#2c3e50; text-align:left; width:220px; }
.total-row td { background:#fff3e0 !important; font-weight:bold; border-top:2px solid #ffb300; }
.qty-val { font-weight:bold; color:#c62828; text-align:right; }
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Warehouse Returns — Detail</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<div class="alpro-box">

    <div style="text-align:left; margin-bottom:8px;">
        <a href="javascript:window.close();" style="font-size:12px; color:#1565c0; text-decoration:none;">&larr; Close</a>
    </div>

    <!-- Product Info -->
    <div class="detail-header">
        <table class="info-tbl">
            <tr>
                <td class="lbl">Picklist</td>
                <td class="val" style="font-family:monospace;"><?php echo htmlspecialchars($picklistNo); ?> &nbsp;<span class="region-badge"><?php echo htmlspecialchars($label); ?></span></td>
                <td class="lbl">Item Code</td>
                <td class="val" style="font-family:monospace;"><?php echo htmlspecialchars($itemCode); ?></td>
            </tr>
            <tr>
                <td class="lbl">Description</td>
                <td class="val" colspan="3"><?php echo htmlspecialchars(!empty($si['name']) ? $si['name'] : '—'); ?></td>
            </tr>
            <tr>
                <td class="lbl">Model</td>
                <td class="val"><?php echo htmlspecialchars(!empty($si['model']) ? $si['model'] : '—'); ?></td>
                <td class="lbl">Return</td>
                <td class="val">
                    <?php if (!empty($si['hq_return_policy'])): ?>
                    <span class="<?php echo $isHq ? 'badge-hq' : 'badge-nhq'; ?>"><?php echo htmlspecialchars($si['hq_return_policy']); ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="lbl">Product Ownership</td>
                <td class="val"><?php echo htmlspecialchars(!empty($si['product_ownership']) ? $si['product_ownership'] : '—'); ?></td>
                <td class="lbl">Reorder Point</td>
                <td class="val"><?php echo isset($si['reorder_point']) && $si['reorder_point'] !== '' ? number_format((float)$si['reorder_point'], 1) : '—'; ?></td>
            </tr>
            <tr>
                <td class="lbl">Procurement Officer</td>
                <td class="val" colspan="3">
                    <?php if (!empty($officerName)): ?>
                        <?php echo htmlspecialchars($officerName); ?>
                        <span style="color:#999; font-size:11px; margin-left:6px;">(<?php echo htmlspecialchars($si['procurement_officer']); ?>)</span>
                    <?php else: ?>
                        <?php echo htmlspecialchars(!empty($si['procurement_officer']) ? $si['procurement_officer'] : '—'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Filter by Outlet -->
    <?php
    $baseParams = array(
        'picklist_no'  => $picklistNo, 'item_code' => $itemCode, 'region' => $region,
        'name'         => $si['name'], 'model'     => $si['model'], 'hq' => $si['hq_return_policy'],
        'ownership'    => $si['product_ownership'], 'officer_code' => $si['procurement_officer'],
        'officer_name' => $officerName, 'reorder'  => $si['reorder_point'],
    );
    ?>
    <form method="get" action="transferpicklist_warehouse_detail.php" style="margin-bottom:12px;">
        <?php foreach ($baseParams as $k => $v): ?>
        <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
        <?php endforeach; ?>
        <div class="filter-bar" style="display:inline-flex; gap:8px; align-items:flex-end; padding:10px 14px;">
            <div>
                <label style="display:block; font-size:11px; color:#888; margin-bottom:3px;">Outlet</label>
                <select name="filter_outlet" onchange="this.form.submit()"
                        style="padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; height:32px; min-width:200px;">
                    <option value="">-- All Outlets --</option>
                    <?php foreach ($outletOptions as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt['outlet_code']); ?>"
                        <?php echo $filterOutletDet === $opt['outlet_code'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($opt['outlet_code']); ?>
                        <?php if (!empty($opt['outlet_name'])): ?> — <?php echo htmlspecialchars($opt['outlet_name']); ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <!-- Outlet Breakdown Table -->
    <div style="margin-bottom:8px; font-size:13px; color:#555;">
        <b><?php echo count($rows); ?></b> outlet(s) &nbsp;|&nbsp; Total Qty: <b style="color:#c62828;"><?php echo number_format($totalQty); ?></b>
    </div>
    <div class="tbl-wrap">
    <table class="wh-tbl">
        <thead>
            <tr>
                <th style="width:40px; text-align:center;">#</th>
                <th style="text-align:center;">Outlet Code</th>
                <th style="text-align:left;">Outlet Name</th>
                <th style="width:120px; text-align:center;">ACMM ID</th>
                <th style="width:100px; text-align:center;">Qty</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center; color:#999; padding:20px;">No data found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td style="color:#999; font-size:11px; text-align:center;"><?php echo $i + 1; ?></td>
            <td style="font-weight:600; color:#1565c0; font-family:monospace; text-align:center;"><?php echo htmlspecialchars($r['outlet_code'] ? $r['outlet_code'] : '—'); ?></td>
            <td style="color:#555; text-align:left;"><?php echo htmlspecialchars($r['outlet_name'] ? $r['outlet_name'] : '—'); ?></td>
            <td style="color:#555; text-align:center;"><?php echo htmlspecialchars($r['outlet_acmm'] ? $r['outlet_acmm'] : '—'); ?></td>
            <td style="text-align:center; font-weight:bold; color:#c62828;"><?php echo number_format((float)$r['qty']); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!empty($rows)): ?>
        <tr class="total-row">
            <td colspan="4" style="text-align:center; color:#555; font-weight:bold;">Total</td>
            <td style="text-align:center; font-weight:bold; color:#c62828;"><?php echo number_format($totalQty); ?></td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

</div>
<?php include(__DIR__ . '/transferpicklist_autofilter.php'); // auto-apply filters ?>
</body>
</html>
