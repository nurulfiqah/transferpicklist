<?php
// Campaign Exclusion — the MinMax Campaign list (minmax_campaign), managed from Transfer Pick List.
// ONE shared list: MinMax uses it for its min/max calculation, and Generate Pick List skips every
// ACTIVE campaign (outlet keeps that item's stock — it is never picked to SEND it; it can still
// receive it). Adds / edits / deletes here are written the same way minmax_campaign_list.php
// writes them (same overlap check, same minmax_campaign_log entry), so both modules stay in step.
// Super Admin (staff.transferpicklist = 1) only.
// ── Timing debug: add ?debug=1 to the URL (admins only) to see how long each step takes ──
$_tplT0 = microtime(true); $_tplTimes = array(); $_tplLast = $_tplT0;
function tplT($label) { global $_tplTimes, $_tplLast; $now = microtime(true); $_tplTimes[] = array($label, $now - $_tplLast); $_tplLast = $now; }
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
mysqli_report(MYSQLI_REPORT_OFF);
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }

$_chk = $conn->query("SHOW TABLES LIKE 'minmax_campaign'");
if (!$_chk || $_chk->num_rows === 0) { die('The MinMax campaign table (minmax_campaign) was not found in this database.'); }

$currentUser  = isset($_SESSION['myusername']) ? $_SESSION['myusername'] : 'unknown';
$now          = date('Y-m-d H:i:s');
$remarkLabels = array(1 => 'Display Commitment', 2 => 'Promotion', 3 => 'Project', 4 => 'User Request');

if (empty($_SESSION['tpl_camp_token'])) { $_SESSION['tpl_camp_token'] = bin2hex(random_bytes(16)); }
$_token = $_SESSION['tpl_camp_token'];
// Nothing below writes to $_SESSION — release the session lock so this page never makes the
// user's other pages wait.
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
tplT('Login check + database connect (outer app)');

// Same rule as MinMax: no two records for the same outlet + item with overlapping dates
function tplCampOverlap($conn, $outletCode, $itemCode, $startDate, $endDate, $excludeId = 0) {
    $res = $conn->query("SELECT id FROM minmax_campaign
        WHERE outlet_code = '" . $conn->real_escape_string($outletCode) . "'
          AND item_code   = '" . $conn->real_escape_string($itemCode) . "'
          AND start_date <= '" . $conn->real_escape_string($endDate) . "'
          AND end_date   >= '" . $conn->real_escape_string($startDate) . "'"
        . ($excludeId ? " AND id != " . (int)$excludeId : '') . " LIMIT 1");
    return $res && $res->num_rows > 0;
}
function tplCampLog($conn, $id, $action, $snapshot, $user, $now) {
    $conn->query("INSERT INTO minmax_campaign_log (campaign_id, action, snapshot, changed_by, changed_at) VALUES ("
        . (int)$id . ", '" . $conn->real_escape_string($action) . "', '" . $conn->real_escape_string(json_encode($snapshot)) . "', '"
        . $conn->real_escape_string($user) . "', '$now')");
}
function tplCampDate($s) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false; }

$msg = ''; $msg_type = '';
$formError = '';
$keepQs = http_build_query(array_filter(array(
    'outlet' => isset($_GET['outlet']) ? $_GET['outlet'] : '', 'item_code' => isset($_GET['item_code']) ? $_GET['item_code'] : '',
    'status' => isset($_GET['status']) ? $_GET['status'] : '', 'remark' => isset($_GET['remark']) ? $_GET['remark'] : '',
    'page'   => isset($_GET['page']) ? $_GET['page'] : '',     'per_page' => isset($_GET['per_page']) ? $_GET['per_page'] : '',
), 'strlen'));
$selfUrl = 'transferpicklist_campaign.php' . ($keepQs !== '' ? '?' . $keepQs : '');

// ============================================================
// ADD / EDIT / DELETE
// ============================================================
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($action !== '' && !hash_equals($_token, isset($_POST['token']) ? (string)$_POST['token'] : '')) {
    $msg = 'Your session expired. Please try again.'; $msg_type = 'error'; $action = '';
}

