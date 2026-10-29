<?php
require_once('../lock_adv.php');
if (file_exists(__DIR__ . '/config/tableau_config.php')) {
    require_once(__DIR__ . '/config/tableau_config.php');
} elseif (!defined('TABLEAU_API_USER')) {
    require_once(__DIR__ . '/transferpicklist_env.php');
    define('TABLEAU_API_USER', tplEnv('TABLEAU_API_USER'));
    define('TABLEAU_API_PASS', tplEnv('TABLEAU_API_PASS'));
}
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho < 3) { redirect('/odb/permission.php'); }

ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);

$tableauServer = "https://tableaualpro.qragoracloud.com";
$apiVersion    = "3.1";
$caCertPath    = __DIR__ . '/cacert.pem'; // was pointing to a nonexistent ../kpi/ folder
$VIEW_P3M      = '3615cb7d-4729-4ba3-8042-b2d8ab4854ae';

function trackerAuth($tableauServer, $apiVersion, $caCertPath) {
    $payload = json_encode(array('credentials' => array('name' => TABLEAU_API_USER, 'password' => TABLEAU_API_PASS, 'site' => array('contentUrl' => ''))));
    $ch = curl_init("$tableauServer/api/$apiVersion/auth/signin");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // cacert.pem is git-ignored — if a server doesn't have it, fall back to php.ini's curl.cainfo
    if (is_file($caCertPath)) { curl_setopt($ch, CURLOPT_CAINFO, $caCertPath); }
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch); curl_close($ch);
    $data = json_decode($resp, true);
    if (!isset($data['credentials']['token'])) { return null; }
    return array('token' => $data['credentials']['token'], 'siteId' => $data['credentials']['site']['id']);
}

$doFetch = isset($_POST['fetch']);
?>
<!DOCTYPE html>
<html>
<head>
<title>Past3Months Outlet List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>
<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Past3Months Outlet List from Tableau</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>
<?php include('transferpicklist_sidebar.php'); ?>
<div class="alpro-box">

<form method="post">
    <button type="submit" name="fetch" value="1" class="alpro-btn alpro-btn-blue">Fetch Outlet List from Tableau</button>
</form><br/>

