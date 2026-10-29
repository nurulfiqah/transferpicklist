<?php
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho < 3) { redirect('/odb/permission.php'); }

// Create/migrate table — simple outlet-level exclusion, no month column
$conn->query("
    CREATE TABLE IF NOT EXISTS transferpicklist_outlet_exclusion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        outlet_id INT NOT NULL,
        outlet_code VARCHAR(50) NOT NULL DEFAULT '',
        excluded_by VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_outlet (outlet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
// Add outlet_code column if missing (early version didn't have it)
$chkOc = $conn->query("SHOW COLUMNS FROM transferpicklist_outlet_exclusion LIKE 'outlet_code'");
if ($chkOc && $chkOc->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_outlet_exclusion ADD COLUMN outlet_code VARCHAR(50) NOT NULL DEFAULT '' AFTER outlet_id");
}

// Drop old month_year column if it exists from a previous version
$chk = $conn->query("SHOW COLUMNS FROM transferpicklist_outlet_exclusion LIKE 'month_year'");
if ($chk && $chk->num_rows > 0) {
    $conn->query("ALTER TABLE transferpicklist_outlet_exclusion DROP COLUMN month_year");
    $conn->query("ALTER TABLE transferpicklist_outlet_exclusion DROP INDEX IF EXISTS uq_outlet_month");
    $conn->query("DROP INDEX IF EXISTS uq_outlet_month ON transferpicklist_outlet_exclusion");
}

// ── AJAX toggle ───────────────────────────────────────────────
if (isset($_POST['ajax_toggle'])) {
    ob_clean();
    header('Content-Type: application/json');
    $outlet_id   = (int)(isset($_POST['outlet_id'])   ? $_POST['outlet_id']   : 0);
    $outlet_code = isset($_POST['outlet_code'])        ? trim($_POST['outlet_code']) : '';
    $action      = isset($_POST['action'])             ? trim($_POST['action'])      : '';
    $by          = (isset($nama_staff) && $nama_staff !== '') ? $nama_staff : 'HQ';

    if ($outlet_id <= 0) {
        echo json_encode(array('ok' => false, 'msg' => 'Invalid outlet'));
        exit;
    }

    if ($action === 'exclude') {
        $eoc  = $conn->real_escape_string($outlet_code);
        $eby  = $conn->real_escape_string($by);
        $now  = date('Y-m-d H:i:s');
        $ok   = $conn->query("INSERT INTO transferpicklist_outlet_exclusion (outlet_id, outlet_code, excluded_by, created_at)
                      VALUES ($outlet_id, '$eoc', '$eby', '$now')
                      ON DUPLICATE KEY UPDATE outlet_code='$eoc', excluded_by='$eby', created_at='$now'");
        if (!$ok) {
            echo json_encode(array('ok' => false, 'msg' => 'DB error: ' . $conn->error));
            exit;
        }
        echo json_encode(array('ok' => true, 'state' => 'excluded', 'by' => $by, 'at' => substr($now, 0, 16)));
    } else {
        $ok = $conn->query("DELETE FROM transferpicklist_outlet_exclusion WHERE outlet_id=$outlet_id");
        if (!$ok) {
            echo json_encode(array('ok' => false, 'msg' => 'DB error: ' . $conn->error));
            exit;
        }
        echo json_encode(array('ok' => true, 'state' => 'included'));
    }
    exit;
}

// ── Load outlets from pick list sync (not outlet master) ─────
// Source: nonmoving_status = outlets registered in pick list sync
$outlets = array();
$res = $conn->query("
    SELECT ns.outlet_id, ns.outlet_code,
           MAX(o.comp_name) AS comp_name,
           MAX(o.acmm_id)   AS acmm_id
    FROM transferpicklist_nonmoving_status ns
    LEFT JOIN outlet o ON o.code = ns.outlet_code AND o.recycle = 0
    GROUP BY ns.outlet_id, ns.outlet_code
    ORDER BY ns.outlet_code ASC
");
while ($r = $res->fetch_assoc()) {
    $code = strtoupper(trim($r['outlet_code']));
    if (strpos($code, 'Q') === 0)      { $region = 'Sarawak'; }
    elseif (strpos($code, 'S') === 0)  { $region = 'Sabah'; }
    else                               { $region = 'Peninsular'; }
    $r['region'] = $region;
    $outlets[] = $r;
}

// ── Load current exclusions ───────────────────────────────────
$excluded = array();
$res = $conn->query("SELECT outlet_id, excluded_by, created_at FROM transferpicklist_outlet_exclusion");
while ($r = $res->fetch_assoc()) {
    $excluded[(int)$r['outlet_id']] = $r;
}

$total_excluded = count($excluded);
$total_outlets  = count($outlets);
?>
<!DOCTYPE html>
<html>
<head>
<title>Outlet Exclusion – Transfer Pick List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.tgl-switch {
    position: relative;
    width: 46px;
    height: 24px;
    display: inline-block;
    flex-shrink: 0;
}
.tgl-switch input {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}
.tgl-slider {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: #4caf50;
    border-radius: 24px;
    transition: background 0.2s;
    cursor: pointer;
}
.tgl-slider:before {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    left: 3px;
    bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: transform 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.3);
}
.tgl-switch input:checked + .tgl-slider { background: #e53935; }
.tgl-switch input:checked + .tgl-slider:before { transform: translateX(22px); }

.badge-region {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
}
.badge-peninsular { background:#e3f2fd; color:#1565c0; }
.badge-sabah      { background:#e8f5e9; color:#2e7d32; }
.badge-sarawak    { background:#fff3e0; color:#e65100; }

tr.row-excluded td { background: #fff5f5 !important; }
.status-badge-excl {
    display:inline-block; padding:2px 8px; border-radius:10px;
    background:#fde8e8; color:#c62828; font-size:11px; font-weight:700;
}
.status-badge-incl {
    display:inline-block; padding:2px 8px; border-radius:10px;
    background:#e8f5e9; color:#2e7d32; font-size:11px; font-weight:700;
}
.summary-bar {
    display:flex; align-items:center; gap:12px;
    padding:8px 14px; margin-bottom:12px;
    background:#f5f5f5; border-radius:6px; font-size:13px;
}
/* Filter bar — same look as the Procurement List page */
.oex-filter-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;}
.oex-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.oex-filter-bar input[type=text],.oex-filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.oex-filter-bar input[type=text]:focus,.oex-filter-bar select:focus{border-color:#1976d2;outline:none;}
.oex-filter-grow{flex:1 1 0;min-width:0;}
.oex-filter-fixed{flex:0 0 auto;white-space:nowrap;}
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Outlet Exclusion – Pick List</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

<p style="font-size:13px; color:#555; margin-bottom:14px;">
    Set which outlets should <b>not</b> be involved in transfer pick list generation.
    Excluded outlets are skipped as both <b>sender</b> and <b>receiver</b> when generating.
    Sync is unaffected — exclusion only applies at generation time.
    Toggle anytime to re-include an outlet.
</p>

<!-- Summary -->
<div class="summary-bar">
    <span><b id="summaryExcluded" style="font-size:16px; color:#e53935;"><?php echo $total_excluded; ?></b> outlet(s) excluded</span>
    <span style="color:#ccc;">|</span>
    <span id="summaryActive"><?php echo $total_outlets - $total_excluded; ?> / <?php echo $total_outlets; ?></span> pick list outlets active
</div>

<!-- Filters (client-side — no reload, so toggles keep working) -->
<div class="oex-filter-bar">
    <div style="flex:0 0 150px;">
        <span class="oex-filter-label">Outlet</span>
        <select id="filterOutlet" onchange="filterTable()">
            <option value="">-- All --</option>
            <?php foreach ($outlets as $o): ?>
            <option value="<?php echo htmlspecialchars(strtolower($o['outlet_code'])); ?>"><?php echo htmlspecialchars($o['outlet_code']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="flex:0 0 140px;">
        <span class="oex-filter-label">Exclude</span>
        <select id="filterStatus" onchange="filterTable()">
            <option value="">-- All --</option>
            <option value="excluded">Excluded</option>
            <option value="included">Included</option>
        </select>
    </div>
    <div style="flex:0 0 140px;">
        <span class="oex-filter-label">Region</span>
        <select id="filterRegion" onchange="filterTable()">
            <option value="">-- All --</option>
            <option value="peninsular">Peninsular</option>
            <option value="sabah">Sabah</option>
            <option value="sarawak">Sarawak</option>
        </select>
    </div>
    <div class="oex-filter-grow">
        <span class="oex-filter-label">Search (Outlet Code / Name)</span>
        <input type="text" id="searchInput" placeholder="Search outlet code or name..." oninput="filterTable()">
    </div>
    <div class="oex-filter-fixed">
        <span class="oex-filter-label">&nbsp;</span>
        <button type="button" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 18px;" onclick="clearFilters()">Clear</button>
    </div>
</div>
<div id="filterCount" style="font-size:12px; color:#888; margin-bottom:6px;"></div>

<!-- Table -->
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF" id="outletTable">
    <thead>
    <tr>
        <th width="30">#</th>
        <th width="90">Code</th>
        <th>Outlet Name</th>
        <th width="90">Region</th>
        <th width="100" style="text-align:center;">Status</th>
        <th width="120" style="text-align:center;">Exclude Toggle</th>
        <th width="160">Excluded By / At</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($outlets)): ?>
    <tr><td colspan="7" class="alpro-center alpro-muted">No outlets synced yet. Run non-moving sync first.</td></tr>
    <?php endif; ?>
    <?php foreach ($outlets as $i => $o):
        $oid        = (int)$o['outlet_id'];
        $isExcluded = isset($excluded[$oid]);
        $exInfo     = $isExcluded ? $excluded[$oid] : null;
        $regionLow  = strtolower($o['region']);
    ?>
    <tr class="<?php echo $isExcluded ? 'row-excluded' : ''; ?>"
        id="row-<?php echo $oid; ?>"
        data-code="<?php echo htmlspecialchars(strtolower($o['outlet_code'])); ?>"
        data-region="<?php echo htmlspecialchars($regionLow); ?>"
        data-search="<?php echo htmlspecialchars(strtolower($o['outlet_code'] . ' ' . $o['comp_name'])); ?>">
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
        <td><b style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($o['outlet_code']); ?></b></td>
        <td style="font-size:13px;"><?php echo htmlspecialchars($o['comp_name'] ? $o['comp_name'] : '—'); ?></td>
        <td><span class="badge-region badge-<?php echo $regionLow; ?>"><?php echo htmlspecialchars($o['region']); ?></span></td>
        <td style="text-align:center;" id="badge-<?php echo $oid; ?>">
            <?php if ($isExcluded): ?>
            <span class="status-badge-excl">Excluded</span>
            <?php else: ?>
            <span class="status-badge-incl">Included</span>
            <?php endif; ?>
        </td>
        <td style="text-align:center;">
            <label class="tgl-switch" title="<?php echo $isExcluded ? 'Click to re-include' : 'Click to exclude'; ?>">
                <input type="checkbox"
                       <?php echo $isExcluded ? 'checked' : ''; ?>
                       onchange="toggleOutlet(<?php echo $oid; ?>, '<?php echo htmlspecialchars($o['outlet_code']); ?>', this)">
                <span class="tgl-slider"></span>
            </label>
        </td>
        <td id="info-<?php echo $oid; ?>" style="font-size:11px;">
            <?php if ($isExcluded): ?>
            <span style="color:#c62828;"><?php echo htmlspecialchars($exInfo['excluded_by']); ?></span><br>
            <span style="color:#aaa;"><?php echo substr($exInfo['created_at'], 0, 16); ?></span>
            <?php else: ?>
            <span style="color:#ccc;">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</fieldset>

<div style="margin-top:8px; font-size:11px; color:#aaa;">
    Showing <?php echo $total_outlets; ?> outlet(s) from pick list sync. Toggle anytime — changes take effect on next pick list generation.
</div>

</div>

<script>
function toggleOutlet(outletId, outletCode, cb) {
    var action = cb.checked ? 'exclude' : 'include';
    var badge  = document.getElementById('badge-' + outletId);
    var info   = document.getElementById('info-'  + outletId);
    var row    = document.getElementById('row-'   + outletId);

    cb.disabled = true;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'transferpicklist_outlet_exclusion.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        cb.disabled = false;
        try {
            var res = JSON.parse(xhr.responseText);
            if (!res.ok) {
                alert('Error: ' + (res.msg || 'Unknown error'));
                cb.checked = !cb.checked;
                return;
            }
            if (res.state === 'excluded') {
                row.classList.add('row-excluded');
                badge.innerHTML = '<span class="status-badge-excl">Excluded</span>';
                info.innerHTML  = '<span style="color:#c62828;">' + escHtml(res.by) + '</span><br><span style="color:#aaa;">' + escHtml(res.at) + '</span>';
            } else {
                row.classList.remove('row-excluded');
                badge.innerHTML = '<span class="status-badge-incl">Included</span>';
                info.innerHTML  = '<span style="color:#ccc;">&mdash;</span>';
            }
            updateSummary();
            filterTable(); // keep the Exclude filter accurate after a toggle
        } catch(e) {
            alert('Unexpected response. Please reload.');
            cb.checked = !cb.checked;
        }
    };
    xhr.send('ajax_toggle=1&outlet_id=' + outletId + '&outlet_code=' + encodeURIComponent(outletCode) + '&action=' + action);
}

function updateSummary() {
    var rows      = document.getElementById('outletTable').getElementsByTagName('tbody')[0].rows;
    var total     = rows.length;
    var excluded  = 0;
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].classList.contains('row-excluded')) { excluded++; }
    }
    document.getElementById('summaryExcluded').textContent = excluded;
    document.getElementById('summaryActive').textContent   = (total - excluded) + ' / ' + total;
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

function filterTable() {
    var q      = document.getElementById('searchInput').value.toLowerCase().trim();
    var outlet = document.getElementById('filterOutlet').value;
    var status = document.getElementById('filterStatus').value;
    var region = document.getElementById('filterRegion').value;
    var rows   = document.getElementById('outletTable').getElementsByTagName('tbody')[0].rows;
    var shown  = 0, total = 0;
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (!row.hasAttribute('data-code')) { continue; } // "no outlets" placeholder row
        total++;
        // Status read live from the row, so it follows toggles made on this page
        var rowStatus = row.classList.contains('row-excluded') ? 'excluded' : 'included';
        var ok = (!q      || (row.getAttribute('data-search') || '').indexOf(q) !== -1)
              && (!outlet || row.getAttribute('data-code') === outlet)
              && (!status || rowStatus === status)
              && (!region || row.getAttribute('data-region') === region);
        row.style.display = ok ? '' : 'none';
        if (ok) { shown++; }
    }
    var filtering = q || outlet || status || region;
    document.getElementById('filterCount').textContent = filtering ? ('Showing ' + shown + ' of ' + total + ' outlet(s)') : '';
}

function clearFilters() {
    document.getElementById('searchInput').value  = '';
    document.getElementById('filterOutlet').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterRegion').value = '';
    filterTable();
}
</script>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