if ($action === 'add') {
    $outletCode = strtoupper(trim(isset($_POST['outlet_code']) ? $_POST['outlet_code'] : ''));
    $itemCode   = trim(isset($_POST['item_code']) ? $_POST['item_code'] : '');
    if (strtoupper($itemCode) === 'ALL') { $itemCode = 'ALL'; }
    $startDate  = trim(isset($_POST['start_date']) ? $_POST['start_date'] : '');
    $endDate    = !empty($_POST['no_expiry']) ? '9999-12-31' : trim(isset($_POST['end_date']) ? $_POST['end_date'] : '');
    $remark     = isset($_POST['remark']) ? (int)$_POST['remark'] : 0;
    $outletId   = 0;
    if ($outletCode !== '' && $outletCode !== 'ALL') {
        $oRes = $conn->query("SELECT id FROM outlet WHERE code = '" . $conn->real_escape_string($outletCode) . "' AND recycle = 0 LIMIT 1");
        $oRow = $oRes ? $oRes->fetch_assoc() : null;
        $outletId = $oRow ? (int)$oRow['id'] : -1;
    }
    if ($outletCode === '' || $itemCode === '' || $startDate === '' || $endDate === '') { $formError = 'All fields are required.'; }
    elseif (!tplCampDate($startDate) || !tplCampDate($endDate))     { $formError = 'Dates must be in YYYY-MM-DD format.'; }
    elseif ($endDate < $startDate)                                    { $formError = 'End Date cannot be before Start Date.'; }
    elseif (!isset($remarkLabels[$remark]))                           { $formError = 'Please select a valid Remark.'; }
    elseif ($outletId === -1)                                         { $formError = "Unknown or inactive Outlet Code '" . htmlspecialchars($outletCode) . "'. Use ALL for all outlets."; }
    elseif ($outletCode === 'ALL' && $itemCode === 'ALL')             { $formError = 'ALL outlets + ALL items would stop every transfer. Please choose an outlet or an item.'; }
    elseif (tplCampOverlap($conn, $outletCode, $itemCode, $startDate, $endDate)) {
        $formError = "A campaign already exists for Outlet '" . htmlspecialchars($outletCode) . "' + Item '" . htmlspecialchars($itemCode) . "' with overlapping dates.";
    } else {
        $ok = $conn->query("INSERT INTO minmax_campaign (outlet_code, outlet_id, item_code, start_date, end_date, remark, created_by, created_at) VALUES ('"
            . $conn->real_escape_string($outletCode) . "', $outletId, '" . $conn->real_escape_string($itemCode) . "', '"
            . $conn->real_escape_string($startDate) . "', '" . $conn->real_escape_string($endDate) . "', $remark, '"
            . $conn->real_escape_string($currentUser) . "', '$now')");
        if ($ok) {
            tplCampLog($conn, $conn->insert_id, 'add', array('outlet_code' => $outletCode, 'item_code' => $itemCode,
                'start_date' => $startDate, 'end_date' => $endDate, 'remark' => $remark, 'source' => 'transferpicklist'), $currentUser, $now);
            header('Location: ' . $selfUrl . ($keepQs !== '' ? '&' : '?') . 'done=added'); exit;
        }
        $formError = 'Save failed: ' . htmlspecialchars($conn->error);
    }
}

