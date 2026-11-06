<?php
// Admin Settings — set a staff's Transfer Pick List role (staff.transferpicklist):
//   1 = Super Admin (everything), 2 = Admin 2 (Warehouse Returns, Procurement List,
//   Exclusions → Campaign only), 0 = none.
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
$_roleNames = array(0 => 'no access', 1 => 'Super Admin', 2 => 'Admin 2');
// Log action per new role (transferpicklist_admin_log.action)
$_roleAction = array(1 => 'grant', 2 => 'grant2', 0 => 'remove');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_admin'], $_POST['staff_id'])) {
    $sid     = (int)$_POST['staff_id'];
    $newRole = (int)$_POST['set_admin'];
    if (!hash_equals($_token, isset($_POST['token']) ? (string)$_POST['token'] : '')) {
        $msg = 'Your session expired. Please try again.'; $msg_type = 'error';
    } elseif (!isset($_roleNames[$newRole])) {
        $msg = 'Unknown role.'; $msg_type = 'error';
    } else {
        $row = $conn->query("SELECT id, nama_staff, username, transferpicklist FROM staff WHERE id = $sid AND recycle != 1 LIMIT 1")->fetch_assoc();
        $adminCount = (int)$conn->query("SELECT COUNT(*) c FROM staff WHERE transferpicklist = 1 AND recycle != 1")->fetch_assoc()['c'];
        $oldRole = $row ? (int)$row['transferpicklist'] : 0;
        if (!$row) {
            $msg = 'Staff not found.'; $msg_type = 'error';
        } elseif ($oldRole === 1 && $newRole !== 1 && $adminCount <= 1) {
            $msg = 'There must be at least one Super Admin.'; $msg_type = 'error';
        } elseif ($oldRole === $newRole) {
            $msg = htmlspecialchars($row['nama_staff']) . ' is already ' . ($newRole === 0 ? 'without access' : 'a ' . $_roleNames[$newRole]) . '.'; $msg_type = 'warn';
        } else {
            $conn->query("UPDATE staff SET transferpicklist = $newRole WHERE id = $sid LIMIT 1");
            $conn->query("INSERT INTO transferpicklist_admin_log (staff_id, staff_name, action, by_user, created_at) VALUES ($sid, '"
                . $conn->real_escape_string($row['nama_staff']) . "', '" . $_roleAction[$newRole] . "', '"
                . $conn->real_escape_string($_byName) . "', '" . date('Y-m-d H:i:s') . "')");
            // Changed your OWN access away from Super Admin: this page is no longer yours — go to
            // the module start page (Admin 2 lands on Warehouse Returns; no access → permission page).
            if ($sid === $_meId && $newRole !== 1) { redirect('/odb/transferpicklist/index.php'); }
            header('Location: transferpicklist_admin.php?done=' . $newRole . '&n=' . urlencode($row['nama_staff'])
                . ($_keepQs !== '' ? '&' . $_keepQs : ''));
            exit;
        }
    }
}
if (isset($_GET['done'], $_GET['n'])) {
    $_d = (int)$_GET['done'];
    $msg = '<b>' . htmlspecialchars($_GET['n']) . '</b> ' . ($_d === 0 ? 'no longer has Transfer Pick List admin access.' : 'is now ' . ($_d === 1 ? 'a Super Admin.' : 'an Admin 2.'));
    $msg_type = 'success';
}

