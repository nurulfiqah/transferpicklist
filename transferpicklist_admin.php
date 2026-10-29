<?php
// Admin Settings — grant / remove Transfer Pick List Super Admin (staff.transferpicklist = 1).
// Super Admin only. Every change is written to transferpicklist_admin_log
// (see database/migrations/2026-10-29_admin_log.sql — also created here if missing).
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }

$conn->query("CREATE TABLE IF NOT EXISTS transferpicklist_admin_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT          NOT NULL,
    staff_name  VARCHAR(150) NULL,
    action      VARCHAR(10)  NOT NULL,
    by_user     VARCHAR(100) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// The logged-in staff's own id (to stop them removing themselves)
$_meId = 0;
$_meUser = isset($_SESSION['myusername']) ? $_SESSION['myusername'] : '';
if ($_meUser !== '') {
    $st = $conn->prepare("SELECT id FROM staff WHERE username = ? AND recycle != 1 LIMIT 1");
    $st->bind_param('s', $_meUser);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    if ($r) { $_meId = (int)$r['id']; }
}
$_byName = (isset($nama_staff) && $nama_staff !== '') ? $nama_staff : $_meUser;

if (empty($_SESSION['tpl_admin_token'])) { $_SESSION['tpl_admin_token'] = bin2hex(random_bytes(16)); }
$_token = $_SESSION['tpl_admin_token'];

$msg = ''; $msg_type = '';
$search    = isset($_GET['q'])     ? trim($_GET['q'])     : '';
$fDept     = isset($_GET['dept'])  ? (int)$_GET['dept']  : 0;
// Query string that keeps the current search/filters (used by the add/remove forms and redirects)
$_keepQs   = http_build_query(array_filter(array('q' => $search, 'dept' => $fDept ? $fDept : ''), 'strlen'));

// ── Grant / remove ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_admin'], $_POST['staff_id'])) {
    $sid   = (int)$_POST['staff_id'];
    $grant = $_POST['set_admin'] === '1';
    if (!hash_equals($_token, isset($_POST['token']) ? (string)$_POST['token'] : '')) {
        $msg = 'Your session expired. Please try again.'; $msg_type = 'error';
    } elseif (!$grant && $sid === $_meId) {
        $msg = 'You cannot remove your own Super Admin access.'; $msg_type = 'error';
    } else {
        $row = $conn->query("SELECT id, nama_staff, username, transferpicklist FROM staff WHERE id = $sid AND recycle != 1 LIMIT 1")->fetch_assoc();
        $adminCount = (int)$conn->query("SELECT COUNT(*) c FROM staff WHERE transferpicklist = 1 AND recycle != 1")->fetch_assoc()['c'];
        if (!$row) {
            $msg = 'Staff not found.'; $msg_type = 'error';
        } elseif (!$grant && $adminCount <= 1) {
            $msg = 'There must be at least one Super Admin.'; $msg_type = 'error';
        } elseif (((int)$row['transferpicklist'] === 1) === $grant) {
            $msg = htmlspecialchars($row['nama_staff']) . ($grant ? ' is already a Super Admin.' : ' is not a Super Admin.'); $msg_type = 'warn';
        } else {
            $conn->query("UPDATE staff SET transferpicklist = " . ($grant ? 1 : 0) . " WHERE id = $sid LIMIT 1");
            $conn->query("INSERT INTO transferpicklist_admin_log (staff_id, staff_name, action, by_user, created_at) VALUES ($sid, '"
                . $conn->real_escape_string($row['nama_staff']) . "', '" . ($grant ? 'grant' : 'remove') . "', '"
                . $conn->real_escape_string($_byName) . "', '" . date('Y-m-d H:i:s') . "')");
            header('Location: transferpicklist_admin.php?done=' . ($grant ? 'grant' : 'remove') . '&n=' . urlencode($row['nama_staff'])
                . ($_keepQs !== '' ? '&' . $_keepQs : ''));
            exit;
        }
    }
}
if (isset($_GET['done'], $_GET['n'])) {
    $msg = '<b>' . htmlspecialchars($_GET['n']) . '</b> ' . ($_GET['done'] === 'grant' ? 'is now a Super Admin.' : 'is no longer a Super Admin.');
    $msg_type = 'success';
}