if ($action === 'edit') {
    $id        = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $startDate = trim(isset($_POST['start_date']) ? $_POST['start_date'] : '');
    $endDate   = !empty($_POST['no_expiry']) ? '9999-12-31' : trim(isset($_POST['end_date']) ? $_POST['end_date'] : '');
    $remark    = isset($_POST['remark']) ? (int)$_POST['remark'] : 0;
    $rowRes    = $conn->query("SELECT * FROM minmax_campaign WHERE id = $id");
    $old       = $rowRes ? $rowRes->fetch_assoc() : null;
    if (!$old)                                                    { $msg = 'Campaign not found.'; $msg_type = 'error'; }
    elseif (!tplCampDate($startDate) || !tplCampDate($endDate))   { $msg = 'Dates must be in YYYY-MM-DD format.'; $msg_type = 'error'; }
    elseif ($endDate < $startDate)                                { $msg = 'End Date cannot be before Start Date.'; $msg_type = 'error'; }
    elseif (!isset($remarkLabels[$remark]))                       { $msg = 'Please select a valid Remark.'; $msg_type = 'error'; }
    elseif (tplCampOverlap($conn, $old['outlet_code'], $old['item_code'], $startDate, $endDate, $id)) {
        $msg = 'Another campaign for this outlet + item already covers those dates.'; $msg_type = 'error';
    } else {
        $conn->query("UPDATE minmax_campaign SET start_date = '" . $conn->real_escape_string($startDate) . "', end_date = '"
            . $conn->real_escape_string($endDate) . "', remark = $remark, updated_by = '" . $conn->real_escape_string($currentUser)
            . "', updated_at = '$now' WHERE id = $id");
        tplCampLog($conn, $id, 'edit', array('start_date' => $startDate, 'end_date' => $endDate, 'remark' => $remark, 'source' => 'transferpicklist'), $currentUser, $now);
        header('Location: ' . $selfUrl . ($keepQs !== '' ? '&' : '?') . 'done=edited'); exit;
    }
}

if ($action === 'delete' || $action === 'bulk_delete') {
    $ids = $action === 'delete' ? array((int)(isset($_POST['id']) ? $_POST['id'] : 0)) : array_map('intval', (array)(isset($_POST['ids']) ? $_POST['ids'] : array()));
    $n = 0;
    foreach (array_unique(array_filter($ids)) as $id) {
        $rowRes = $conn->query("SELECT * FROM minmax_campaign WHERE id = $id");
        if ($rowRes && $row = $rowRes->fetch_assoc()) {
            tplCampLog($conn, $id, 'delete', $row + array('source' => 'transferpicklist'), $currentUser, $now);
            $conn->query("DELETE FROM minmax_campaign WHERE id = $id");
            $n++;
        }
    }
    header('Location: ' . $selfUrl . ($keepQs !== '' ? '&' : '?') . 'done=deleted&n=' . $n); exit;
}

if (isset($_GET['done'])) {
    $msg_type = 'success';
    if ($_GET['done'] === 'added')   { $msg = 'Campaign added. It applies to the next pick list generated (and to MinMax).'; }
    if ($_GET['done'] === 'edited')  { $msg = 'Campaign updated.'; }
    if ($_GET['done'] === 'deleted') { $msg = (int)(isset($_GET['n']) ? $_GET['n'] : 0) . ' campaign(s) deleted.'; }
}

// ============================================================
// LIST
// ============================================================
$filterOutlet = isset($_GET['outlet'])    ? strtoupper(trim($_GET['outlet'])) : '';
$filterItem   = isset($_GET['item_code']) ? trim($_GET['item_code'])         : '';
$filterStatus = isset($_GET['status'])    ? trim($_GET['status'])            : '';
$filterRemark = isset($_GET['remark'])    ? (int)$_GET['remark']             : 0;
$editId       = isset($_GET['edit'])      ? (int)$_GET['edit']               : 0;

$where = '1=1';
if ($filterOutlet !== '')         { $where .= " AND outlet_code = '" . $conn->real_escape_string($filterOutlet) . "'"; }
if ($filterItem   !== '')         { $where .= " AND item_code LIKE '%" . $conn->real_escape_string($filterItem) . "%'"; }
if ($filterStatus === 'active')   { $where .= " AND CURDATE() BETWEEN start_date AND end_date"; }
if ($filterStatus === 'upcoming') { $where .= " AND start_date > CURDATE()"; }
if ($filterStatus === 'expired')  { $where .= " AND end_date < CURDATE()"; }
if ($filterRemark > 0)            { $where .= " AND remark = $filterRemark"; }

$stats = array('active' => 0, 'upcoming' => 0, 'expired' => 0);
$sRes  = $conn->query("SELECT SUM(CURDATE() BETWEEN start_date AND end_date) a, SUM(start_date > CURDATE()) u, SUM(end_date < CURDATE()) e FROM minmax_campaign");
if ($sRes && $s = $sRes->fetch_assoc()) { $stats = array('active' => (int)$s['a'], 'upcoming' => (int)$s['u'], 'expired' => (int)$s['e']); }
tplT('Stats (active / upcoming / expired)');

