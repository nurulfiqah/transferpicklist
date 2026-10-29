<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }
$_sync_is_super = (int)$transferpicklist_autho === 1;

$filterStatus = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$filterSearch = isset($_GET['q'])      ? trim($_GET['q']) : '';

// ── Dynamic Non-Moving phase label (mirrors nonmoving_sync.php logic) ─
$_curMonth = date('Y-m');
if ($_curMonth >= '2026-07')     { $nmLabel = 'Non-Moving (6M)'; }
elseif ($_curMonth >= '2026-06') { $nmLabel = 'Non-Moving (9M)'; }
else                             { $nmLabel = 'Non-Moving (12M)'; }

// Ensure status tables exist (lightweight — only creates if missing)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transferpicklist_itemgrade_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outlet_id INT NOT NULL DEFAULT 0,
    outlet_code VARCHAR(20) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    last_sync DATETIME DEFAULT NULL,
    error_msg VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY uq_outlet (outlet_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transferpicklist_nonmoving_ethical_status (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    outlet_id INT NOT NULL,
    outlet_code VARCHAR(20) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    last_sync DATETIME DEFAULT NULL,
    error_msg TEXT DEFAULT NULL,
    UNIQUE KEY uq_outlet (outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transferpicklist_nonmoving_ethical (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outlet_id INT NOT NULL,
    item_code VARCHAR(50) NOT NULL,
    closing_stock FLOAT NOT NULL DEFAULT 0,
    avg_monthly FLOAT NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL,
    UNIQUE KEY uq_outlet_item (outlet_id, item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transferpicklist_slowmoving_ethical_status (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    outlet_id INT NOT NULL,
    outlet_code VARCHAR(20) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    last_sync DATETIME DEFAULT NULL,
    error_msg TEXT DEFAULT NULL,
    UNIQUE KEY uq_outlet (outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transferpicklist_slowmoving_ethical (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outlet_id INT NOT NULL,
    item_code VARCHAR(50) NOT NULL,
    qty FLOAT NOT NULL DEFAULT 0,
    trend_type VARCHAR(20) NOT NULL DEFAULT '',
    timestamp DATETIME NOT NULL,
    last_month_closing FLOAT NOT NULL DEFAULT 0,
    avg_monthly FLOAT NOT NULL DEFAULT 0,
    moi FLOAT NOT NULL DEFAULT 0,
    adjusted_overstock_qty FLOAT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_outlet_item_trend (outlet_id, item_code, trend_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

// ── Lock file checks (filesystem, no DB needed) ───────────────────────
$igLocked  = file_exists(__DIR__ . '/logs/itemgrade_sync.lock');
$nmLocked  = file_exists(__DIR__ . '/logs/nonmoving_sync.lock');
$nmeLocked = file_exists(__DIR__ . '/logs/nonmoving_ethical_sync.lock');
$smLocked  = file_exists(__DIR__ . '/logs/slowmoving_sync.lock');
$smeLocked = file_exists(__DIR__ . '/logs/slowmoving_ethical_sync.lock');
$p3mLocked = file_exists(__DIR__ . '/logs/past3months_sync.lock');

// ── QUERY 1: Summary counts + per-outlet rows + NEW grade counts — all in one ──
// Replaces 6 separate queries (4x syncSummary + newGradeMap + main UNION query)
$empty = array('total' => 0, 'synced' => 0, 'pending' => 0, 'last_sync' => null);
$igSum = $nmSum = $nmeSum = $smSum = $smeSum = $p3mSum = $empty;
$rows  = array();

$where = '';
if ($filterStatus === 'pending') {
    $where = "HAVING (ig_status=0 OR nm_status=0 OR nme_status=0 OR sm_status=0 OR sme_status=0 OR p3m_status=0)";
} elseif ($filterStatus === 'synced') {
    $where = "HAVING (ig_status=1 AND nm_status=1 AND nme_status=1 AND sm_status=1 AND sme_status=1 AND p3m_status=1)";
}
if ($filterSearch !== '') {
    $s = mysqli_real_escape_string($conn, $filterSearch);
    $where .= ($where ? ' AND' : 'HAVING') . " outlet_code LIKE '%$s%'";
}

// Single query: join all 4 status tables + outlet
$sql = "
    SELECT
        o.code  AS outlet_code,
        o.id    AS outlet_id,
        ig.status    AS ig_status,  ig.last_sync  AS ig_last_sync,  ig.error_msg  AS ig_error,
        nm.status    AS nm_status,  nm.last_sync  AS nm_last_sync,  nm.error_msg  AS nm_error,
        nme.status   AS nme_status, nme.last_sync AS nme_last_sync, nme.error_msg AS nme_error,
        sm.status    AS sm_status,  sm.last_sync  AS sm_last_sync,  sm.error_msg  AS sm_error,
        sme.status   AS sme_status, sme.last_sync AS sme_last_sync, sme.error_msg AS sme_error,
        p3m.status   AS p3m_status, p3m.last_sync AS p3m_last_sync, p3m.error_msg AS p3m_error
    FROM outlet o
    LEFT JOIN transferpicklist_itemgrade_status           ig  ON ig.outlet_code  = o.code
    LEFT JOIN transferpicklist_nonmoving_status           nm  ON nm.outlet_code  = o.code
    LEFT JOIN transferpicklist_nonmoving_ethical_status   nme ON nme.outlet_code = o.code
    LEFT JOIN transferpicklist_slowmoving_status          sm  ON sm.outlet_code  = o.code
    LEFT JOIN transferpicklist_slowmoving_ethical_status  sme ON sme.outlet_code = o.code
    LEFT JOIN transferpicklist_past3months_status         p3m ON p3m.outlet_code = o.code
    WHERE o.recycle = 0
      AND (ig.outlet_code IS NOT NULL OR nm.outlet_code IS NOT NULL OR nme.outlet_code IS NOT NULL
           OR sm.outlet_code IS NOT NULL OR sme.outlet_code IS NOT NULL OR p3m.outlet_code IS NOT NULL)
    $where
    ORDER BY o.code
";
$res = mysqli_query($conn, $sql);

// ── QUERY 2: Summary card counts — one query for all 4 tables ─────────
$sumQ = mysqli_query($conn, "
    SELECT
        'ig'  AS src, COUNT(*) AS total, SUM(status=1) AS synced, SUM(status=0) AS pending, MAX(last_sync) AS last_sync
        FROM transferpicklist_itemgrade_status
    UNION ALL
    SELECT 'nm',  COUNT(*), SUM(status=1), SUM(status=0), MAX(last_sync) FROM transferpicklist_nonmoving_status
    UNION ALL
    SELECT 'nme', COUNT(*), SUM(status=1), SUM(status=0), MAX(last_sync) FROM transferpicklist_nonmoving_ethical_status
    UNION ALL
    SELECT 'sm',  COUNT(*), SUM(status=1), SUM(status=0), MAX(last_sync) FROM transferpicklist_slowmoving_status
    UNION ALL
    SELECT 'sme', COUNT(*), SUM(status=1), SUM(status=0), MAX(last_sync) FROM transferpicklist_slowmoving_ethical_status
    UNION ALL
    SELECT 'p3m', COUNT(*), SUM(status=1), SUM(status=0), MAX(last_sync) FROM transferpicklist_past3months_status
");
if ($sumQ) {
    while ($sr = mysqli_fetch_assoc($sumQ)) {
        switch ($sr['src']) {
            case 'ig':  $igSum  = $sr; break;
            case 'nm':  $nmSum  = $sr; break;
            case 'nme': $nmeSum = $sr; break;
            case 'sm':  $smSum  = $sr; break;
            case 'sme': $smeSum = $sr; break;
            case 'p3m': $p3mSum = $sr; break;
        }
    }
}

// Collect per-outlet rows
if ($res) {
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
}

// Helper: progress bar
function progressBar($synced, $total) {
    $pct = $total > 0 ? round($synced / $total * 100) : 0;
    $color = $pct == 100 ? '#388e3c' : ($pct > 50 ? '#1976d2' : '#c62828');
    return "<div style='margin-top:8px;'>
        <div style='background:#eee;border-radius:3px;height:6px;width:100%;'>
            <div style='background:$color;height:6px;border-radius:3px;width:{$pct}%;'></div>
        </div>
        <div style='font-size:10px;color:#888;margin-top:2px;text-align:right;'>$pct%</div>
    </div>";
}

// "2026-08-05 16:06:47" -> "08-05 16:06" — drops the year (all rows are
// clearly current) so the narrow per-outlet table columns don't overflow.
function shortSync($ts) {
    if (!$ts) { return '—'; }
    return substr($ts, 5, 5) . ' ' . substr($ts, 11, 5);
}

// All six syncs run as standalone Python scripts under hyper/ (Task
// Scheduler or manual .bat) — that's the normal path. The thin
// transferpicklist_*_sync.php pages are a manual/browser fallback that
// just shells out to the same script, so show both: the scheduled-job
// label, and a "just in case" manual trigger.
function syncInfoPythonScheduled($isSuper, $scriptName, $syncPage, $pendingCount, $totalCount) {
    if (!$isSuper) { return ''; }
    $pendingLabel = $pendingCount > 0 ? "Sync Pending ($pendingCount)" : "Sync Pending";
    return "<div style='margin-top:10px; font-size:11px; color:#888;'>Runs via scheduled Python job (hyper/{$scriptName})</div>
            <div class='sync-actions' style='margin-top:6px;'>
                <a href='{$syncPage}' class='alpro-btn alpro-btn-orange' style='font-size:11px;padding:3px 8px;'>$pendingLabel</a>
                <a href='{$syncPage}?sync_all=1' class='alpro-btn alpro-btn-grey' style='font-size:11px;padding:3px 8px;' onclick=\"return confirm('Sync ALL $totalCount outlets?');\">Sync All</a>
            </div>";
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Sync Status Dashboard</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<?php if ($igLocked || $nmLocked || $nmeLocked || $smLocked || $smeLocked || $p3mLocked): ?>
<!-- FIX: Auto-refresh every 30s while any sync is running -->
<meta http-equiv="refresh" content="30">
<?php endif; ?>
<style>
.sync-cards { display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:18px; }
.sync-card  { min-width:0; background:#fff; border:1px solid #ddd; border-radius:6px; padding:10px 12px; }
.sync-card h3 { margin:0 0 8px 0; font-size:11px; color:#555; font-weight:600; text-transform:uppercase; letter-spacing:.3px; }
.sync-card .big { font-size:22px; font-weight:bold; color:#1976d2; }
.sync-card .meta { font-size:10px; color:#888; margin-top:6px; }
.sync-card .counts { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.sync-card .counts span { font-size:11px; font-weight:600; }
@media (max-width: 900px) {
    .sync-cards { grid-template-columns:repeat(3, 1fr); }
}
@media (max-width: 480px) {
    .sync-cards { grid-template-columns:repeat(2, 1fr); }
}
.s-ok  { color:#388e3c; }
.s-err { color:#c62828; }
.s-na  { color:#bbb; }
.badge-ok  { background:#e8f5e9; color:#388e3c; font-size:10px; font-weight:bold; padding:1px 5px; border-radius:3px; white-space:nowrap; }
.badge-err { background:#ffebee; color:#c62828; font-size:10px; font-weight:bold; padding:1px 5px; border-radius:3px; white-space:nowrap; }
.badge-na  { background:#f5f5f5; color:#aaa;    font-size:10px; padding:1px 5px; border-radius:3px; white-space:nowrap; }
.sync-actions { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:4px; }
.tpl-filter-bar { background:#fff; border:1px solid #ddd; border-radius:6px; padding:12px 16px; display:flex; align-items:flex-end; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
.tpl-filter-label { display:block; font-size:11px; color:#888; margin-bottom:4px; text-align:center; }
.tpl-filter-bar input[type=text], .tpl-filter-bar select { width:100%; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; box-sizing:border-box; height:32px; }
.tpl-filter-bar input[type=text]:focus, .tpl-filter-bar select:focus { border-color:#1976d2; outline:none; }
.tpl-filter-grow { flex:1 1 0; min-width:120px; }
.tpl-filter-fixed { flex:0 0 auto; white-space:nowrap; }
/* IG Sync All uses POST form */
.ig-sync-form { display:inline; }
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Sync Status Dashboard</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

    <!-- Summary Cards -->
    <div class="sync-cards">

        <!-- Item Grade -->
        <div class="sync-card">
            <h3>Item Grade</h3>
            <div class="big"><?php echo (int)$igSum['total']; ?></div>
            <div class="counts">
                <span class="s-ok">&#10003; <?php echo (int)$igSum['synced']; ?> synced</span>
                <span class="<?php echo $igSum['pending'] > 0 ? 's-err' : 's-ok'; ?>">
                    &#9888; <?php echo (int)$igSum['pending']; ?> pending
                </span>
            </div>
            <?php echo progressBar((int)$igSum['synced'], (int)$igSum['total']); ?>
            <div class="meta">Last sync: <?php echo $igSum['last_sync'] ? htmlspecialchars($igSum['last_sync']) : '—'; ?></div>
            <?php echo syncInfoPythonScheduled($_sync_is_super, 'itemgrade_sync.py', 'transferpicklist_itemgrade_sync.php', (int)$igSum['pending'], (int)$igSum['total']); ?>
        </div>

        <!-- Non-Moving -->
        <div class="sync-card">
            <h3><?php echo htmlspecialchars($nmLabel); ?></h3>
            <div class="big"><?php echo (int)$nmSum['total']; ?></div>
            <div class="counts">
                <span class="s-ok">&#10003; <?php echo (int)$nmSum['synced']; ?> synced</span>
                <span class="<?php echo $nmSum['pending'] > 0 ? 's-err' : 's-ok'; ?>">
                    &#9888; <?php echo (int)$nmSum['pending']; ?> pending
                </span>
            </div>
            <?php echo progressBar((int)$nmSum['synced'], (int)$nmSum['total']); ?>
            <div class="meta">Last sync: <?php echo $nmSum['last_sync'] ? htmlspecialchars($nmSum['last_sync']) : '—'; ?></div>
            <?php echo syncInfoPythonScheduled($_sync_is_super, 'nonmoving_sync.py', 'transferpicklist_nonmoving_sync.php', (int)$nmSum['pending'], (int)$nmSum['total']); ?>
        </div>

        <!-- Non-Moving Ethical -->
        <div class="sync-card">
            <h3>Non-Moving Ethical (6M)</h3>
            <div class="big"><?php echo (int)$nmeSum['total']; ?></div>
            <div class="counts">
                <span class="s-ok">&#10003; <?php echo (int)$nmeSum['synced']; ?> synced</span>
                <span class="<?php echo $nmeSum['pending'] > 0 ? 's-err' : 's-ok'; ?>">
                    &#9888; <?php echo (int)$nmeSum['pending']; ?> pending
                </span>
            </div>
            <?php echo progressBar((int)$nmeSum['synced'], (int)$nmeSum['total']); ?>
            <div class="meta">Last sync: <?php echo $nmeSum['last_sync'] ? htmlspecialchars($nmeSum['last_sync']) : '—'; ?></div>
            <?php echo syncInfoPythonScheduled($_sync_is_super, 'nonmoving_ethical_sync.py', 'transferpicklist_nonmoving_ethical_sync.php', (int)$nmeSum['pending'], (int)$nmeSum['total']); ?>
        </div>

        <!-- Slow Moving -->
        <div class="sync-card">
            <h3>Slow Moving</h3>
            <div class="big"><?php echo (int)$smSum['total']; ?></div>
            <div class="counts">
                <span class="s-ok">&#10003; <?php echo (int)$smSum['synced']; ?> synced</span>
                <span class="<?php echo $smSum['pending'] > 0 ? 's-err' : 's-ok'; ?>">
                    &#9888; <?php echo (int)$smSum['pending']; ?> pending
                </span>
            </div>
            <?php echo progressBar((int)$smSum['synced'], (int)$smSum['total']); ?>
            <div class="meta">Last sync: <?php echo $smSum['last_sync'] ? htmlspecialchars($smSum['last_sync']) : '—'; ?></div>
            <?php echo syncInfoPythonScheduled($_sync_is_super, 'slowmoving_sync.py', 'transferpicklist_slowmoving_sync.php', (int)$smSum['pending'], (int)$smSum['total']); ?>
        </div>

        <!-- Slow Moving Ethical -->
        <div class="sync-card">
            <h3>Slow Moving Ethical</h3>
            <div class="big"><?php echo (int)$smeSum['total']; ?></div>
            <div class="counts">
                <span class="s-ok">&#10003; <?php echo (int)$smeSum['synced']; ?> synced</span>
                <span class="<?php echo $smeSum['pending'] > 0 ? 's-err' : 's-ok'; ?>">
                    &#9888; <?php echo (int)$smeSum['pending']; ?> pending
                </span>
            </div>
            <?php echo progressBar((int)$smeSum['synced'], (int)$smeSum['total']); ?>
            <div class="meta">Last sync: <?php echo $smeSum['last_sync'] ? htmlspecialchars($smeSum['last_sync']) : '—'; ?></div>
            <?php echo syncInfoPythonScheduled($_sync_is_super, 'slowmoving_ethical_sync.py', 'transferpicklist_slowmoving_ethical_sync.php', (int)$smeSum['pending'], (int)$smeSum['total']); ?>
        </div>

        <!-- Past 3 Months -->
        <div class="sync-card">
            <h3>Past 3 Months</h3>
            <div class="big"><?php echo (int)$p3mSum['total']; ?></div>
            <div class="counts">
                <span class="s-ok">&#10003; <?php echo (int)$p3mSum['synced']; ?> synced</span>
                <span class="<?php echo $p3mSum['pending'] > 0 ? 's-err' : 's-ok'; ?>">
                    &#9888; <?php echo (int)$p3mSum['pending']; ?> pending
                </span>
            </div>
            <?php echo progressBar((int)$p3mSum['synced'], (int)$p3mSum['total']); ?>
            <div class="meta">Last sync: <?php echo $p3mSum['last_sync'] ? htmlspecialchars($p3mSum['last_sync']) : '—'; ?></div>
            <?php echo syncInfoPythonScheduled($_sync_is_super, 'past3m_sync.py', 'transferpicklist_past3months_sync.php', (int)$p3mSum['pending'], (int)$p3mSum['total']); ?>
        </div>

    </div>

    <!-- Filter Bar -->
    <form method="get" action="">
    <div class="tpl-filter-bar">
        <div class="tpl-filter-grow">
            <span class="tpl-filter-label">Search Outlet</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Outlet code...">
        </div>
        <div style="flex:0 0 180px;">
            <span class="tpl-filter-label">Show</span>
            <select name="filter">
                <option value="all"     <?php echo $filterStatus === 'all'     ? 'selected' : ''; ?>>All Outlets</option>
                <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending / Failed</option>
                <option value="synced"  <?php echo $filterStatus === 'synced'  ? 'selected' : ''; ?>>Fully Synced</option>
            </select>
        </div>
        <div class="tpl-filter-fixed" style="display:flex; gap:6px;">
            <button type="submit" class="alpro-btn alpro-btn-blue">Search</button>
            <a href="transferpicklist_sync_status.php" class="alpro-btn alpro-btn-grey" style="display:inline-flex; align-items:center;">Clear</a>
        </div>
    </div>
    </form>

    <!-- Per-Outlet Table -->
    <fieldset>
    <table class="myTable alpro-table" width="100%" cellpadding="3" cellspacing="1" bgcolor="#EFEFEF" style="font-size:11px;">
        <tr>
            <th width="24">#</th>
            <th>Outlet</th>
            <th width="60" class="alpro-center" title="Item Grade">IG</th>
            <th width="72" class="alpro-center" title="Item Grade Last Sync">IG Sync</th>
            <th width="60" class="alpro-center" title="Non-Moving (6M)">NM</th>
            <th width="72" class="alpro-center" title="Non-Moving Last Sync">NM Sync</th>
            <th width="60" class="alpro-center" title="Non-Moving Ethical (6M)">NME</th>
            <th width="72" class="alpro-center" title="Non-Moving Ethical Last Sync">NME Sync</th>
            <th width="60" class="alpro-center" title="Slow Moving">SM</th>
            <th width="72" class="alpro-center" title="Slow Moving Last Sync">SM Sync</th>
            <th width="60" class="alpro-center" title="Slow Moving Ethical">SME</th>
            <th width="72" class="alpro-center" title="Slow Moving Ethical Last Sync">SME Sync</th>
            <th width="60" class="alpro-center" title="Past 3 Months">P3M</th>
            <th width="72" class="alpro-center" title="Past 3 Months Last Sync">P3M Sync</th>
        </tr>
        <?php if (empty($rows)): ?>
        <tr><td colspan="14" class="alpro-center alpro-muted">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
            <td><b><?php echo htmlspecialchars($r['outlet_code']); ?></b></td>

            <!-- Item Grade -->
            <td class="alpro-center">
                <?php if ($r['ig_status'] === null): ?>
                    <span class="badge-na">—</span>
                <?php elseif ($r['ig_status'] == 1): ?>
                    <span class="badge-ok">Synced</span>
                <?php else: ?>
                    <span class="badge-err">Pending</span>
                    <?php if ($r['ig_error']): ?>
                    <div style="font-size:10px;color:#c62828;margin-top:2px;"><?php echo htmlspecialchars($r['ig_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="alpro-center" style="font-size:11px;color:#555;">
                <?php echo htmlspecialchars(shortSync($r['ig_last_sync'])); ?>
            </td>

            <!-- Non-Moving -->
            <td class="alpro-center">
                <?php if ($r['nm_status'] === null): ?>
                    <span class="badge-na">—</span>
                <?php elseif ($r['nm_status'] == 1): ?>
                    <span class="badge-ok">Synced</span>
                <?php else: ?>
                    <span class="badge-err">Pending</span>
                    <?php if ($r['nm_error']): ?>
                    <div style="font-size:10px;color:#c62828;margin-top:2px;"><?php echo htmlspecialchars($r['nm_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="alpro-center" style="font-size:11px;color:#555;">
                <?php echo htmlspecialchars(shortSync($r['nm_last_sync'])); ?>
            </td>

            <!-- Non-Moving Ethical -->
            <td class="alpro-center">
                <?php if ($r['nme_status'] === null): ?>
                    <span class="badge-na">—</span>
                <?php elseif ($r['nme_status'] == 1): ?>
                    <span class="badge-ok">Synced</span>
                <?php else: ?>
                    <span class="badge-err">Pending</span>
                    <?php if ($r['nme_error']): ?>
                    <div style="font-size:10px;color:#c62828;margin-top:2px;"><?php echo htmlspecialchars($r['nme_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="alpro-center" style="font-size:11px;color:#555;">
                <?php echo htmlspecialchars(shortSync($r['nme_last_sync'])); ?>
            </td>

            <!-- Slow Moving -->
            <td class="alpro-center">
                <?php if ($r['sm_status'] === null): ?>
                    <span class="badge-na">—</span>
                <?php elseif ($r['sm_status'] == 1): ?>
                    <span class="badge-ok">Synced</span>
                <?php else: ?>
                    <span class="badge-err">Pending</span>
                    <?php if ($r['sm_error']): ?>
                    <div style="font-size:10px;color:#c62828;margin-top:2px;"><?php echo htmlspecialchars($r['sm_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="alpro-center" style="font-size:11px;color:#555;">
                <?php echo htmlspecialchars(shortSync($r['sm_last_sync'])); ?>
            </td>

            <!-- Slow Moving Ethical -->
            <td class="alpro-center">
                <?php if ($r['sme_status'] === null): ?>
                    <span class="badge-na">—</span>
                <?php elseif ($r['sme_status'] == 1): ?>
                    <span class="badge-ok">Synced</span>
                <?php else: ?>
                    <span class="badge-err">Pending</span>
                    <?php if ($r['sme_error']): ?>
                    <div style="font-size:10px;color:#c62828;margin-top:2px;"><?php echo htmlspecialchars($r['sme_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="alpro-center" style="font-size:11px;color:#555;">
                <?php echo htmlspecialchars(shortSync($r['sme_last_sync'])); ?>
            </td>

            <!-- Past 3 Months -->
            <td class="alpro-center">
                <?php if ($r['p3m_status'] === null): ?>
                    <span class="badge-na">—</span>
                <?php elseif ($r['p3m_status'] == 1): ?>
                    <span class="badge-ok">Synced</span>
                <?php else: ?>
                    <span class="badge-err">Pending</span>
                    <?php if ($r['p3m_error']): ?>
                    <div style="font-size:10px;color:#c62828;margin-top:2px;"><?php echo htmlspecialchars($r['p3m_error']); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="alpro-center" style="font-size:11px;color:#555;">
                <?php echo htmlspecialchars(shortSync($r['p3m_last_sync'])); ?>
            </td>

        </tr>
        <?php endforeach; ?>
    </table>
    </fieldset>

    <div style="margin-top:6px; font-size:11px; color:#aaa;">
        Showing <?php echo count($rows); ?> outlet(s).
        <?php if ($filterStatus !== 'pending' && ($nmSum['pending'] > 0 || $smSum['pending'] > 0 || $p3mSum['pending'] > 0 || $igSum['pending'] > 0)): ?>
            <a href="?filter=pending">View pending only</a>
        <?php endif; ?>
    </div>

</div>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>