// ── Current Super Admins ──
$admins = array(); $admins2 = array();
$res = $conn->query("SELECT s.id, s.nama_staff, s.username, s.status_semasa, s.transferpicklist, d.depart_name
                     FROM staff s LEFT JOIN staff_department d ON d.id = s.department
                     WHERE s.transferpicklist IN (1, 2) AND s.recycle != 1 ORDER BY s.nama_staff");
while ($res && $r = $res->fetch_assoc()) { if ((int)$r['transferpicklist'] === 1) { $admins[] = $r; } else { $admins2[] = $r; } }

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

// $role: 1 = make Super Admin, 2 = make Admin 2, 0 = remove access
function tplAdminBtn($sid, $role, $name, $token, $keepQs, $disabledReason = '') {
    if ($disabledReason !== '') {
        echo '<span class="alpro-muted" style="font-size:12px;">' . htmlspecialchars($disabledReason) . '</span>';
        return;
    }
    $q     = array(
        1 => 'Make ' . $name . ' a Super Admin?\n\nThey will get full access to Transfer Pick List.',
        2 => 'Make ' . $name . ' an Admin 2?\n\nThey can use Warehouse Returns, Procurement List and Exclusions \u2192 Campaign only.',
        0 => 'Remove ' . $name . '\'s Transfer Pick List admin access?',
    );
    $label = array(1 => '+ Super Admin', 2 => '+ Admin 2', 0 => 'Remove');
    $cls   = array(1 => 'tpl-adm-grant', 2 => 'tpl-adm-grant2', 0 => 'tpl-adm-remove');
    echo '<form method="post" action="transferpicklist_admin.php' . ($keepQs !== '' ? '?' . htmlspecialchars($keepQs) : '') . '" style="display:inline;" '
       . 'onsubmit="return confirm(' . htmlspecialchars(json_encode($q[$role]), ENT_QUOTES) . ');">'
       . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
       . '<input type="hidden" name="staff_id" value="' . (int)$sid . '">'
       . '<input type="hidden" name="set_admin" value="' . (int)$role . '">'
       . '<button type="submit" class="tpl-adm-btn ' . $cls[$role] . '">' . $label[$role] . '</button></form> ';
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
.tpl-adm-grant2{background:#1976d2;border:none;color:#fff;}
.tpl-adm-grant2:hover{background:#0d47a1;}
.tpl-adm-badge2{display:inline-block;background:#e3f2fd;color:#1565c0;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;}
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
    <div class="tpl-adm-title">&#128273; Super Admins <span class="tpl-adm-badge"><?php echo count($admins); ?></span></div>
    <table class="myTable alpro-table" width="100%" cellpadding="6" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th width="40">No.</th><th>Name</th><th>Department</th><th width="220">Action</th></tr>
        <?php if (empty($admins)): ?>
        <tr><td colspan="4" class="alpro-muted">No Super Admins.</td></tr>
        <?php endif; ?>
        <?php foreach ($admins as $i => $a): ?>
        <tr>
            <td><?php echo $i + 1; ?></td>
            <td><b><?php echo htmlspecialchars($a['nama_staff']); ?></b><?php echo (int)$a['id'] === $_meId ? ' <span class="alpro-muted" style="font-size:11px;">(you)</span>' : ''; ?></td>
            <td><?php echo htmlspecialchars((string)$a['depart_name']); ?></td>
            <td><?php
                // You may change your own access too — only the very last Super Admin is protected
                $why = count($admins) <= 1 ? 'Last Super Admin' : '';
                if ($why === '') { tplAdminBtn($a['id'], 2, $a['nama_staff'], $_token, $_keepQs); }
                tplAdminBtn($a['id'], 0, $a['nama_staff'], $_token, $_keepQs, $why);
            ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- ADMIN 2 -->
<div class="alpro-box">
    <div class="tpl-adm-title">&#128100; Admin 2 <span class="tpl-adm-badge2"><?php echo count($admins2); ?></span> <small>— Warehouse Returns, Procurement List and Exclusions &rarr; Campaign only</small></div>
    <table class="myTable alpro-table" width="100%" cellpadding="6" cellspacing="1" bgcolor="#EFEFEF">
        <tr><th width="40">No.</th><th>Name</th><th>Department</th><th width="220">Action</th></tr>
        <?php if (empty($admins2)): ?>
        <tr><td colspan="4" class="alpro-muted">No Admin 2 yet — search below to add one.</td></tr>
        <?php endif; ?>
        <?php foreach ($admins2 as $i => $a): ?>
        <tr>
            <td><?php echo $i + 1; ?></td>
            <td><b><?php echo htmlspecialchars($a['nama_staff']); ?></b></td>
            <td><?php echo htmlspecialchars((string)$a['depart_name']); ?></td>
            <td><?php tplAdminBtn($a['id'], 1, $a['nama_staff'], $_token, $_keepQs); tplAdminBtn($a['id'], 0, $a['nama_staff'], $_token, $_keepQs); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- ADD -->
<div class="alpro-box">
    <div class="tpl-adm-title">&#10133; Add an Admin <small>— pick a department, or search by name, username or staff ID</small></div>
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
        <tr><th>Name</th><th>Department</th><th width="220">Action</th></tr>
        <?php if (empty($results)): ?>
        <tr><td colspan="3" class="alpro-muted">No staff found.</td></tr>
        <?php endif; ?>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><b><?php echo htmlspecialchars($r['nama_staff']); ?></b></td>
            <td><?php echo htmlspecialchars((string)$r['depart_name']); ?></td>
            <td><?php
                $_lv = (int)$r['transferpicklist'];
                if ($_lv === 1)     { echo '<span class="tpl-adm-badge">&#10003; Super Admin</span>'; }
                elseif ($_lv === 2) { echo '<span class="tpl-adm-badge2">&#10003; Admin 2</span> '; tplAdminBtn($r['id'], 1, $r['nama_staff'], $_token, $_keepQs); }
                else                { tplAdminBtn($r['id'], 1, $r['nama_staff'], $_token, $_keepQs); tplAdminBtn($r['id'], 2, $r['nama_staff'], $_token, $_keepQs); }
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
    <?php foreach ($logs as $l):
        $_la  = $l['action'];
        $_lc  = $_la === 'grant' ? '#2e7d32' : ($_la === 'grant2' ? '#1976d2' : '#c62828');
        $_lt  = $_la === 'grant' ? 'was made a Super Admin' : ($_la === 'grant2' ? 'was made an Admin 2' : 'had admin access removed');
    ?>
    <div style="border-left:3px solid <?php echo $_lc; ?>; padding:3px 0 3px 12px; margin-bottom:8px; text-align:left;">
        <div style="font-size:13px;"><b><?php echo htmlspecialchars($l['staff_name']); ?></b> <?php echo $_lt; ?></div>
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
