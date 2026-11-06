<?php
// Delist / Discontinue — read-only view of MinMax's minmax_sku_exclusion (synced from Procurement's
// Tableau "Delist & Discontinue Master List" by ../minmax/minmax_delist_sync.php and
// minmax_discontinue_sync.php). Generate Pick List lets these items move outlet → outlet but NEVER
// returns them to the warehouse (leftover stays at the outlet as "maintain"); MinMax sets them to
// 0/0. Read-only here: the next MinMax sync replaces the list, so edits would be lost.
// Super Admin (staff.transferpicklist = 1) only.
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
mysqli_report(MYSQLI_REPORT_OFF);
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }
// Read-only page: release the PHP session lock so it never makes the user's other pages wait.
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$_chk = $conn->query("SHOW TABLES LIKE 'minmax_sku_exclusion'");
if (!$_chk || $_chk->num_rows === 0) { die('The MinMax Delist / Discontinue table (minmax_sku_exclusion) was not found in this database.'); }

$typeLabels   = array('delist' => 'Delist', 'discontinue' => 'Discontinue');
$filterType   = isset($_GET['type'])   && isset($typeLabels[$_GET['type']]) ? $_GET['type'] : '';
$filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = "source IN ('delist', 'discontinue')";
if ($filterType !== '')   { $where .= " AND source = '" . $conn->real_escape_string($filterType) . "'"; }
if ($filterSearch !== '') {
    $esc    = $conn->real_escape_string($filterSearch);
    $where .= " AND (item_code LIKE '%$esc%' OR item_description LIKE '%$esc%')";
}

// Count + last sync per type (whole list, not filtered)
$stats = array('delist' => array(0, ''), 'discontinue' => array(0, ''));
$sRes  = $conn->query("SELECT source, COUNT(*) c, MAX(timestamp) t FROM minmax_sku_exclusion WHERE source IN ('delist', 'discontinue') GROUP BY source");
while ($sRes && $s = $sRes->fetch_assoc()) { $stats[$s['source']] = array((int)$s['c'], (string)$s['t']); }
$bRes      = $conn->query("SELECT COUNT(*) c FROM (SELECT item_code FROM minmax_sku_exclusion WHERE source IN ('delist', 'discontinue') GROUP BY item_code HAVING COUNT(DISTINCT source) > 1) t");
$bothCount = $bRes ? (int)$bRes->fetch_assoc()['c'] : 0;

