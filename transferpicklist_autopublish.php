<?php
/**
 * Auto-Publish Pick List
 * Publishes the latest draft outlet or warehouse picklist.
 *
 * Web (outlet    — run on 7th):  ?secret=<TPL_SYNC_SECRET>&type=outlet
 * Web (warehouse — run on 20th): ?secret=<TPL_SYNC_SECRET>&type=warehouse
 */

require_once(__DIR__ . '/transferpicklist_env.php');
$secret = tplSyncSecret(); // from .env (TPL_SYNC_SECRET)
$isCli  = (php_sapi_name() === 'cli');
if (!$isCli && (!isset($_GET['secret']) || $_GET['secret'] !== $secret)) {
    http_response_code(403);
    die("Forbidden.");
}

// type: 'outlet' or 'warehouse'
$type = isset($_GET['type']) ? trim($_GET['type']) : (isset($argv[1]) ? trim($argv[1]) : '');
if (!in_array($type, array('outlet', 'warehouse'))) {
    die("[ERROR] Invalid or missing type. Use ?type=outlet or ?type=warehouse\n");
}

$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include(__DIR__ . '/../common/index_adv.php');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "[ERROR] Database connection not available.\n";
    exit(1);
}
// PHP 8.1+ makes mysqli throw on query errors by default; this script's own
// error handling (the !$res checks below) expects the legacy
// return-false-on-error behavior, so restore it.
mysqli_report(MYSQLI_REPORT_OFF);

$timestamp = date('Y-m-d H:i:s');
$by_user   = 'System (Auto-Publish)';
// picklist_no uses the FULL suffix: -outlet / -warehouse (matches what
// transferpicklist_generate.php actually creates — the old abbreviated
// '-out'/'-war' pattern never matched any real picklist, so auto-publish
// silently found "nothing to publish" every single run).
$suffix    = $type === 'outlet' ? '-outlet' : '-warehouse';
$esc       = $conn->real_escape_string($suffix);

// Find latest draft picklist matching the suffix
$res = $conn->query("
    SELECT id, picklist_no
    FROM transferpicklist_picklist
    WHERE status = 'draft'
      AND picklist_no LIKE '%$esc'
    ORDER BY id DESC
    LIMIT 1
");

if (!$res || $res->num_rows === 0) {
    echo "[" . $timestamp . "] No draft $type picklist found (looking for suffix '$suffix'). Nothing to publish.\n";
    $connect = 0;
    include(__DIR__ . '/../common/index_adv.php');
    exit(0);
}

$pl          = $res->fetch_assoc();
$picklist_id = (int)$pl['id'];
$picklist_no = $pl['picklist_no'];

$conn->query("UPDATE transferpicklist_picklist SET status = 'published' WHERE id = $picklist_id");

if ($conn->affected_rows === 0) {
    echo "[" . $timestamp . "] [ERROR] Failed to publish $picklist_no.\n";
    $connect = 0;
    include(__DIR__ . '/../common/index_adv.php');
    exit(1);
}

$dayLabel = $type === 'outlet' ? '7th' : '20th';
$action   = 'Auto-published ' . $type . ' pick list on scheduled date (' . $dayLabel . ' of month)';
$stmt     = $conn->prepare("INSERT INTO transferpicklist_log (picklist_id, action, by_user, timestamp) VALUES (?,?,?,?)");
$stmt->bind_param('isss', $picklist_id, $action, $by_user, $timestamp);
$stmt->execute();
$stmt->close();

echo "[" . $timestamp . "] SUCCESS: $picklist_no published.\n";

// Warehouse only: "Confirm Return" IS the approval step (each procurement user
// ticks the items they agree to return). Anything nobody ticked by this cutoff
// never got approved, so soft-delete it now instead of leaving dead draft rows
// behind forever — recycle=1, not a hard DELETE, so an admin can still recover
// it if the cutoff was hit by mistake.
if ($type === 'warehouse') {
    $delRes = $conn->query("
        UPDATE transferpicklist_item
        SET recycle = 1
        WHERE picklist_id = $picklist_id
          AND priority_type = 'warehouse'
          AND return_confirmed = 0
          AND recycle = 0
    ");
    $deletedCount = $delRes ? $conn->affected_rows : 0;
    if ($deletedCount > 0) {
        $cleanupAction = "Auto-cleanup: soft-deleted $deletedCount unconfirmed (not ticked Confirm Return) warehouse item row(s) on scheduled date ($dayLabel of month)";
        $stmt2 = $conn->prepare("INSERT INTO transferpicklist_log (picklist_id, action, by_user, timestamp) VALUES (?,?,?,?)");
        $stmt2->bind_param('isss', $picklist_id, $cleanupAction, $by_user, $timestamp);
        $stmt2->execute();
        $stmt2->close();
    }
    echo "[" . $timestamp . "] Auto-cleanup: soft-deleted $deletedCount unconfirmed warehouse item row(s).\n";
}

$connect = 0;
include(__DIR__ . '/../common/index_adv.php');
exit(0);
?>