$perPage    = tplPerPage(50);
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$cRes       = $conn->query("SELECT COUNT(*) c FROM minmax_campaign WHERE $where");
$totalItems = $cRes ? (int)$cRes->fetch_assoc()['c'] : 0;
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = array();
$res  = $conn->query("SELECT * FROM minmax_campaign WHERE $where ORDER BY start_date DESC, outlet_code, item_code LIMIT $perPage OFFSET $offset");
while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; }

// Staff names for this page's creators. Looked up separately with string literals instead of
// JOINing staff: staff.username is latin1 and created_by is utf8mb4, so a JOIN can't use the
// username index and scans the whole staff table once per row.
$staffNames = array(); $users = array();
foreach ($rows as $r) { if ((string)$r['created_by'] !== '') { $users[$r['created_by']] = "'" . $conn->real_escape_string($r['created_by']) . "'"; } }
if ($users) {
    $uRes = $conn->query("SELECT username, nama_staff FROM staff WHERE username IN (" . implode(',', $users) . ") AND recycle != 1");
    while ($uRes && $u = $uRes->fetch_assoc()) { $staffNames[$u['username']] = $u['nama_staff']; }
}
tplT('Count + load rows + staff names');

// Descriptions for this page's items only — as string literals so simple's (latin1) index is used
$descMap = array(); $codes = array();
foreach ($rows as $r) { if ($r['item_code'] !== 'ALL') { $codes[$r['item_code']] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
if ($codes) {
    $dRes = $conn->query("SELECT item_code, name FROM simple WHERE item_code IN (" . implode(',', $codes) . ")");
    while ($dRes && $d = $dRes->fetch_assoc()) { $descMap[$d['item_code']] = $d['name']; }
}
tplT('Item descriptions');

// Outlet filter dropdown (outlets that have campaigns) + Add form outlet list
$campOutlets = array();
$res = $conn->query("SELECT DISTINCT outlet_code FROM minmax_campaign ORDER BY outlet_code");
while ($res && $r = $res->fetch_assoc()) { $campOutlets[] = $r['outlet_code']; }
$today = date('Y-m-d');
function tplCampStatus($r, $today) {
    if ($today < $r['start_date']) { return array('Upcoming', 'tpl-cp-up'); }
    if ($today > $r['end_date'])   { return array('Expired', 'tpl-cp-exp'); }
    return array('Active', 'tpl-cp-act');
}
$showAdd = isset($_GET['add']) || $formError !== '';

// Every active outlet code — only needed for the Add form's suggestion list
$allOutlets = array();
if ($showAdd) {
    $res = $conn->query("SELECT code FROM outlet WHERE recycle = 0 ORDER BY code");
    while ($res && $r = $res->fetch_assoc()) { $allOutlets[] = $r['code']; }
}
tplT('Outlet dropdowns');
?>
<!DOCTYPE html>
<html>
<head>
<title>Campaign Exclusion</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.tpl-cp-title{display:flex;align-items:center;gap:8px;font-size:16px;font-weight:bold;color:#222;padding-bottom:10px;border-bottom:1px solid #e5e7eb;margin-bottom:10px;}
.tpl-cp-title small{font-size:12px;font-weight:normal;color:#888;}
.tpl-cp-stats{display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;}
.tpl-cp-stat{flex:1 1 140px;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;text-decoration:none;color:#222;background:#fff;}
.tpl-cp-stat b{display:block;font-size:22px;}
.tpl-cp-stat span{font-size:12px;color:#888;}
.tpl-cp-stat.on{border-color:#1976d2;box-shadow:0 0 0 1px #1976d2 inset;}
.tpl-cp-act{background:#e8f5e9;color:#2e7d32;}
.tpl-cp-up{background:#e3f2fd;color:#1565c0;}
.tpl-cp-exp{background:#f5f5f5;color:#888;}
.tpl-cp-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;}
.tpl-cp-form{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;}
.tpl-cp-form label{display:block;font-size:11px;color:#888;margin-bottom:3px;}
.tpl-cp-form .f{flex:1 1 150px;}
.tpl-cp-form input[type=text],.tpl-cp-form input[type=date],.tpl-cp-form select{width:100%;height:28px;padding:0 6px;border:1px solid #ccc;border-radius:4px;font-size:12px;box-sizing:border-box;}
.tpl-cp-form .btn{flex:0 0 auto;height:28px;padding:0 14px;line-height:28px;}
.tpl-cp-icon{display:inline-flex;align-items:center;justify-content:center;width:26px;height:22px;border-radius:5px;border:none;background:none;cursor:pointer;text-decoration:none;vertical-align:middle;padding:0;}
.tpl-cp-edit{color:#1976d2;} .tpl-cp-edit:hover{background:#e3f2fd;}
.tpl-cp-del{color:#c62828;}  .tpl-cp-del:hover{background:#ffebee;}
.tpl-cp-src{font-size:10px;color:#888;}
.tpl-cp-inline input[type=date],.tpl-cp-inline select{height:24px;font-size:12px;}
.myTable td,.myTable th{text-align:left;}
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Campaign Exclusion</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<?php if ($msg): ?><div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div><?php endif; ?>

<div class="alpro-box">
    <div class="tpl-cp-title">&#127991; MinMax Campaigns <small>— same list as MinMax. Active campaigns are not sent out when a pick list is generated (the outlet can still receive them).</small></div>

    <div class="tpl-cp-stats">
        <?php foreach (array('active' => 'Active', 'upcoming' => 'Upcoming', 'expired' => 'Expired') as $_k => $_l): ?>
        <a class="tpl-cp-stat <?php echo $filterStatus === $_k ? 'on' : ''; ?>" href="transferpicklist_campaign.php?status=<?php echo $_k; ?>">
            <b><?php echo number_format($stats[$_k]); ?></b><span><?php echo $_l; ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="transferpicklist_campaign.php" class="tpl-cp-form tpl-filter-bar" style="margin-bottom:10px;">
        <div class="f"><label>Outlet</label>
            <select name="outlet">
                <option value="">-- All --</option>
                <?php foreach ($campOutlets as $_o): ?>
                <option value="<?php echo htmlspecialchars($_o); ?>" <?php echo $filterOutlet === $_o ? 'selected' : ''; ?>><?php echo htmlspecialchars($_o); ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="f"><label>Item Code</label><input type="text" name="item_code" value="<?php echo htmlspecialchars($filterItem); ?>" placeholder="Item code"></div>
        <div class="f"><label>Status</label>
            <select name="status">
                <option value="">-- All --</option>
                <?php foreach (array('active' => 'Active', 'upcoming' => 'Upcoming', 'expired' => 'Expired') as $_k => $_l): ?>
                <option value="<?php echo $_k; ?>" <?php echo $filterStatus === $_k ? 'selected' : ''; ?>><?php echo $_l; ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="f"><label>Remark</label>
            <select name="remark">
                <option value="">-- All --</option>
                <?php foreach ($remarkLabels as $_k => $_l): ?>
                <option value="<?php echo $_k; ?>" <?php echo $filterRemark === $_k ? 'selected' : ''; ?>><?php echo $_k . ' - ' . $_l; ?></option>
                <?php endforeach; ?>
            </select></div>
        <input type="submit" value="Search" class="alpro-btn alpro-btn-blue btn">
        <a href="transferpicklist_campaign.php" class="alpro-btn alpro-btn-grey btn">Clear</a>
        <a href="<?php echo htmlspecialchars($selfUrl . ($keepQs !== '' ? '&' : '?') . 'add=1'); ?>" class="alpro-btn alpro-btn-green btn">+ Add Campaign</a>
    </form>

    <?php if ($showAdd): ?>
    <div style="border:1px solid #c8e6c9;background:#f6fbf6;border-radius:6px;padding:10px;margin-bottom:10px;">
        <?php if ($formError): ?><div class="alpro-alert alpro-error" style="margin-bottom:8px;"><?php echo $formError; ?></div><?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($selfUrl); ?>" class="tpl-cp-form">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($_token); ?>">
            <input type="hidden" name="action" value="add">
            <div class="f"><label>Outlet Code (or ALL)</label>
                <input type="text" name="outlet_code" list="tplCpOutlets" required value="<?php echo htmlspecialchars(isset($_POST['outlet_code']) ? $_POST['outlet_code'] : ''); ?>" placeholder="e.g. QKC3M1 or ALL"></div>
            <div class="f"><label>Item Code (or ALL)</label>
                <input type="text" name="item_code" required value="<?php echo htmlspecialchars(isset($_POST['item_code']) ? $_POST['item_code'] : ''); ?>" placeholder="e.g. 100005719 or ALL"></div>
            <div class="f"><label>Start Date</label>
                <input type="date" name="start_date" required value="<?php echo htmlspecialchars(isset($_POST['start_date']) ? $_POST['start_date'] : $today); ?>"></div>
            <div class="f"><label>End Date</label>
                <input type="date" name="end_date" id="tplCpEnd" value="<?php echo htmlspecialchars(isset($_POST['end_date']) ? $_POST['end_date'] : ''); ?>" <?php echo !empty($_POST['no_expiry']) ? 'disabled' : ''; ?>></div>
            <div class="f" style="flex:0 0 auto;"><label>&nbsp;</label>
                <label style="font-size:12px;color:#333;margin:0;line-height:28px;"><input type="checkbox" name="no_expiry" value="1" <?php echo !empty($_POST['no_expiry']) ? 'checked' : ''; ?>
                    onchange="document.getElementById('tplCpEnd').disabled=this.checked;"> No expiry</label></div>
            <div class="f"><label>Remark</label>
                <select name="remark" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($remarkLabels as $_k => $_l): ?>
                    <option value="<?php echo $_k; ?>" <?php echo (isset($_POST['remark']) && (int)$_POST['remark'] === $_k) ? 'selected' : ''; ?>><?php echo $_k . ' - ' . $_l; ?></option>
                    <?php endforeach; ?>
                </select></div>
            <input type="submit" value="Save" class="alpro-btn alpro-btn-green btn">
            <a href="<?php echo htmlspecialchars($selfUrl); ?>" class="alpro-btn alpro-btn-grey btn">Cancel</a>
        </form>
        <datalist id="tplCpOutlets"><option value="ALL"><?php foreach ($allOutlets as $_o): ?><option value="<?php echo htmlspecialchars($_o); ?>"><?php endforeach; ?></datalist>
    </div>
    <?php endif; ?>

    <form method="post" action="<?php echo htmlspecialchars($selfUrl); ?>" id="tplCpBulk"
          onsubmit="var n=document.querySelectorAll('.tpl-cp-chk:checked').length; if(!n){alert('Tick at least one campaign.');return false;} return confirm('Delete '+n+' campaign(s)?\n\nThey are removed from MinMax too.');">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_token); ?>">
        <input type="hidden" name="action" value="bulk_delete">
    </form>

    <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
            <th width="24"><input type="checkbox" onclick="document.querySelectorAll('.tpl-cp-chk').forEach(function(c){c.checked=this.checked;}, this);"></th>
            <th width="36">No.</th><th>Outlet</th><th>Item Code</th><th>Description</th><th>Start</th><th>End</th><th>Remark</th><th>Status</th><th>Created By</th><th width="70">Action</th>
        </tr>
        <?php if (empty($rows)): ?>
        <tr><td colspan="11" class="alpro-muted">No campaigns found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r):
            list($_st, $_cls) = tplCampStatus($r, $today);
            $_perm  = $r['end_date'] === '9999-12-31';
            // Rows copied in from a Pick List exclusion page (tags: ../minmax/minmax_campaign_sync.php)
            $_tplSrc = array(
                'sync_transferpicklist_excluded_items'    => 'Pick List – Excluded Item (Global)',
                'migrate_transferpicklist_excluded_items' => 'Pick List – Excluded Item (Global)',
                'sync_transferpicklist_excluded'          => 'Pick List – Excluded Item (Per Outlet)',
                'migrate_transferpicklist_excluded'       => 'Pick List – Excluded Item (Per Outlet)',
            );
            $_by    = isset($_tplSrc[(string)$r['created_by']]) ? $_tplSrc[(string)$r['created_by']]
                    : (isset($staffNames[$r['created_by']]) ? $staffNames[$r['created_by']] : $r['created_by']);
        ?>
        <tr>
            <td><input type="checkbox" class="tpl-cp-chk" name="ids[]" value="<?php echo (int)$r['id']; ?>" form="tplCpBulk"></td>
            <td><?php echo $offset + $i + 1; ?></td>
            <td><b><?php echo htmlspecialchars($r['outlet_code']); ?></b></td>
            <td class="alpro-mono"><?php echo htmlspecialchars($r['item_code']); ?></td>
            <td><?php echo $r['item_code'] === 'ALL' ? '<span class="alpro-muted">All items</span>' : htmlspecialchars(isset($descMap[$r['item_code']]) ? $descMap[$r['item_code']] : ''); ?></td>
            <?php if ($editId === (int)$r['id']): ?>
            <td colspan="3" class="tpl-cp-inline">
                <form method="post" action="<?php echo htmlspecialchars($selfUrl); ?>" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_token); ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($r['start_date']); ?>" required>
                    <input type="date" name="end_date" id="tplCpEditEnd" value="<?php echo $_perm ? '' : htmlspecialchars($r['end_date']); ?>" <?php echo $_perm ? 'disabled' : ''; ?>>
                    <label style="font-size:11px;"><input type="checkbox" name="no_expiry" value="1" <?php echo $_perm ? 'checked' : ''; ?> onchange="document.getElementById('tplCpEditEnd').disabled=this.checked;"> No expiry</label>
                    <select name="remark">
                        <?php foreach ($remarkLabels as $_k => $_l): ?>
                        <option value="<?php echo $_k; ?>" <?php echo (int)$r['remark'] === $_k ? 'selected' : ''; ?>><?php echo $_k . ' - ' . $_l; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="alpro-btn alpro-btn-green" style="height:24px;padding:0 10px;font-size:12px;">Save</button>
                    <a href="<?php echo htmlspecialchars($selfUrl); ?>" class="alpro-btn alpro-btn-grey" style="height:24px;line-height:24px;padding:0 10px;font-size:12px;">Cancel</a>
                </form>
            </td>
            <?php else: ?>
            <td><?php echo htmlspecialchars($r['start_date']); ?></td>
            <td><?php echo $_perm ? '<span class="alpro-muted">No expiry</span>' : htmlspecialchars($r['end_date']); ?></td>
            <td><?php echo (int)$r['remark'] . ' - ' . htmlspecialchars(isset($remarkLabels[(int)$r['remark']]) ? $remarkLabels[(int)$r['remark']] : '?'); ?></td>
            <?php endif; ?>
            <td><span class="tpl-cp-pill <?php echo $_cls; ?>"><?php echo $_st; ?></span></td>
            <td><?php echo htmlspecialchars((string)$_by); ?><div class="tpl-cp-src"><?php echo htmlspecialchars(date('d M Y', strtotime($r['created_at']))); ?></div></td>
            <td style="text-align:center;white-space:nowrap;">
                <a class="tpl-cp-icon tpl-cp-edit" title="Edit dates / remark" href="<?php echo htmlspecialchars($selfUrl . ($keepQs !== '' ? '&' : '?') . 'edit=' . (int)$r['id']); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></a>
                <form method="post" action="<?php echo htmlspecialchars($selfUrl); ?>" style="display:inline;" onsubmit="return confirm('Delete this campaign?\n\nIt is removed from MinMax too.');">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_token); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="tpl-cp-icon tpl-cp-del" title="Delete"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (!empty($rows)): ?>
    <div style="margin-top:6px;text-align:left;"><button type="submit" form="tplCpBulk" class="alpro-btn alpro-btn-grey" style="height:26px;padding:0 12px;font-size:12px;color:#c62828;">Delete selected</button></div>
    <?php endif; ?>
    <?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } ?>
</div>

<?php
tplT('Build page HTML');
if (isset($_GET['debug'])):
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

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