<?php if ($doFetch):
    echo "Authenticating...<br/>"; ob_flush(); flush();
    $auth = trackerAuth($tableauServer, $apiVersion, $caCertPath);
    if (!$auth) { echo "<b style='color:red;'>Authentication failed.</b>"; exit; }
    $apiToken = $auth['token'];
    $siteId   = $auth['siteId'];
    echo "Authenticated. Fetching outlet list...<br/>"; ob_flush(); flush();

    $ch = curl_init("$tableauServer/api/$apiVersion/sites/$siteId/views/$VIEW_P3M/data");
    curl_setopt($ch, CURLOPT_HTTPHEADER,     array("X-Tableau-Auth: $apiToken"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (is_file($caCertPath)) { curl_setopt($ch, CURLOPT_CAINFO, $caCertPath); }
    curl_setopt($ch, CURLOPT_TIMEOUT,        600);
    $csv = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$csv || $err) { echo "<b style='color:red;'>Error: " . htmlspecialchars($err ? $err : 'Empty response') . "</b>"; exit; }
    if (strpos($csv, '<error') !== false) { echo "<b style='color:red;'>Tableau error: " . htmlspecialchars(strip_tags($csv)) . "</b>"; exit; }

    $csv   = str_replace(array("\r\n", "\r"), "\n", $csv);
    $lines = explode("\n", trim($csv));
    $hdr   = str_getcsv(array_shift($lines));

    $codes = array();
    foreach ($lines as $line) {
        if (trim($line) === '') { continue; }
        $row = str_getcsv($line);
        if (count($row) !== count($hdr)) { continue; }
        $data = array_combine($hdr, $row);
        if (isset($data['Store Short Name']))      { $code = trim($data['Store Short Name']); }
        elseif (isset($data['Store Short Name '])) { $code = trim($data['Store Short Name ']); }
        else                                       { $code = trim(reset($data)); }
        if ($code !== '') { $codes[$code] = 1; }
    }
    $codes = array_keys($codes);
    sort($codes);

    // Load master outlet table
    $master = array();
    $r = mysqli_query($conn, "SELECT id, code, comp_name, recycle FROM outlet ORDER BY code");
    while ($row = mysqli_fetch_assoc($r)) { $master[trim($row['code'])] = $row; }

    // Load past3months status table
    $p3mStatus = array();
    $r = mysqli_query($conn, "SELECT outlet_code, status, last_sync FROM transferpicklist_past3months_status");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $p3mStatus[$row['outlet_code']] = $row; } }

    $cntActive = $cntInactive = $cntMissing = 0;
    foreach ($codes as $code) {
        if (!isset($master[$code]))             { $cntMissing++; }
        elseif ($master[$code]['recycle'] == 1) { $cntInactive++; }
        else                                    { $cntActive++; }
    }

    echo "<div style='margin-bottom:14px;'>";
    echo "<span style='background:#e8f5e9;color:#2e7d32;font-weight:bold;padding:6px 14px;border-radius:4px;margin-right:8px;'>Active: $cntActive</span>";
    echo "<span style='background:#ffebee;color:#c62828;font-weight:bold;padding:6px 14px;border-radius:4px;margin-right:8px;'>Inactive (recycle=1): $cntInactive</span>";
    echo "<span style='background:#fff3e0;color:#e65100;font-weight:bold;padding:6px 14px;border-radius:4px;'>Not in Master: $cntMissing</span>";
    echo "</div>";

    echo "<b>" . count($codes) . " outlets total.</b><br/><br/>";

    echo "<fieldset><table class='myTable alpro-table' width='100%' cellpadding='4' cellspacing='1' bgcolor='#EFEFEF'>";
    echo "<tr><th width='30'>#</th><th>Outlet Code</th><th>Company Name</th><th width='130' class='alpro-center'>Master Status</th><th width='100' class='alpro-center'>In Status Table</th><th width='130' class='alpro-center'>Last Sync</th></tr>";
    foreach ($codes as $i => $code) {
        $m       = isset($master[$code]) ? $master[$code] : null;
        $recycle = $m ? (int)$m['recycle'] : -1;
        $rowBg   = $recycle === 1 ? "style='background:#fff8f8;'" : ($recycle === -1 ? "style='background:#fff8f0;'" : '');
        if ($recycle === 0)     { $badge = "<span style='background:#e8f5e9;color:#2e7d32;font-size:11px;font-weight:bold;padding:2px 7px;border-radius:3px;'>Active</span>"; }
        elseif ($recycle === 1) { $badge = "<span style='background:#ffebee;color:#c62828;font-size:11px;font-weight:bold;padding:2px 7px;border-radius:3px;'>Inactive</span>"; }
        else                    { $badge = "<span style='background:#fff3e0;color:#e65100;font-size:11px;font-weight:bold;padding:2px 7px;border-radius:3px;'>Not in Master</span>"; }
        $name = $m ? htmlspecialchars($m['comp_name']) : '<i style="color:#aaa;">—</i>';

        $p3m      = isset($p3mStatus[$code]) ? $p3mStatus[$code] : null;
        $inStatus = $p3m ? "<span style='background:#e3f2fd;color:#1565c0;font-size:11px;font-weight:bold;padding:2px 7px;border-radius:3px;'>Yes</span>" : "<span style='background:#f5f5f5;color:#bbb;font-size:11px;padding:2px 7px;border-radius:3px;'>Missing</span>";
        $lastSync = $p3m && $p3m['last_sync'] ? "<span style='font-size:11px;color:#555;'>" . substr($p3m['last_sync'], 0, 16) . "</span>" : '<span style="color:#aaa;font-size:11px;">—</span>';

        echo "<tr $rowBg><td class='alpro-center alpro-muted' style='font-size:11px;'>" . ($i+1) . "</td><td><b>" . htmlspecialchars($code) . "</b></td><td style='font-size:11px;color:#555;'>$name</td><td class='alpro-center'>$badge</td><td class='alpro-center'>$inStatus</td><td class='alpro-center'>$lastSync</td></tr>";
    }
    echo "</table></fieldset>";
endif; ?>

</div>
<?php $connect = 0; include('../common/index_adv.php'); ?>
</body>
</html>
