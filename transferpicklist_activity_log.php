<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
mysqli_report(MYSQLI_REPORT_OFF);
// Admin (status 1) and super admin (status 3)
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }

$filterPicklist = isset($_GET['picklist_no']) ? trim($_GET['picklist_no']) : '';
$filterUser     = isset($_GET['user'])        ? trim($_GET['user'])        : '';
$filterSearch   = isset($_GET['search'])      ? trim($_GET['search'])      : '';
$page           = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage        = tplPerPage(50);

// Build WHERE clause — Warehouse Returns only: every action on transferpicklist_warehouse.php
// (confirm ticks, deletes, warehouse changes, Confirm All) is logged against the
// "<base>-warehouse" pick list, so only those pick lists are shown here.
$where  = array("p.picklist_no LIKE '%-warehouse'");
$types  = '';
$params = array();

if ($filterPicklist !== '') {
    $where[]  = 'p.picklist_no = ?';
    $types   .= 's';
    $params[] = $filterPicklist;
}
if ($filterUser !== '') {
    $where[]  = 'l.by_user = ?';
    $types   .= 's';
    $params[] = $filterUser;
}
if ($filterSearch !== '') {
    $where[]  = 'l.action LIKE ?';
    $types   .= 's';
    $params[] = '%' . $filterSearch . '%';
}
$whereSql = implode(' AND ', $where);

// Total count
$countSql = "SELECT COUNT(*) as total FROM transferpicklist_log l JOIN transferpicklist_picklist p ON p.id = l.picklist_id WHERE $whereSql";
$stmt = $conn->prepare($countSql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Fetch rows
$sql = "
    SELECT l.action, l.by_user, l.timestamp, p.picklist_no, p.status as picklist_status
    FROM transferpicklist_log l
    JOIN transferpicklist_picklist p ON p.id = l.picklist_id
    WHERE $whereSql
    ORDER BY l.timestamp DESC, l.id DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res  = $stmt->get_result();
$rows = array();
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

// User dropdown — only staff who actually have Warehouse Returns activity
// (narrowed to the selected pick list when one is chosen)
$userOptions = array();
$_uSql = "SELECT DISTINCT l.by_user FROM transferpicklist_log l JOIN transferpicklist_picklist p ON p.id = l.picklist_id
          WHERE p.picklist_no LIKE '%-warehouse' AND l.by_user IS NOT NULL AND l.by_user != ''"
       . ($filterPicklist !== '' ? " AND p.picklist_no = '" . $conn->real_escape_string($filterPicklist) . "'" : "")
       . " ORDER BY l.by_user";
$_uRes = $conn->query($_uSql);
if ($_uRes) { while ($r = $_uRes->fetch_assoc()) { $userOptions[] = $r['by_user']; } }
if ($filterUser !== '' && !in_array($filterUser, $userOptions, true)) { $userOptions[] = $filterUser; sort($userOptions); }

// Pick list options for filter dropdown
$picklistOptions = array();
$plRes = $conn->query("SELECT p.picklist_no FROM transferpicklist_picklist p WHERE p.picklist_no LIKE '%-warehouse' ORDER BY p.timestamp DESC LIMIT 200");
if ($plRes) { while ($r = $plRes->fetch_assoc()) { $picklistOptions[] = $r['picklist_no']; } }

function buildLogUrl($params) {
    return 'transferpicklist_activity_log.php?' . http_build_query(array_filter($params, function($v) { return $v !== '' && $v !== null; }));
}
$currentParams = array(
    'picklist_no' => $filterPicklist,
    'user'        => $filterUser,
    'search'      => $filterSearch,
);
?>
<!DOCTYPE html>
<html>
<head>
<title>Warehouse Returns – Activity Log</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
    .filter-bar { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px; background:#fff; border:1px solid #ddd; border-radius:6px; padding:12px 14px; }
    .filter-bar label { display:block; font-size:11px; color:#888; margin-bottom:3px; }
    .filter-bar select, .filter-bar input[type=text] { padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; height:32px; box-sizing:border-box; }
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Warehouse Returns – Activity Log</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">
    <form method="get" action="transferpicklist_activity_log.php">
        <div class="filter-bar">
            <div style="flex:1 1 0; min-width:200px;">
                <label>Pick List</label>
                <select name="picklist_no" style="width:100%;">
                    <option value="">-- All Pick Lists --</option>
                    <?php foreach ($picklistOptions as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $filterPicklist === $opt ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($opt); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1 1 0; min-width:160px;">
                <label>User</label>
                <select name="user" style="width:100%;">
                    <option value="">-- All Staff --</option>
                    <?php foreach ($userOptions as $_u): ?>
                    <option value="<?php echo htmlspecialchars($_u); ?>" <?php echo $filterUser === $_u ? 'selected' : ''; ?>><?php echo htmlspecialchars($_u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:2 1 0; min-width:200px;">
                <label>Search Action</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="e.g. deleted, warehouse..." style="width:100%;">
            </div>
            <div style="display:flex; gap:8px; flex:0 0 auto;">
                <button type="submit" class="alpro-btn alpro-btn-blue" style="height:32px; min-width:80px; padding:0 16px; box-sizing:border-box; display:inline-flex; align-items:center; justify-content:center;">Filter</button>
                <a href="transferpicklist_activity_log.php" class="alpro-btn alpro-btn-grey" style="height:32px; min-width:80px; padding:0 16px; box-sizing:border-box; display:inline-flex; align-items:center; justify-content:center;">Reset</a>
            </div>
        </div>
    </form>

    <div class="alpro-muted" style="margin-bottom:8px; font-size:12px;"><?php echo number_format($total); ?> log entr<?php echo $total === 1 ? 'y' : 'ies'; ?> found.</div>

    <style>
    .activity-log-tbl th, .activity-log-tbl td { text-align: left; }
    </style>
    <table class="myTable alpro-table activity-log-tbl" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
            <th width="150">When</th>
            <th width="150">By</th>
            <th width="220">Pick List</th>
            <th>Action</th>
        </tr>
        <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="alpro-center alpro-muted">No activity recorded.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td class="alpro-mono" style="white-space:nowrap;"><?php echo htmlspecialchars($row['timestamp']); ?></td>
            <td><b><?php echo htmlspecialchars($row['by_user']); ?></b></td>
            <td class="alpro-mono"><?php echo htmlspecialchars($row['picklist_no']); ?></td>
            <td style="max-width:600px; word-break:break-word; white-space:normal;"><?php echo htmlspecialchars($row['action']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($total > 0) { tplPager($page, $totalPages, $total, $perPage); } // shared list footer (transferpicklist_pager.php) ?>
</div>

</body>
</html>
