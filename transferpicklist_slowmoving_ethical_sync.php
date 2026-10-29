<?php
// ============================================================
// Slow Moving Ethical Sync — PHP trigger for the Python rewrite
// (hyper/slowmoving_ethical_sync.py). This file contains no sync logic
// of its own — it just runs the Python script as a subprocess and
// streams its output back. Manual/browser fallback alongside the
// Task Scheduler-driven hyper/slowmoving_ethical_sync.bat.
// ============================================================
require_once(__DIR__ . '/transferpicklist_env.php');
$_tpl_secret    = tplSyncSecret(); // from .env (TPL_SYNC_SECRET)
$_tpl_scheduled = isset($_GET['secret']) && $_GET['secret'] === $_tpl_secret;
if ($_tpl_scheduled) {
    @ini_set('zlib.output_compression', 0);
    @ini_set('output_buffering', 0);
    @apache_setenv('no-gzip', '1');
    header('Content-Encoding: none');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    header('Content-Type: text/html; charset=utf-8');
}
if (!$_tpl_scheduled) { require_once('../lock_adv.php'); }
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
if (!$_tpl_scheduled && (int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 30000);
set_time_limit(0);
ignore_user_abort(true);

// ============================================================
// BUILD THE PYTHON COMMAND
// ============================================================
// Local dev and production have Python installed under different user
// profiles/versions — try each known install, fall back to relying on PATH.
$_pythonCandidates = array(
    'C:\\Users\\ALPRO\\AppData\\Local\\Programs\\Python\\Python314\\python.exe',
    'C:\\Users\\app-octopus-a\\AppData\\Local\\Programs\\Python\\Python313\\python.exe',
);
$_pythonExe = 'python';
foreach ($_pythonCandidates as $_cand) {
    if (file_exists($_cand)) { $_pythonExe = $_cand; break; }
}
$_scriptPath = __DIR__ . '\\hyper\\slowmoving_ethical_sync.py';

$_args = array();
if (isset($_GET['outlet']) && $_GET['outlet'] !== '') {
    $_args[] = '--outlet';
    $_args[] = escapeshellarg($_GET['outlet']);
} elseif (isset($_GET['sync_all']) && $_GET['sync_all'] == '1') {
    $_args[] = '--sync-all';
}

$_cmd = escapeshellarg($_pythonExe) . ' ' . escapeshellarg($_scriptPath) . ' ' . implode(' ', $_args) . ' 2>&1';

if (!$_tpl_scheduled) {
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Slow Moving Ethical Sync</title>
<style>
    body { font-family: Consolas, "Courier New", monospace; background:#1b1e23; color:#e6e6e6; padding:20px; }
    h2 { color:#fff; }
    pre { white-space: pre-wrap; word-wrap: break-word; font-size:13px; line-height:1.4; }
</style>
</head>
<body>
<h2>Slow Moving Ethical Sync</h2>
<pre id="log">
<?php
}
flush();

echo "Running: {$_scriptPath}\n";
if (!empty($_args)) { echo "Args: " . implode(' ', $_args) . "\n"; }
echo str_repeat('-', 60) . "\n";
flush();

$_descriptors = array(
    0 => array('pipe', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
);
$_process = proc_open($_cmd, $_descriptors, $_pipes, __DIR__);

if (is_resource($_process)) {
    fclose($_pipes[0]);
    stream_set_blocking($_pipes[1], false);
    stream_set_blocking($_pipes[2], false);

    while (true) {
        $_status = proc_get_status($_process);
        $_out = stream_get_contents($_pipes[1]);
        $_err = stream_get_contents($_pipes[2]);
        if ($_out !== '' && $_out !== false) {
            echo $_tpl_scheduled ? $_out : htmlspecialchars($_out);
            flush();
            if (ob_get_level() > 0) { ob_end_flush(); flush(); }
        }
        if ($_err !== '' && $_err !== false) {
            echo $_tpl_scheduled ? $_err : htmlspecialchars($_err);
            flush();
            if (ob_get_level() > 0) { ob_end_flush(); flush(); }
        }
        if (!$_status['running']) { break; }
        usleep(300000);
    }

    fclose($_pipes[1]);
    fclose($_pipes[2]);
    $_exitCode = proc_close($_process);

    echo "\n" . str_repeat('-', 60) . "\n";
    echo $_exitCode === 0 ? "DONE (exit 0)\n" : "FAILED (exit {$_exitCode})\n";
} else {
    echo "ERROR: failed to start the Python sync process.\n";
}

if (!$_tpl_scheduled) {
?>
</pre>
</body>
</html>
<?php
}