// ============================================================
// EXPORT EXCEL — everything matching the current filters (all pages)
// ============================================================
if (isset($_GET['export'])) {
    set_time_limit(0);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Delist_Discontinue_' . date('Ymd_His') . '.xls"');
    $x    = function ($v) { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $cell = function ($v) use ($x) { return '<Cell><Data ss:Type="String">' . $x($v) . '</Data></Cell>'; };
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
       . '<Styles><Style ss:ID="h"><Font ss:Bold="1"/><Interior ss:Color="#EFEFEF" ss:Pattern="Solid"/></Style></Styles>'
       . '<Worksheet ss:Name="Delist Discontinue"><Table>' . "\n<Row>";
    foreach (array('No.', 'Item Code', 'Description', 'Type', 'Item Status', 'Last Synced') as $h) {
        echo '<Cell ss:StyleID="h"><Data ss:Type="String">' . $x($h) . '</Data></Cell>';
    }
    echo "</Row>\n";
    $conn->real_query("SELECT item_code, item_description, source, item_status, timestamp FROM minmax_sku_exclusion WHERE $where ORDER BY source, item_code");
    $res = $conn->use_result();
    $i   = 0;
    while ($res && $r = $res->fetch_assoc()) {
        echo '<Row><Cell><Data ss:Type="Number">' . (++$i) . '</Data></Cell>'
           . $cell($r['item_code']) . $cell($r['item_description']) . $cell($typeLabels[$r['source']])
           . $cell($r['item_status']) . $cell($r['timestamp']) . "</Row>\n";
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

$perPage    = tplPerPage(50);
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$cRes       = $conn->query("SELECT COUNT(*) c FROM minmax_sku_exclusion WHERE $where");
$totalItems = $cRes ? (int)$cRes->fetch_assoc()['c'] : 0;
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = array();
$res  = $conn->query("SELECT item_code, item_description, source, item_status, timestamp FROM minmax_sku_exclusion WHERE $where ORDER BY source, item_code LIMIT $perPage OFFSET $offset");
while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; }

$exportUrl = 'transferpicklist_delist.php?' . http_build_query(array_filter(array('type' => $filterType, 'search' => $filterSearch, 'export' => 1), 'strlen'));
?>
<!DOCTYPE html>
<html>
<head>
<title>Delist / Discontinue</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.tpl-dd-title{display:flex;align-items:center;gap:8px;font-size:16px;font-weight:bold;color:#222;padding-bottom:10px;border-bottom:1px solid #e5e7eb;margin-bottom:10px;}
.tpl-dd-title small{font-size:12px;font-weight:normal;color:#888;}
.tpl-dd-stats{display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;}
.tpl-dd-stat{flex:1 1 160px;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;text-decoration:none;color:#222;background:#fff;}
.tpl-dd-stat b{display:block;font-size:22px;}
.tpl-dd-stat span{font-size:12px;color:#888;}
.tpl-dd-stat.on{border-color:#1976d2;box-shadow:0 0 0 1px #1976d2 inset;}
.tpl-dd-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;}
.tpl-dd-delist{background:#fff3e0;color:#e65100;}
.tpl-dd-discontinue{background:#fce4ec;color:#ad1457;}
.tpl-dd-form{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
.tpl-dd-form label{display:block;font-size:11px;color:#888;margin-bottom:3px;}
.tpl-dd-form .f{flex:1 1 180px;}
.tpl-dd-form input[type=text],.tpl-dd-form select{width:100%;height:28px;padding:0 6px;border:1px solid #ccc;border-radius:4px;font-size:12px;box-sizing:border-box;}
.tpl-dd-form .btn{flex:0 0 auto;height:28px;padding:0 14px;line-height:28px;}
.tpl-dd-note{font-size:12px;color:#555;background:#f6f8fb;border:1px solid #e3e8ef;border-radius:6px;padding:8px 12px;margin-bottom:10px;text-align:left;}
.myTable td,.myTable th{text-align:left;}
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Delist / Discontinue</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">
    <div class="tpl-dd-title">&#128683; Delist / Discontinue Items <small>— from MinMax (Procurement's Tableau master list). Read-only.</small></div>

    <div class="tpl-dd-note">
        <b>In the pick list:</b> these items can still move outlet &rarr; outlet (same Area Manager, and outside the Area Manager in the same region),
        but are <b>never returned to the warehouse</b> &mdash; whatever is left stays at the outlet. <b>In MinMax:</b> Min / Max set to 0 / 0.
        Updated by the MinMax Delist / Discontinue sync.
    </div>

    <div class="tpl-dd-stats">
        <a class="tpl-dd-stat <?php echo $filterType === '' ? 'on' : ''; ?>" href="transferpicklist_delist.php">
            <b><?php echo number_format($stats['delist'][0] + $stats['discontinue'][0] - $bothCount); ?></b><span>Items in total<?php echo $bothCount ? ' (' . number_format($bothCount) . ' on both lists)' : ''; ?></span></a>
        <?php foreach ($typeLabels as $_k => $_l): ?>
        <a class="tpl-dd-stat <?php echo $filterType === $_k ? 'on' : ''; ?>" href="transferpicklist_delist.php?type=<?php echo $_k; ?>">
            <b><?php echo number_format($stats[$_k][0]); ?></b>
            <span><?php echo $_l; ?> &middot; last synced <?php echo $stats[$_k][1] !== '' ? htmlspecialchars(date('d M Y, h:i A', strtotime($stats[$_k][1]))) : 'never'; ?></span></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="transferpicklist_delist.php" class="tpl-dd-form tpl-filter-bar">
        <div class="f"><label>Type</label>
            <select name="type">
                <option value="">-- All --</option>
                <?php foreach ($typeLabels as $_k => $_l): ?>
                <option value="<?php echo $_k; ?>" <?php echo $filterType === $_k ? 'selected' : ''; ?>><?php echo $_l; ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="f"><label>Search</label><input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Item code or description"></div>
        <input type="submit" value="Search" class="alpro-btn alpro-btn-blue btn">
        <a href="transferpicklist_delist.php" class="alpro-btn alpro-btn-grey btn">Clear</a>
        <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="alpro-btn alpro-btn-grey btn">&#8659; Export Excel</a>
    </form>

    <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th width="50">No.</th><th width="130">Item Code</th><th>Description</th><th width="120">Type</th><th width="170">Last Synced</th></tr>
        <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="alpro-muted">No items found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td><?php echo $offset + $i + 1; ?></td>
            <td class="alpro-mono"><?php echo htmlspecialchars($r['item_code']); ?></td>
            <td><?php echo htmlspecialchars($r['item_description']); ?></td>
            <td><span class="tpl-dd-pill tpl-dd-<?php echo $r['source']; ?>"><?php echo $typeLabels[$r['source']]; ?></span></td>
            <td><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($r['timestamp']))); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } ?>
</div>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