// ── Current Super Admins ──
$admins = array();
$res = $conn->query("SELECT s.id, s.nama_staff, s.username, s.status_semasa, d.depart_name
                     FROM staff s LEFT JOIN staff_department d ON d.id = s.department
                     WHERE s.transferpicklist = 1 AND s.recycle != 1 ORDER BY s.nama_staff");
while ($res && $r = $res->fetch_assoc()) { $admins[] = $r; }

// ── Department dropdown ──
$deptList = array();
$res = $conn->query("SELECT d.id, d.depart_name FROM staff_department d
                     WHERE EXISTS (SELECT 1 FROM staff s WHERE s.department = d.id AND s.recycle != 1)
                     ORDER BY d.depart_name");
while ($res && $r = $res->fetch_assoc()) { $deptList[] = $r; }

// ── Search staff to add ──
$results = array();
$hasFilter = ($search !== '' || $fDept > 0);
if ($hasFilter) {
    $w = array("s.recycle != 1");
    if ($fDept > 0) { $w[] = "s.department = $fDept"; }
    if ($search !== '') {
        $esc = $conn->real_escape_string($search);
        $idCond = ctype_digit($search) ? " OR s.id = " . (int)$search : '';
        $w[] = "(s.nama_staff LIKE '%$esc%' OR s.username LIKE '%$esc%'$idCond)";
    }
    $res = $conn->query("SELECT s.id, s.nama_staff, s.username, s.status_semasa, s.transferpicklist, d.depart_name
                         FROM staff s LEFT JOIN staff_department d ON d.id = s.department
                         WHERE " . implode(' AND ', $w) . "
                         ORDER BY s.nama_staff LIMIT 100");
    while ($res && $r = $res->fetch_assoc()) { $results[] = $r; }
}

// ── Recent changes ──
$logs = array();
$res = $conn->query("SELECT staff_name, action, by_user, created_at FROM transferpicklist_admin_log ORDER BY id DESC LIMIT 30");
while ($res && $r = $res->fetch_assoc()) { $logs[] = $r; }

function tplAdminBtn($sid, $grant, $name, $token, $keepQs, $disabledReason = '') {
    if ($disabledReason !== '') {
        echo '<span class="alpro-muted" style="font-size:12px;">' . htmlspecialchars($disabledReason) . '</span>';
        return;
    }
    $q = $grant
        ? 'Make ' . $name . ' a Super Admin?\n\nThey will get full access to Transfer Pick List.'
        : 'Remove Super Admin from ' . $name . '?';
    echo '<form method="post" action="transferpicklist_admin.php' . ($keepQs !== '' ? '?' . htmlspecialchars($keepQs) : '') . '" style="display:inline;" '
       . 'onsubmit="return confirm(' . htmlspecialchars(json_encode($q), ENT_QUOTES) . ');">'
       . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
       . '<input type="hidden" name="staff_id" value="' . (int)$sid . '">'
       . '<input type="hidden" name="set_admin" value="' . ($grant ? '1' : '0') . '">'
       . '<button type="submit" class="tpl-adm-btn ' . ($grant ? 'tpl-adm-grant' : 'tpl-adm-remove') . '">'
       . ($grant ? '+ Make Super Admin' : 'Remove') . '</button></form>';
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Settings</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.tpl-adm-title{display:flex;align-items:center;gap:8px;font-size:16px;font-weight:bold;color:#222;padding-bottom:10px;border-bottom:1px solid #e5e7eb;margin-bottom:10px;}
.tpl-adm-title small{font-size:12px;font-weight:normal;color:#888;}
.tpl-adm-btn{height:28px;padding:0 12px;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;}
.tpl-adm-grant{background:#2e7d32;border:none;color:#fff;}
.tpl-adm-grant:hover{background:#1b5e20;}
.tpl-adm-remove{background:#fff;border:1px solid #e57373;color:#c62828;}
.tpl-adm-remove:hover{background:#ffebee;}
.tpl-adm-badge{display:inline-block;background:#e8f5e9;color:#2e7d32;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;}
.tpl-adm-search{display:flex;align-items:flex-end;gap:8px;margin-bottom:10px;flex-wrap:wrap;}
.tpl-adm-search label{display:block;font-size:11px;color:#888;margin-bottom:4px;}
.tpl-adm-search input[type=text],.tpl-adm-search select{width:100%;height:32px;padding:0 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;}
.tpl-adm-search .tpl-adm-f{flex:1 1 0;min-width:180px;}
.tpl-adm-search .tpl-adm-go{flex:0 0 auto;width:auto !important;height:32px;padding:0 18px;}
.myTable td,.myTable th{text-align:left;}
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Admin Settings</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<!-- CURRENT SUPER ADMINS -->
<div class="alpro-box">
    <div class="tpl-adm-title">&#128273; Super Admins <span class="tpl-adm-badge"><?php echo count($admins); ?></span>
        <small>— full access to every Transfer Pick List page</small></div>
    <table class="myTable alpro-table" width="100%" cellpadding="6" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th width="40">No.</th><th>Name</th><th>Department</th><th>Position</th><th width="160">Action</th></tr>
        <?php if (empty($admins)): ?>
        <tr><td colspan="5" class="alpro-muted">No Super Admins.</td></tr>
        <?php endif; ?>
        <?php foreach ($admins as $i => $a): ?>
        <tr>
            <td><?php echo $i + 1; ?></td>
            <td><b><?php echo htmlspecialchars($a['nama_staff']); ?></b><?php echo (int)$a['id'] === $_meId ? ' <span class="alpro-muted" style="font-size:11px;">(you)</span>' : ''; ?></td>
            <td><?php echo htmlspecialchars((string)$a['depart_name']); ?></td>
            <td><?php echo htmlspecialchars((string)$a['status_semasa']); ?></td>
            <td><?php
                $why = (int)$a['id'] === $_meId ? 'Can\'t remove yourself' : (count($admins) <= 1 ? 'Last Super Admin' : '');
                tplAdminBtn($a['id'], false, $a['nama_staff'], $_token, $_keepQs, $why);
            ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- ADD -->
<div class="alpro-box">
    <div class="tpl-adm-title">&#10133; Add a Super Admin <small>— pick a department, or search by name, username or staff ID</small></div>
    <form method="get" action="transferpicklist_admin.php" class="tpl-adm-search">
        <div class="tpl-adm-f">
            <label>Department</label>
            <select name="dept" onchange="this.form.submit();">
                <option value="">-- All Departments --</option>
                <?php foreach ($deptList as $d): ?>
                <option value="<?php echo (int)$d['id']; ?>" <?php echo $fDept === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['depart_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tpl-adm-f">
            <label>Search</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, username or staff ID">
        </div>
        <input type="submit" value="Search" class="alpro-btn alpro-btn-blue tpl-adm-go">
        <?php if ($hasFilter): ?><a href="transferpicklist_admin.php" class="alpro-btn alpro-btn-grey" style="flex:0 0 auto;height:32px;line-height:32px;padding:0 14px;box-sizing:border-box;display:inline-flex;align-items:center;">Clear</a><?php endif; ?>
    </form>
    <?php if ($hasFilter): ?>
    <table class="myTable alpro-table" width="100%" cellpadding="6" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th>Name</th><th>Department</th><th>Position</th><th width="160">Action</th></tr>
        <?php if (empty($results)): ?>
        <tr><td colspan="4" class="alpro-muted">No staff found.</td></tr>
        <?php endif; ?>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><b><?php echo htmlspecialchars($r['nama_staff']); ?></b></td>
            <td><?php echo htmlspecialchars((string)$r['depart_name']); ?></td>
            <td><?php echo htmlspecialchars((string)$r['status_semasa']); ?></td>
            <td><?php
                if ((int)$r['transferpicklist'] === 1) { echo '<span class="tpl-adm-badge">&#10003; Super Admin</span>'; }
                else { tplAdminBtn($r['id'], true, $r['nama_staff'], $_token, $_keepQs); }
            ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if (count($results) === 100): ?><p class="alpro-muted" style="font-size:12px;margin-top:6px;">Showing the first 100 matches — pick a department or type more of the name to narrow it down.</p><?php endif; ?>
    <?php endif; ?>
</div>

<!-- LOG -->
<div class="alpro-box">
    <div class="tpl-adm-title">&#128339; Recent Changes <small>(latest 30)</small></div>
    <?php if (empty($logs)): ?>
    <div class="alpro-muted" style="font-size:13px;">No changes recorded yet.</div>
    <?php endif; ?>
    <?php foreach ($logs as $l): $g = $l['action'] === 'grant'; ?>
    <div style="border-left:3px solid <?php echo $g ? '#2e7d32' : '#c62828'; ?>; padding:3px 0 3px 12px; margin-bottom:8px;">
        <div style="font-size:13px;"><b><?php echo htmlspecialchars($l['staff_name']); ?></b> <?php echo $g ? 'was made a Super Admin' : 'was removed as Super Admin'; ?></div>
        <div style="font-size:11px;color:#888;">by <?php echo htmlspecialchars($l['by_user'] !== '' && $l['by_user'] !== null ? $l['by_user'] : 'Unknown'); ?> &middot; <?php echo date('d M Y, h:i A', strtotime($l['created_at'])); ?></div>
    </div>
    <?php endforeach; ?>
</div>

</body>
</html>
<?php
$connect = 0;
include('../common/index_adv.php');
?>
