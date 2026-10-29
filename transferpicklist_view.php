<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
ini_set('memory_limit', '512M');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
require_once(__DIR__ . '/transferpicklist_route_filter.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
$_tpl_is_super_admin = (int)$transferpicklist_autho === 1;
$_tpl_is_admin = (int)$transferpicklist_autho === 1;
$_tpl_is_proc  = $_tpl_is_admin || ((int)$department === 18);
if (!$_tpl_is_proc) { redirect('/odb/permission.php'); }

$id         = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$outletCode = isset($_GET['outlet']) ? trim($_GET['outlet']) : '';
$msg        = '';
$msg_type   = '';

if (!$id) { header('Location: index.php'); die; }

// Load pick list header
$stmt = $conn->prepare("SELECT * FROM transferpicklist_picklist WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$pl = $stmt->get_result()->fetch_assoc();
// Confirm Return only applies to warehouse returns — hidden on -outlet pick lists
$_tplShowReturn = !($pl && tplPicklistKind($pl['picklist_no']) === 'outlet');
$stmt->close();

if (!$pl) { header('Location: index.php'); die; }

// Publish action
if (isset($_POST['publish']) && $_tpl_is_super_admin) {
    $stmt = $conn->prepare("UPDATE transferpicklist_picklist SET status='published' WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $by  = isset($_SESSION['nama_staff']) ? $_SESSION['nama_staff'] : 'HQ';
    $ts  = date('Y-m-d H:i:s');
    $act = 'Published pick list';
    $stmt = $conn->prepare("INSERT INTO transferpicklist_log (picklist_id, action, by_user, timestamp) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $id, $act, $by, $ts);
    $stmt->execute();
    $stmt->close();
    $pl['status'] = 'published';
    $msg      = 'Pick list published. Outlets can now view their transfer tasks.';
    $msg_type = 'success';
}

// Undo Published → Draft
if (isset($_POST['undo_publish']) && $_tpl_is_super_admin) {
    $stmt = $conn->prepare("UPDATE transferpicklist_picklist SET status='draft' WHERE id=? AND status='published'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $pl['status'] = 'draft';
    $msg      = 'Pick list reverted to Draft.';
    $msg_type = 'success';
}

// Delete picklist (draft only)
if (isset($_POST['delete_picklist']) && $_tpl_is_super_admin) {
    $conn->query("DELETE FROM transferpicklist_item WHERE picklist_id = $id");
    $conn->query("DELETE FROM transferpicklist_log  WHERE picklist_id = $id");
    $stmt = $conn->prepare("DELETE FROM transferpicklist_picklist WHERE id=? AND status='draft'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: index.php');
    exit;
}

// Complete action
if (isset($_POST['complete']) && $_tpl_is_super_admin) {
    $stmt = $conn->prepare("UPDATE transferpicklist_picklist SET status='completed' WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $by  = isset($_SESSION['nama_staff']) ? $_SESSION['nama_staff'] : 'HQ';
    $ts  = date('Y-m-d H:i:s');
    $act = 'Marked pick list as completed';
    $stmt = $conn->prepare("INSERT INTO transferpicklist_log (picklist_id, action, by_user, timestamp) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $id, $act, $by, $ts);
    $stmt->execute();
    $stmt->close();
    $pl['status'] = 'completed';
    $msg      = 'Pick list marked as completed.';
    $msg_type = 'success';
}

// Export CSV
if (isset($_GET['export'])) {
    ob_end_clean();
    ini_set('memory_limit', '512M');
    $expOutlet = isset($_GET['outlet']) ? trim($_GET['outlet']) : '';

    if ($expOutlet === '') {
        // Grouped export — matches what is shown on screen, respects all filters
        $expSearch      = isset($_GET['search'])           ? trim($_GET['search'])           : '';
        $expPri         = isset($_GET['search_priority'])  ? trim($_GET['search_priority'])  : '';
        $expStat        = isset($_GET['search_status'])    ? trim($_GET['search_status'])    : '';
        $expType        = isset($_GET['search_type'])      ? trim($_GET['search_type'])      : '';
        $expOutletCode  = isset($_GET['search_outlet'])    ? trim($_GET['search_outlet'])    : '';
        $expWhere   = "i.picklist_id = $id AND i.recycle = 0";
        if ($expOutletCode !== '') { $expWhere .= " AND fo.code = '" . $conn->real_escape_string($expOutletCode) . "'"; }
        if (isset($_GET['search_confirm']) && $_GET['search_confirm'] !== '') {
            $expWhere .= " AND i.priority_type = 'warehouse' AND i.return_confirmed = " . ($_GET['search_confirm'] === '1' ? '1' : '0');
        }
        if ($expSearch !== '') {
            $esc       = $conn->real_escape_string($expSearch);
            $expOr     = array("fo.code LIKE '%$esc%'", "i.item_code LIKE '%$esc%'");
            $expDCodes = array();
            $dRes = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
            while ($d = $dRes->fetch_assoc()) { $expDCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
            if (!empty($expDCodes)) { $expOr[] = "i.item_code IN (" . implode(',', $expDCodes) . ")"; }
            $expWhere .= " AND (" . implode(' OR ', $expOr) . ")";
        }
        if ($expPri  !== '') { $expWhere .= " AND " . tplRouteWhere($conn, $expPri); }
        if ($expStat !== '') { $expWhere .= " AND i.status = '" . $conn->real_escape_string($expStat) . "'"; }
        if ($expType !== '') {
            $expTypeCodes = array();
            $expTypeCond  = $expType === 'non_hq' ? "model = 'Store/HQ Order'" : "model != 'Store/HQ Order'";
            $tRes = $conn->query("SELECT item_code FROM simple WHERE $expTypeCond");
            while ($t = $tRes->fetch_assoc()) { $expTypeCodes[] = "'" . $conn->real_escape_string($t['item_code']) . "'"; }
            if (!empty($expTypeCodes)) { $expWhere .= " AND i.item_code IN (" . implode(',', $expTypeCodes) . ")"; }
            else { $expWhere .= " AND 1=0"; }
        }

        $expRes = $conn->query("
            SELECT i.item_code, i.qty_transfer, i.priority_type, i.status,
                fo.code as from_code, fo.acmm_id as from_acmm, fo.comp_name as from_name,
                to2.code as to_code, to2.acmm_id as to_acmm, to2.comp_name as to_name
            FROM transferpicklist_item i
            JOIN outlet fo  ON fo.id = i.from_outlet_id
            JOIN outlet to2 ON to2.id = i.to_outlet_id
            WHERE $expWhere
            ORDER BY fo.code, i.item_code
        ");
        $expRows  = array();
        $expCodes = array();
        while ($r = $expRes->fetch_assoc()) { $expRows[] = $r; $expCodes[$r['item_code']] = true; }

        $expItemArr = array();
        foreach (array_chunk(array_keys($expCodes), 500) as $chunk) {
            $inList = implode(',', array_map(function($c) use ($conn) { return "'" . $conn->real_escape_string($c) . "'"; }, $chunk));
            $dRes = $conn->query("SELECT item_code, name, model FROM simple WHERE item_code IN ($inList)");
            while ($d = $dRes->fetch_assoc()) { $expItemArr[$d['item_code']] = array('name' => $d['name'], 'model' => $d['model']); }
        }

        $suffix = $expOutletCode !== '' ? '_' . $expOutletCode : ($expPri !== '' ? '_' . $expPri : ($expStat !== '' ? '_' . $expStat : ''));
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $pl['picklist_no'] . $suffix . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('#', 'From Short Code', 'From Outlet Code', 'Transfer To Short Code', 'Transfer To Code', 'Type', 'Item Code', 'Item Description', 'Qty', 'Priority', 'Status'));
        $rowNum = 1;
        foreach ($expRows as $r) {
            $itm      = isset($expItemArr[$r['item_code']]) ? $expItemArr[$r['item_code']] : array('name'=>'','model'=>'');
            $type     = (isset($itm['model']) && $itm['model'] === 'Store/HQ Order') ? 'Non-HQ' : 'HQ';
            $fromAcmm = !empty($r['from_acmm']) ? "'" . str_pad((int)$r['from_acmm'], 4, '0', STR_PAD_LEFT) : '';
            $toAcmm   = !empty($r['to_acmm'])   ? "'" . str_pad((int)$r['to_acmm'],   4, '0', STR_PAD_LEFT) : '';
            $status   = $r['status'] === 'transferred' ? 'Confirmed' : ucfirst($r['status']);
            fputcsv($out, array($rowNum++, $r['from_code'], $fromAcmm, $r['to_code'], $toAcmm, $type, $r['item_code'], $itm['name'], $r['qty_transfer'], $r['priority_type'], $status));
        }
        fclose($out);
        exit;

    } else {
        // Detail export: all items for a specific outlet
        $expItemCode = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
        $expPriority = isset($_GET['priority'])  ? trim($_GET['priority'])  : '';
        $expStatus   = isset($_GET['status'])    ? trim($_GET['status'])    : '';
        $expType     = isset($_GET['type'])      ? trim($_GET['type'])      : '';
        $expWhere    = "i.picklist_id = $id AND i.recycle = 0 AND fo.code = '" . $conn->real_escape_string($expOutlet) . "'";
        if (isset($_GET['confirm']) && $_GET['confirm'] !== '') {
            $expWhere .= " AND i.priority_type = 'warehouse' AND i.return_confirmed = " . ($_GET['confirm'] === '1' ? '1' : '0');
        }
        if ($expItemCode !== '') { $expWhere .= " AND i.item_code LIKE '%" . $conn->real_escape_string($expItemCode) . "%'"; }
        if ($expPriority !== '') { $expWhere .= " AND " . tplRouteWhere($conn, $expPriority); }
        if ($expStatus   !== '') { $expWhere .= " AND i.status = '" . $conn->real_escape_string($expStatus) . "'"; }
        if ($expType === 'hq')    { $expWhere .= " AND (s.model IS NULL OR s.model != 'Store/HQ Order')"; }
        if ($expType === 'nonhq') { $expWhere .= " AND s.model = 'Store/HQ Order'"; }
        $expRes = $conn->query("
            SELECT i.item_code, i.qty_transfer, i.priority_type, i.status,
                fo.code as from_code,
                to2.code as to_code, to2.acmm_id as to_acmm, to2.comp_name as to_name,
                s.model as item_model, s.name as item_desc
            FROM transferpicklist_item i
            JOIN outlet fo  ON fo.id  = i.from_outlet_id
            JOIN outlet to2 ON to2.id = i.to_outlet_id
            LEFT JOIN simple s ON s.item_code = i.item_code
            WHERE $expWhere
            ORDER BY i.priority_type, i.item_code
        ");
        $expRows = array();
        while ($r = $expRes->fetch_assoc()) { $expRows[] = $r; }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $pl['picklist_no'] . '_' . $expOutlet . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('#', 'From Short Code', 'From Outlet Code', 'Transfer To Short Code', 'Transfer To Code', 'Type', 'Item Code', 'Item Description', 'Qty', 'Priority', 'Status'));
        $rowNum = 1;
        foreach ($expRows as $r) {
            $type   = (isset($r['item_model']) && $r['item_model'] === 'Store/HQ Order') ? 'Non-HQ' : 'HQ';
            $desc   = isset($r['item_desc']) ? $r['item_desc'] : '';
            $toAcmm = !empty($r['to_acmm']) ? "'" . str_pad((int)$r['to_acmm'], 4, '0', STR_PAD_LEFT) : '';
            $status = $r['status'] === 'transferred' ? 'Confirmed' : ucfirst($r['status']);
            fputcsv($out, array($rowNum++, $r['from_code'], '', $r['to_code'], $toAcmm, $type, $r['item_code'], $desc, $r['qty_transfer'], $r['priority_type'], $status));
        }
        fclose($out);
        exit;
    }
}

// Stats
$stmt = $conn->prepare("
    SELECT COUNT(*) as total,
        SUM(status='pending') as pending,
        SUM(status='transferred') as transferred,
        SUM(status='cancelled') as cancelled,
        SUM(priority_type='warehouse') as warehouse_total,
        SUM(priority_type='warehouse' AND return_confirmed=1) as warehouse_confirmed
    FROM transferpicklist_item WHERE picklist_id = ? AND recycle = 0
");
$stmt->bind_param('i', $id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
<title>Pick List <?php echo htmlspecialchars($pl['picklist_no']); ?></title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Pick List: <?php echo htmlspecialchars($pl['picklist_no']); ?></h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<div class="alpro-box">
    <!-- Pick list details + counts in ONE row (wraps on narrow screens) -->
    <style>
    .pl-summary-row { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:flex-start; }
    .pl-summary-row > .alpro-field { flex:1 1 0; min-width:120px; text-align:center; margin:0; }
    </style>
    <div class="pl-summary-row">
        <div class="alpro-field">
            <label class="alpro-muted">Pick List No</label>
            <div class="alpro-mono"><?php echo htmlspecialchars($pl['picklist_no']); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Generated By</label>
            <div><?php echo htmlspecialchars($pl['generated_by']); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Status</label>
            <div>
                <?php if ($pl['status'] === 'draft'): ?>
                    <span style="color:#f57c00;">Draft</span>
                <?php elseif ($pl['status'] === 'published'): ?>
                    <span style="color:#1976d2;">Published</span>
                <?php else: ?>
                    <span style="color:#388e3c;">Completed</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Date</label>
            <div><?php echo htmlspecialchars($pl['timestamp']); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Total Items</label>
            <div style="font-size:20px;font-weight:bold;"><?php echo (int)$stats['total']; ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Pending</label>
            <div style="font-size:20px;font-weight:bold;color:#f57c00;"><?php echo (int)$stats['pending']; ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Confirmed</label>
            <div style="font-size:20px;font-weight:bold;color:#388e3c;"><?php echo (int)$stats['transferred']; ?></div>
        </div>
        <?php if ((int)$stats['warehouse_total'] > 0): ?>
        <div class="alpro-field">
            <label class="alpro-muted">Total Confirm Return</label>
            <div style="font-size:20px;font-weight:bold;color:#388e3c;">
                <?php echo (int)$stats['warehouse_confirmed']; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($_tpl_is_super_admin): ?>
    <form method="post">
        <div class="alpro-actions alpro-mt-10" style="display:flex; justify-content:flex-end; gap:6px; flex-wrap:wrap;">
            <?php if ($pl['status'] === 'draft'): ?>
                <input class="alpro-btn alpro-btn-blue" type="submit" name="publish" value="Publish to Outlets">
            <?php elseif ($pl['status'] === 'published'): ?>
                <input class="alpro-btn alpro-btn-blue" type="submit" name="complete" value="Mark as Completed">
            <?php endif; ?>
            <?php if ($_tpl_is_super_admin && $pl['status'] === 'published'): ?>
                <button type="submit" name="undo_publish" value="1" class="alpro-btn alpro-btn-orange" style="border:none; cursor:pointer;"
                    onclick="return confirm('Undo published? This will revert the picklist back to Draft.');">Undo Published</button>
            <?php endif; ?>
            <?php if ($_tpl_is_super_admin && $pl['status'] === 'draft'): ?>
                <button type="submit" name="delete_picklist" value="1" class="alpro-btn" style="border:none; cursor:pointer; background:#c62828; color:#fff;"
                    onclick="return confirm('Delete this picklist?\n\nAll items will be permanently deleted.');">Delete</button>
            <?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php if ($outletCode === ''): ?>
<!-- OUTLET GROUPED VIEW -->
<?php
$filterSearch      = isset($_GET['search'])           ? trim($_GET['search'])           : '';
$filterSumPri      = isset($_GET['search_priority'])  ? trim($_GET['search_priority'])  : '';
$filterSumStat     = isset($_GET['search_status'])    ? trim($_GET['search_status'])    : '';
$filterSumType     = isset($_GET['search_type'])      ? trim($_GET['search_type'])      : '';
$filterOutletCode  = isset($_GET['search_outlet'])    ? trim($_GET['search_outlet'])    : '';
$filterSumConfirm  = ($_tplShowReturn && isset($_GET['search_confirm'])) ? trim($_GET['search_confirm']) : '';

// Load outlet list for this picklist (for dropdown)
$_outletListRes = $conn->query("SELECT DISTINCT fo.code FROM transferpicklist_item i JOIN outlet fo ON fo.id = i.from_outlet_id WHERE i.picklist_id = $id AND i.recycle = 0 ORDER BY fo.code");
$_outletListArr = array();
while ($_ol = $_outletListRes->fetch_assoc()) { $_outletListArr[] = $_ol['code']; }

$perPage = tplPerPage(50);
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$summaryWhere = "i.picklist_id = $id AND i.recycle = 0";
if ($filterOutletCode !== '') { $summaryWhere .= " AND fo.code = '" . $conn->real_escape_string($filterOutletCode) . "'"; }
if ($filterSearch !== '') {
    $esc      = $conn->real_escape_string($filterSearch);
    $searchOr = array("fo.code LIKE '%$esc%'", "i.item_code LIKE '%$esc%'");
    $dCodes   = array();
    $dRes = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
    while ($d = $dRes->fetch_assoc()) { $dCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
    if (!empty($dCodes)) { $searchOr[] = "i.item_code IN (" . implode(',', $dCodes) . ")"; }
    $summaryWhere .= " AND (" . implode(' OR ', $searchOr) . ")";
}
if ($filterSumPri  !== '') { $summaryWhere .= " AND " . tplRouteWhere($conn, $filterSumPri); }
if ($filterSumStat !== '') { $summaryWhere .= " AND i.status = '" . $conn->real_escape_string($filterSumStat) . "'"; }
if ($filterSumType !== '') {
    $typeCodes = array();
    $typeCond  = $filterSumType === 'non_hq' ? "model = 'Store/HQ Order'" : "model != 'Store/HQ Order'";
    $tRes = $conn->query("SELECT item_code FROM simple WHERE $typeCond");
    while ($t = $tRes->fetch_assoc()) { $typeCodes[] = "'" . $conn->real_escape_string($t['item_code']) . "'"; }
    if (!empty($typeCodes)) { $summaryWhere .= " AND i.item_code IN (" . implode(',', $typeCodes) . ")"; }
    else { $summaryWhere .= " AND 1=0"; }
}
if ($filterSumConfirm !== '') {
    $summaryWhere .= " AND i.priority_type = 'warehouse' AND i.return_confirmed = " . ($filterSumConfirm === '1' ? '1' : '0');
}

$totalItems = (int)$conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_item i JOIN outlet fo ON fo.id = i.from_outlet_id WHERE $summaryWhere")->fetch_assoc()['cnt'];
$totalPages = max(1, ceil($totalItems / $perPage));

$allItems = $conn->query("
    SELECT i.item_code, i.qty_transfer, i.priority_type, i.status, i.return_confirmed,
        fo.code as from_code, fo.acmm_id as from_acmm, fo.comp_name as from_name,
        to2.code as to_code, to2.acmm_id as to_acmm, to2.comp_name as to_name
    FROM transferpicklist_item i
    JOIN outlet fo  ON fo.id = i.from_outlet_id
    JOIN outlet to2 ON to2.id = i.to_outlet_id
    WHERE $summaryWhere
    ORDER BY fo.code, i.item_code
    LIMIT $perPage OFFSET $offset
");
$pageRows = array();
$_codes   = array();
while ($r = $allItems->fetch_assoc()) {
    $pageRows[] = $r;
    $_codes[$r['item_code']] = true;
}

// One lookup for all item codes on this page — reuse $item_arr in display
$item_arr = array();
if (!empty($_codes)) {
    $inList = implode(',', array_map(function($c) use ($conn) {
        return "'" . $conn->real_escape_string($c) . "'";
    }, array_keys($_codes)));
    $res = $conn->query("SELECT item_code, name, model FROM simple WHERE item_code IN ($inList)");
    while ($d = $res->fetch_assoc()) {
        $item_arr[$d['item_code']] = array('name' => $d['name'], 'model' => $d['model']);
    }
}
?>
<style>
.tpl-filter-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;}
.tpl-filter-bar table{width:100%;border-collapse:collapse;}
.tpl-filter-bar td{padding:0 6px;vertical-align:bottom;white-space:normal;}
.tpl-filter-bar td:first-child{padding-left:0;}
.tpl-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.tpl-filter-bar input[type=text],.tpl-filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.tpl-filter-bar input[type=text]:focus,.tpl-filter-bar select:focus{border-color:#1976d2;outline:none;}
.outlet-group-header{background:#2c3e50;color:#fff;font-weight:bold;padding:6px 10px;}
.outlet-group-header td{padding:6px 8px !important;}
</style>
<form method="get" action="transferpicklist_view.php">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <div class="tpl-filter-bar">
        <table>
            <tr>
                <td style="width:24%;">
                    <span class="tpl-filter-label">Search (Item Code / Description)</span>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Item code or description...">
                </td>
                <td style="width:16%;">
                    <span class="tpl-filter-label">Outlet</span>
                    <select name="search_outlet">
                        <option value="">-- All Outlets --</option>
                        <?php foreach ($_outletListArr as $_oc): ?>
                        <option value="<?php echo htmlspecialchars($_oc); ?>" <?php echo $filterOutletCode === $_oc ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($_oc); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td style="width:14%;">
                    <span class="tpl-filter-label">Transfer Route</span>
                    <select name="search_priority" title="Where Generate sent each item: first to an outlet under the same Area Manager, then to any outlet in the same region; if no outlet needs it, HQ items return to that region's warehouse and Non-HQ items stay at the outlet.">
                        <?php tplRouteOptions($filterSumPri, tplPicklistKind($pl['picklist_no']), true); ?>
                    </select>
                </td>
                <td style="width:12%;">
                    <span class="tpl-filter-label">Item Type</span>
                    <select name="search_type">
                        <option value="">-- All --</option>
                        <option value="hq"     <?php echo $filterSumType === 'hq'     ? 'selected' : ''; ?>>HQ</option>
                        <option value="non_hq" <?php echo $filterSumType === 'non_hq' ? 'selected' : ''; ?>>Non-HQ</option>
                    </select>
                </td>
                <td style="width:18%;">
                    <span class="tpl-filter-label">Status</span>
                    <select name="search_status">
                        <option value="">-- All Status --</option>
                        <option value="pending"     <?php echo $filterSumStat === 'pending'     ? 'selected' : ''; ?>>Pending</option>
                        <option value="transferred" <?php echo $filterSumStat === 'transferred' ? 'selected' : ''; ?>>Confirmed</option>
                    </select>
                </td>
                <?php if ($_tplShowReturn): ?>
                <td style="width:16%;">
                    <span class="tpl-filter-label">Confirm Return (Warehouse)</span>
                    <select name="search_confirm">
                        <option value="">-- All --</option>
                        <option value="1" <?php echo $filterSumConfirm === '1' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="0" <?php echo $filterSumConfirm === '0' ? 'selected' : ''; ?>>Not Confirmed</option>
                    </select>
                </td>
                <?php endif; ?>
                <td style="width:1%;white-space:nowrap;">
                    <span class="tpl-filter-label">&nbsp;</span>
                    <input type="submit" value="Search" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
                    <a href="transferpicklist_view.php?id=<?php echo $id; ?>" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 14px;line-height:32px;display:inline-block;">Reset</a>
                    <a href="transferpicklist_view.php?id=<?php echo $id; ?>&export=1<?php
                        echo $filterOutletCode !== '' ? '&search_outlet='    . urlencode($filterOutletCode) : '';
                        echo $filterSearch     !== '' ? '&search='           . urlencode($filterSearch)     : '';
                        echo $filterSumPri     !== '' ? '&search_priority='  . urlencode($filterSumPri)     : '';
                        echo $filterSumStat    !== '' ? '&search_status='    . urlencode($filterSumStat)    : '';
                        echo $filterSumType    !== '' ? '&search_type='      . urlencode($filterSumType)    : '';
                        echo $filterSumConfirm !== '' ? '&search_confirm='   . urlencode($filterSumConfirm) : '';
                    ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 14px;line-height:32px;display:inline-block;">&#8659; Export CSV</a>
                </td>
            </tr>
        </table>
    </div>
</form>

<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th width="110">Transfer To</th>
        <th width="80">Type</th>
        <th>Item Code</th>
        <th>Item Description</th>
        <th width="60">Qty</th>
        <th width="90">Priority</th>
        <th width="80">Status</th>
        <?php if ($_tplShowReturn): ?><th width="100">Confirm Return</th><?php endif; ?>
    </tr>
    <?php if (empty($pageRows)): ?>
    <tr><td colspan="<?php echo $_tplShowReturn ? 9 : 8; ?>" class="alpro-center alpro-muted">No items found.</td></tr>
    <?php endif; ?>
    <?php
    $currentOutlet = null;
    $rn = $offset;
    foreach ($pageRows as $row):
        $_itm = isset($item_arr[$row['item_code']]) ? $item_arr[$row['item_code']] : array('name'=>'','model'=>'');
        if ($row['from_code'] !== $currentOutlet):
            $currentOutlet = $row['from_code'];
            $acmmLabel = !empty($row['from_acmm']) ? ' (' . str_pad((int)$row['from_acmm'], 4, '0', STR_PAD_LEFT) . ')' : '';
    ?>
    <tr class="outlet-group-header">
        <td colspan="<?php echo $_tplShowReturn ? 9 : 8; ?>">
            <?php echo htmlspecialchars($row['from_code'] . $acmmLabel); ?>
            &nbsp;&mdash;&nbsp;
            <span style="font-weight:normal;"><?php echo htmlspecialchars($row['from_name']); ?></span>
        </td>
    </tr>
    <?php endif; $rn++; ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rn; ?></td>
        <td class="alpro-mono alpro-center">
            <?php echo htmlspecialchars($row['to_code']); ?>
            <?php if (!empty($row['to_acmm'])): ?>
                <br><span class="alpro-muted" style="font-size:11px;">(<?php echo str_pad((int)$row['to_acmm'], 4, '0', STR_PAD_LEFT); ?>)</span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php if (isset($_itm['model']) && $_itm['model'] === 'Store/HQ Order'): ?>
                <span style="background:#f57c00;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Non-HQ</span>
            <?php else: ?>
                <span style="background:#1976d2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">HQ</span>
            <?php endif; ?>
        </td>
        <td class="alpro-mono"><?php echo htmlspecialchars($row['item_code']); ?></td>
        <td><?php echo htmlspecialchars($_itm['name'] ? $_itm['name'] : '—'); ?></td>
        <td class="alpro-center"><?php echo number_format((float)$row['qty_transfer'], 0); ?></td>
        <td class="alpro-center">
            <?php if ($row['priority_type'] === 'same_area'): ?>
                <span style="color:#388e3c;">Same Area</span>
            <?php elseif ($row['priority_type'] === 'peninsular'): ?>
                <span style="color:#1976d2;">Peninsular</span>
            <?php elseif ($row['priority_type'] === 'sabah'): ?>
                <span style="color:#7b1fa2;">Sabah</span>
            <?php elseif ($row['priority_type'] === 'sarawak'): ?>
                <span style="color:#0288d1;">Sarawak</span>
            <?php elseif ($row['priority_type'] === 'warehouse'): ?>
                <span style="color:#c62828;">Warehouse</span>
            <?php else: ?>
                <span style="color:#888;"><?php echo htmlspecialchars($row['priority_type']); ?></span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php if ($row['status'] === 'pending'): ?>
                <span style="color:#f57c00;">Pending</span>
            <?php elseif ($row['status'] === 'transferred'): ?>
                <span style="color:#388e3c;">Confirmed</span>
            <?php else: ?>
                <span style="color:#c62828;">Cancelled</span>
            <?php endif; ?>
        </td>
        <?php if ($_tplShowReturn): ?>
        <td class="alpro-center">
            <?php if ($row['priority_type'] === 'warehouse'): ?>
                <?php if ((int)$row['return_confirmed'] === 1): ?>
                    <span style="color:#388e3c;font-weight:bold;">&#10003; Confirmed</span>
                <?php else: ?>
                    <span style="color:#c62828;">Not Confirmed</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="alpro-muted">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<!-- PAGINATION -->
<?php
$pgBaseS = 'transferpicklist_view.php?id=' . $id
    . ($filterOutletCode !== '' ? '&search_outlet='    . urlencode($filterOutletCode) : '')
    . ($filterSearch     !== '' ? '&search='           . urlencode($filterSearch)     : '')
    . ($filterSumPri     !== '' ? '&search_priority='  . urlencode($filterSumPri)     : '')
    . ($filterSumStat    !== '' ? '&search_status='    . urlencode($filterSumStat)    : '')
    . ($filterSumType    !== '' ? '&search_type='      . urlencode($filterSumType)    : '')
    . ($filterSumConfirm !== '' ? '&search_confirm='   . urlencode($filterSumConfirm) : '');
?>
<?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } // shared list footer (transferpicklist_pager.php) ?>

<?php else: ?>
<!-- OUTLET DETAIL VIEW -->
<?php
$filterItemCode  = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
$filterPriority  = isset($_GET['priority'])  ? trim($_GET['priority'])  : '';
$filterStatus    = isset($_GET['status'])    ? trim($_GET['status'])    : '';
$filterType      = isset($_GET['type'])      ? trim($_GET['type'])      : '';
$filterConfirm   = ($_tplShowReturn && isset($_GET['confirm'])) ? trim($_GET['confirm']) : '';

$itemPerPage  = tplPerPage(50);
$itemPage     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$itemOffset   = ($itemPage - 1) * $itemPerPage;

$detailWhere = "i.picklist_id = $id AND i.recycle = 0 AND fo.code = '" . $conn->real_escape_string($outletCode) . "'";
if ($filterItemCode !== '') {
    $detailWhere .= " AND i.item_code LIKE '%" . $conn->real_escape_string($filterItemCode) . "%'";
}
if ($filterPriority !== '') {
    $detailWhere .= " AND " . tplRouteWhere($conn, $filterPriority);
}
if ($filterStatus !== '') {
    $detailWhere .= " AND i.status = '" . $conn->real_escape_string($filterStatus) . "'";
}
if ($filterType === 'hq') {
    $detailWhere .= " AND (s.model IS NULL OR s.model != 'Store/HQ Order')";
} elseif ($filterType === 'nonhq') {
    $detailWhere .= " AND s.model = 'Store/HQ Order'";
}
if ($filterConfirm !== '') {
    $detailWhere .= " AND i.priority_type = 'warehouse' AND i.return_confirmed = " . ($filterConfirm === '1' ? '1' : '0');
}

// Exclude maintain from main paginated list
$detailWhereNoMaintain = $detailWhere . " AND i.priority_type != 'maintain'";
$cntRes     = $conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_item i JOIN outlet fo ON fo.id = i.from_outlet_id LEFT JOIN simple s ON s.item_code = i.item_code WHERE $detailWhereNoMaintain");
$totalItems = (int)$cntRes->fetch_assoc()['cnt'];
$itemTotalPages = max(1, ceil($totalItems / $itemPerPage));

// Outlet-level stats (unfiltered — always show full outlet totals, excluding maintain)
$outletBaseWhere = "i.picklist_id = $id AND i.recycle = 0 AND fo.code = '" . $conn->real_escape_string($outletCode) . "' AND i.priority_type != 'maintain'";
$outletStatsRes  = $conn->query("
    SELECT fo.code as from_code, fo.acmm_id as from_acmm, fo.comp_name as from_name,
        COUNT(*) as total,
        SUM(i.status='pending') as pending,
        SUM(i.status='transferred') as transferred,
        SUM(i.status='cancelled') as cancelled
    FROM transferpicklist_item i
    JOIN outlet fo ON fo.id = i.from_outlet_id
    WHERE $outletBaseWhere
    GROUP BY i.from_outlet_id
");
$outletStats = $outletStatsRes ? $outletStatsRes->fetch_assoc() : null;

// Maintain items count
$maintainBaseWhere = "i.picklist_id = $id AND i.recycle = 0 AND fo.code = '" . $conn->real_escape_string($outletCode) . "' AND i.priority_type = 'maintain'";
$maintainCntRes    = $conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_item i JOIN outlet fo ON fo.id = i.from_outlet_id WHERE $maintainBaseWhere");
$maintainCount     = $maintainCntRes ? (int)$maintainCntRes->fetch_assoc()['cnt'] : 0;

$items = $conn->query("
    SELECT i.*,
        fo.code as from_code, fo.comp_name as from_name,
        to2.code as to_code, to2.acmm_id as to_acmm, to2.comp_name as to_name,
        s.model as item_model, s.name as item_desc
    FROM transferpicklist_item i
    JOIN outlet fo  ON fo.id  = i.from_outlet_id
    JOIN outlet to2 ON to2.id = i.to_outlet_id
    LEFT JOIN simple s ON s.item_code = i.item_code
    WHERE $detailWhereNoMaintain
    ORDER BY i.priority_type, i.item_code
    LIMIT $itemPerPage OFFSET $itemOffset
");

// Maintain items (all, no pagination — typically small set, Non-HQ no-demand items)
$maintainItems = $conn->query("
    SELECT i.*,
        fo.code as from_code,
        to2.code as to_code,
        s.model as item_model, s.name as item_desc
    FROM transferpicklist_item i
    JOIN outlet fo  ON fo.id  = i.from_outlet_id
    JOIN outlet to2 ON to2.id = i.to_outlet_id
    LEFT JOIN simple s ON s.item_code = i.item_code
    WHERE $maintainBaseWhere
    ORDER BY i.item_code
");
?>
<?php if ($outletStats): ?>
<div class="alpro-box" style="margin-bottom:12px;">
    <div class="alpro-grid">
        <div class="alpro-field">
            <label class="alpro-muted">Outlet Code</label>
            <div class="alpro-mono" style="font-size:18px;font-weight:bold;">
                <?php echo htmlspecialchars($outletStats['from_code']); ?>
                <?php if (!empty($outletStats['from_acmm'])): ?>
                    <span class="alpro-muted" style="font-size:13px;font-weight:normal;">(<?php echo str_pad((int)$outletStats['from_acmm'], 4, '0', STR_PAD_LEFT); ?>)</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Outlet Name</label>
            <div><?php echo htmlspecialchars($outletStats['from_name']); ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Total Items</label>
            <div style="font-size:20px;font-weight:bold;"><?php echo (int)$outletStats['total']; ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Pending</label>
            <div style="font-size:20px;font-weight:bold;color:#f57c00;"><?php echo (int)$outletStats['pending']; ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Confirmed</label>
            <div style="font-size:20px;font-weight:bold;color:#388e3c;"><?php echo (int)$outletStats['transferred']; ?></div>
        </div>
        <?php if ($maintainCount > 0): ?>
        <div class="alpro-field">
            <label class="alpro-muted">Maintain at Outlet</label>
            <div style="font-size:20px;font-weight:bold;color:#888;"><?php echo $maintainCount; ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<style>
.detail-filter{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;}
.detail-filter table{width:100%;border-collapse:collapse;}
.detail-filter td{padding:0 8px;vertical-align:bottom;}
.detail-filter td:first-child{padding-left:0;}
.detail-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.detail-filter input[type=text],
.detail-filter select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.detail-filter input[type=text]:focus,
.detail-filter select:focus{border-color:#1976d2;outline:none;}
</style>
<form method="get" action="transferpicklist_view.php">
    <input type="hidden" name="id"     value="<?php echo $id; ?>">
    <input type="hidden" name="outlet" value="<?php echo htmlspecialchars($outletCode); ?>">
    <div class="detail-filter">
        <table>
            <tr>
                <td style="width:30%;">
                    <span class="detail-filter-label">Item Code</span>
                    <input type="text" name="item_code" value="<?php echo htmlspecialchars($filterItemCode); ?>" placeholder="e.g. 100001056">
                </td>
                <td style="width:18%;">
                    <span class="detail-filter-label">Type</span>
                    <select name="type">
                        <option value="">-- All Type --</option>
                        <option value="hq"    <?php echo $filterType === 'hq'    ? 'selected' : ''; ?>>HQ</option>
                        <option value="nonhq" <?php echo $filterType === 'nonhq' ? 'selected' : ''; ?>>Non-HQ</option>
                    </select>
                </td>
                <td style="width:18%;">
                    <span class="detail-filter-label">Transfer Route</span>
                    <select name="priority" title="Where Generate sent each item: first to an outlet under the same Area Manager, then to any outlet in the same region; if no outlet needs it, HQ items return to that region's warehouse and Non-HQ items stay at the outlet.">
                        <?php tplRouteOptions($filterPriority, tplPicklistKind($pl['picklist_no']), false); ?>
                    </select>
                </td>
                <td style="width:18%;">
                    <span class="detail-filter-label">Status</span>
                    <select name="status">
                        <option value="">-- All Status --</option>
                        <option value="pending"     <?php echo $filterStatus === 'pending'     ? 'selected' : ''; ?>>Pending</option>
                        <option value="transferred" <?php echo $filterStatus === 'transferred' ? 'selected' : ''; ?>>Confirmed</option>
                        <?php /* <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option> */ ?>
                    </select>
                </td>
                <?php if ($_tplShowReturn): ?>
                <td style="width:18%;">
                    <span class="detail-filter-label">Confirm Return (Warehouse)</span>
                    <select name="confirm">
                        <option value="">-- All --</option>
                        <option value="1" <?php echo $filterConfirm === '1' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="0" <?php echo $filterConfirm === '0' ? 'selected' : ''; ?>>Not Confirmed</option>
                    </select>
                </td>
                <?php endif; ?>
                <td style="width:1%;white-space:nowrap;">
                    <input type="submit" value="Search" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
                    <a href="transferpicklist_view.php?id=<?php echo $id; ?>&outlet=<?php echo urlencode($outletCode); ?>" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 14px;line-height:32px;display:inline-block;">Reset</a>
                    <a href="transferpicklist_view.php?id=<?php echo $id; ?>&outlet=<?php echo urlencode($outletCode); ?>&export=1<?php echo $filterItemCode !== '' ? '&item_code=' . urlencode($filterItemCode) : ''; ?><?php echo $filterType !== '' ? '&type=' . urlencode($filterType) : ''; ?><?php echo $filterPriority !== '' ? '&priority=' . urlencode($filterPriority) : ''; ?><?php echo $filterStatus !== '' ? '&status=' . urlencode($filterStatus) : ''; ?><?php echo $filterConfirm !== '' ? '&confirm=' . urlencode($filterConfirm) : ''; ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 14px;line-height:32px;display:inline-block;">&#8659; Export CSV</a>
                </td>
            </tr>
        </table>
    </div>
</form>
<fieldset>
    <legend><b><?php echo htmlspecialchars($outletCode); ?></b> <span class="alpro-muted" style="font-size:12px;">(<?php echo $totalItems; ?> items)</span></legend>
    <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
            <th width="30">#</th>
            <th width="60">Type</th>
            <th>Item Code</th>
            <th>Qty Transfer</th>
            <th>Transfer To</th>
            <th>Priority</th>
            <th>Status</th>
            <?php if ($_tplShowReturn): ?><th>Confirm Return</th><?php endif; ?>
        </tr>
        <?php if ($items->num_rows === 0): ?>
        <tr><td colspan="<?php echo $_tplShowReturn ? 8 : 7; ?>" class="alpro-center alpro-muted">No items.</td></tr>
        <?php endif; ?>
        <?php $rn = $itemOffset; while ($item = $items->fetch_assoc()): $rn++; ?>
        <tr>
            <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rn; ?></td>
            <td class="alpro-center">
                <?php if (isset($item['item_model']) && $item['item_model'] === 'Store/HQ Order'): ?>
                    <span style="background:#f57c00;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Non-HQ</span>
                <?php else: ?>
                    <span style="background:#1976d2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">HQ</span>
                <?php endif; ?>
            </td>
            <td class="alpro-mono"><?php echo htmlspecialchars($item['item_code']); ?></td>
            <td class="alpro-center"><?php echo number_format((float)$item['qty_transfer'], 0); ?></td>
            <td class="alpro-mono alpro-center">
                <?php echo htmlspecialchars($item['to_code']); ?>
                <?php if (!empty($item['to_acmm'])): ?>
                    <span class="alpro-muted" style="font-size:11px;">(<?php echo str_pad((int)$item['to_acmm'], 4, '0', STR_PAD_LEFT); ?>)</span>
                <?php endif; ?>
            </td>
            <td class="alpro-center">
                <?php if ($item['priority_type'] === 'same_area'): ?>
                    <span style="color:#388e3c;">Same Area</span>
                <?php elseif ($item['priority_type'] === 'peninsular'): ?>
                    <span style="color:#1976d2;">Peninsular</span>
                <?php elseif ($item['priority_type'] === 'sabah'): ?>
                    <span style="color:#7b1fa2;">Sabah</span>
                <?php elseif ($item['priority_type'] === 'sarawak'): ?>
                    <span style="color:#0288d1;">Sarawak</span>
                <?php elseif ($item['priority_type'] === 'warehouse'): ?>
                    <span style="color:#c62828;">Warehouse</span>
                <?php else: ?>
                    <span style="color:#1976d2;">Other Area</span>
                <?php endif; ?>
            </td>
            <td class="alpro-center">
                <?php if ($item['status'] === 'pending'): ?>
                    <span style="color:#f57c00;">Pending</span>
                <?php elseif ($item['status'] === 'transferred'): ?>
                    <span style="color:#388e3c;">Confirmed</span>
                <?php else: ?>
                    <span style="color:#c62828;">Cancelled</span>
                <?php endif; ?>
            </td>
            <?php if ($_tplShowReturn): ?>
            <td class="alpro-center">
                <?php if ($item['priority_type'] === 'warehouse'): ?>
                    <?php if ((int)$item['return_confirmed'] === 1): ?>
                        <span style="color:#388e3c;font-weight:bold;">&#10003; Confirmed</span>
                    <?php else: ?>
                        <span style="color:#c62828;">Not Confirmed</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="alpro-muted">—</span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endwhile; ?>
    </table>
</fieldset>

<!-- ITEM PAGINATION -->
<?php
$pgBaseD = 'transferpicklist_view.php?id=' . $id . '&outlet=' . urlencode($outletCode)
    . ($filterItemCode !== '' ? '&item_code=' . urlencode($filterItemCode) : '')
    . ($filterPriority  !== '' ? '&priority='  . urlencode($filterPriority)  : '')
    . ($filterStatus    !== '' ? '&status='    . urlencode($filterStatus)    : '')
    . ($filterConfirm   !== '' ? '&confirm='   . urlencode($filterConfirm)   : '');
?>
<?php if ($totalItems > 0) { tplPager($itemPage, $itemTotalPages, $totalItems, $itemPerPage); } // shared list footer (transferpicklist_pager.php) ?>

<?php if ($maintainCount > 0): ?>
<div style="margin-top:20px;">
    <div style="background:#757575;color:#fff;padding:8px 12px;border-radius:6px 6px 0 0;font-weight:bold;font-size:13px;">
        &#9679; Maintain at Outlet &mdash; <?php echo $maintainCount; ?> Non-HQ item(s) with no demand (no transfer required)
    </div>
    <fieldset style="border-top:none;border-radius:0 0 6px 6px;margin-bottom:0;border-color:#bbb;">
    <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
            <th width="30">#</th>
            <th width="80">Type</th>
            <th>Item Code</th>
            <th>Item Description</th>
            <th width="60">Qty</th>
        </tr>
        <?php $mn = 1; while ($mr = $maintainItems->fetch_assoc()): ?>
        <tr style="background:#f5f5f5;color:#888;">
            <td class="alpro-center" style="font-size:11px;color:#bbb;"><?php echo $mn++; ?></td>
            <td class="alpro-center">
                <span style="background:#e0e0e0;color:#666;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Non-HQ</span>
            </td>
            <td class="alpro-mono" style="color:#aaa;"><?php echo htmlspecialchars($mr['item_code']); ?></td>
            <td style="color:#999;"><?php echo htmlspecialchars($mr['item_desc'] ? $mr['item_desc'] : '—'); ?></td>
            <td class="alpro-center" style="color:#aaa;"><?php echo number_format((float)$mr['qty_transfer'], 0); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
    </fieldset>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
