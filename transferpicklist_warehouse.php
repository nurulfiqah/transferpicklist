<?php
// ── Timing debug: add &debug=1 to the URL (admins only) to see how long each step takes ──
$_tplT0 = microtime(true); $_tplTimes = array(); $_tplLast = $_tplT0;
function tplT($label) { global $_tplTimes, $_tplLast; $now = microtime(true); $_tplTimes[] = array($label, $now - $_tplLast); $_tplLast = $now; }
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 120);
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
// PHP 8.1+ makes mysqli throw on query errors by default; this file's AJAX
// handlers expect the legacy return-false-on-error behavior (they check
// $conn->error / affected_rows rather than using try/catch), so restore it.
mysqli_report(MYSQLI_REPORT_OFF);
tplT('Login check + database connect (outer app)');
// Raise MySQL's default 1024-byte GROUP_CONCAT limit — the checkbox value
// (all row IDs behind one item_code) can easily exceed that for high-volume
// items, silently truncating which rows get selected for bulk actions.
$conn->query("SET SESSION group_concat_max_len = 1000000");
// Additionally open to departments flagged BU=1 in staff_department (same
// source of truth used for MinMax Campaign access) — does not affect
// $_wh_is_super_admin/$_wh_is_admin below, which stay tied to transferpicklist_autho only.
$_wh_is_bu = false;
if ((int)$department > 0) {
    $_wh_bu_res = $conn->query("SELECT BU FROM staff_department WHERE id = " . (int)$department . " LIMIT 1");
    if ($_wh_bu_res && $_wh_bu_row = $_wh_bu_res->fetch_assoc()) {
        $_wh_is_bu = ((int)$_wh_bu_row['BU'] === 1);
    }
}
// separate from the department/BU/autho-based grants above.
$_wh_is_individually_granted = in_array((int)$department, array(22, 29, 30), true); // WLD (HQ), WLD (Sabah), WLD (Sarawak)
$_wh_is_proc = ((int)$transferpicklist_autho === 1) || ((int)$department === 18) || $_wh_is_bu || $_wh_is_individually_granted;
if (!$_wh_is_proc) { redirect('/odb/permission.php'); }
$_wh_is_super_admin = (int)$transferpicklist_autho === 1;
$_wh_is_admin       = (int)$transferpicklist_autho === 1;

$filterPicklist   = isset($_GET['picklist_no']) ? trim($_GET['picklist_no']) : '';
$filterSearch     = isset($_GET['search'])      ? trim($_GET['search'])      : '';
$filterOutlet     = isset($_GET['outlet'])      ? trim($_GET['outlet'])      : '';
$filterStatus     = isset($_GET['status'])      ? trim($_GET['status'])      : '';
$filterOfficer    = isset($_GET['officer'])     ? trim($_GET['officer'])     : '';
$filterModel      = isset($_GET['model'])       ? trim($_GET['model'])       : '';
$filterReturn     = isset($_GET['hq_return'])   ? trim($_GET['hq_return'])   : '';
$filterOwnership  = isset($_GET['ownership'])   ? trim($_GET['ownership'])   : '';
$filterConfirm    = isset($_GET['confirm'])     ? trim($_GET['confirm'])     : '';

// NOTE: Column existence checks removed — columns (is_active, recycle) already exist in production.
// These information_schema queries were running on every page load and causing severe slowness.

// Helper: check if a picklist_no is published (read-only guard for AJAX)
function isPicklistPublished($conn, $picklistNo) {
    if ($picklistNo === '') { return false; }
    $esc = $conn->real_escape_string($picklistNo);
    $r = $conn->query("SELECT status FROM transferpicklist_picklist WHERE picklist_no = '$esc' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) { return $row['status'] === 'published'; }
    return false;
}

// Helper: resolve picklist_no to id (for logging)
function getPicklistIdByNo($conn, $picklistNo) {
    if ($picklistNo === '') { return 0; }
    $esc = $conn->real_escape_string($picklistNo);
    $r = $conn->query("SELECT id FROM transferpicklist_picklist WHERE picklist_no = '$esc' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) { return (int)$row['id']; }
    return 0;
}

// Helper: record who did what, on which pick list, into the existing audit log table
function logWhAction($conn, $picklistId, $action) {
    global $nama_staff;
    if ($picklistId <= 0) { return; }
    $by = isset($nama_staff) && $nama_staff !== '' ? $nama_staff : 'HQ';
    $ts = date('Y-m-d H:i:s');
    // action column is TEXT (max 65,535 bytes) — strict SQL mode rejects overlong
    // inserts outright, so trim defensively rather than lose the whole entry.
    if (strlen($action) > 65000) { $action = substr($action, 0, 64990) . '...(truncated)'; }
    $stmt = $conn->prepare("INSERT INTO transferpicklist_log (picklist_id, action, by_user, timestamp) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $picklistId, $action, $by, $ts);
    $stmt->execute();
    $stmt->close();
}

// Helper: turn a comma-separated code list into a readable list for log entries.
// Caps at a generous limit rather than truly unlimited, to stay safely under the
// TEXT column's 65KB size even for extreme bulk operations spanning many item codes.
function summarizeCodes($codesCsv, $limit = 3000) {
    if (!$codesCsv) { return '(none)'; }
    $codes = explode(',', $codesCsv);
    $count = count($codes);
    if ($count <= $limit) { return implode(', ', $codes); }
    return implode(', ', array_slice($codes, 0, $limit)) . ' +' . ($count - $limit) . ' more';
}

// AJAX: toggle "agree to return to warehouse" lock for one item_code's row group.
// Only return_confirmed=1 rows are shown to the outlet once the picklist is published —
// see the (i.priority_type != 'warehouse' OR i.return_confirmed = 1) guard in
// transferpicklist_outlet.php's read/confirm queries.
if (isset($_POST['ajax']) && $_POST['ajax'] === 'toggle_confirm') {
    ob_clean();
    header('Content-Type: application/json');
    $picklistNo = isset($_POST['picklist_no']) ? trim($_POST['picklist_no']) : '';
    $rawIds     = isset($_POST['ids']) ? $_POST['ids'] : '';
    $newState   = isset($_POST['state']) ? (int)$_POST['state'] : 0;
    if (isPicklistPublished($conn, $picklistNo)) { echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.')); exit; }
    $ids = array();
    foreach (explode(',', $rawIds) as $v) {
        $v = (int)trim($v);
        if ($v > 0) { $ids[] = $v; }
    }
    if (!empty($ids)) {
        $idList = implode(',', $ids);
        $state  = $newState ? 1 : 0;
        $codesRes = $conn->query("SELECT picklist_id, GROUP_CONCAT(DISTINCT item_code ORDER BY item_code) codes FROM transferpicklist_item WHERE id IN ($idList) GROUP BY picklist_id");
        $picklistId = 0;
        $codesCsv   = '';
        if ($codesRes && $cr = $codesRes->fetch_assoc()) { $picklistId = (int)$cr['picklist_id']; $codesCsv = $cr['codes']; }
        // recycle = 0: the checkbox's data-ids is built at page load and is NOT
        // refreshed after an in-place region delete (item-rgn-del-btn), so it can
        // still carry ids of rows deleted moments ago. Without this guard, ticking
        // would mark those deleted rows return_confirmed=1 and they'd resurface
        // anywhere that filters on return_confirmed but forgets recycle.
        $ok = $conn->query("UPDATE transferpicklist_item SET return_confirmed = $state WHERE id IN ($idList) AND recycle = 0");
        if (!$ok) {
            echo json_encode(array('ok' => false, 'msg' => 'Update failed: ' . $conn->error));
            exit;
        }
        logWhAction($conn, $picklistId, ($state ? 'Confirmed' : 'Unconfirmed') . ' return-to-warehouse for item(s): ' . summarizeCodes($codesCsv));
        echo json_encode(array('ok' => true, 'state' => $state));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'No items specified'));
    }
    exit;
}

// AJAX: bulk soft-delete items — inactivate in itemgrade, mark deleted in picklist_item
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_items') {
    ob_clean();
    header('Content-Type: application/json');
    $rawIds = isset($_POST['ids']) ? $_POST['ids'] : '';
    $ids = array();
    foreach (explode(',', $rawIds) as $v) {
        $v = (int)trim($v);
        if ($v > 0) { $ids[] = $v; }
    }
    if (!empty($ids)) {
        $idList = implode(',', $ids);
        // Same read-only rule as every other write here: never change a published picklist
        $pubRes = $conn->query("
            SELECT COUNT(*) AS c FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            WHERE i.id IN ($idList) AND p.status = 'published'
        ");
        if ($pubRes && (int)$pubRes->fetch_assoc()['c'] > 0) {
            echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.'));
            exit;
        }
        $conn->query("
            UPDATE transferpicklist_itemgrade ig
            JOIN transferpicklist_item i ON i.from_outlet_id = ig.outlet_id AND i.item_code = ig.item_code
            SET ig.is_active = 0
            WHERE i.id IN ($idList)
        ");
        $delOk = $conn->query("UPDATE transferpicklist_item SET recycle = 1, return_confirmed = 0 WHERE id IN ($idList)");
        if (!$delOk) {
            echo json_encode(array('ok' => false, 'msg' => 'Delete failed: ' . $conn->error));
            exit;
        }
        $logRes = $conn->query("SELECT picklist_id, COUNT(*) c, GROUP_CONCAT(DISTINCT item_code ORDER BY item_code) codes FROM transferpicklist_item WHERE id IN ($idList) GROUP BY picklist_id");
        if ($logRes) {
            while ($lr = $logRes->fetch_assoc()) {
                logWhAction($conn, (int)$lr['picklist_id'], 'Deleted ' . $lr['c'] . ' item row(s) via bulk selection — items: ' . summarizeCodes($lr['codes']));
            }
        }
        echo json_encode(array('ok' => true, 'deleted' => $ids));
    } else {
        echo json_encode(array('ok' => false));
    }
    exit;
}

// AJAX: change warehouse for selected item codes
if (isset($_POST['ajax']) && $_POST['ajax'] === 'change_warehouse_selected') {
    ob_clean();
    header('Content-Type: application/json');
    $newWhId    = (int)(isset($_POST['new_wh_id'])    ? $_POST['new_wh_id']    : 0);
    $picklistNo = isset($_POST['picklist_no'])         ? trim($_POST['picklist_no']) : '';
    $region     = isset($_POST['region'])              ? trim($_POST['region'])      : '';
    $rawCodes   = isset($_POST['item_codes'])          ? $_POST['item_codes']        : '';
    if (isPicklistPublished($conn, $picklistNo)) { echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.')); exit; }
    // Despite the POST field name, the checkboxes' values are the
    // transferpicklist_item row IDs (GROUP_CONCAT(i.id) — see the row-chk
    // checkbox markup and the delete_items handler above), not item_code
    // strings. Filtering by item_code here was matching zero rows.
    $itemIds = array();
    foreach (explode(',', $rawCodes) as $v) {
        $v = (int)trim($v);
        if ($v > 0) { $itemIds[] = $v; }
    }
    $regionAcmm = array(
        'peninsular' => array(1001, 1004),
        'sabah'      => array(1016, 1013),
        'sarawak'    => array(1005, 1002),
    );
    if ($newWhId > 0 && !empty($itemIds) && isset($regionAcmm[$region])) {
        $idList   = implode(',', $itemIds);
        $acmmList = implode(',', $regionAcmm[$region]);
        $escPl    = $conn->real_escape_string($picklistNo);
        $qtyRow = $conn->query("
            SELECT COALESCE(SUM(i.qty_transfer),0) AS total_qty
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList)
            WHERE i.id IN ($idList) AND p.picklist_no = '$escPl' AND i.priority_type = 'warehouse' AND i.recycle = 0
        ")->fetch_assoc();
        $oldWhRes = $conn->query("
            SELECT DISTINCT wh.code
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList)
            WHERE i.id IN ($idList) AND p.picklist_no = '$escPl' AND i.priority_type = 'warehouse' AND i.recycle = 0
        ");
        $oldWhCodes = array();
        if ($oldWhRes) { while ($owr = $oldWhRes->fetch_assoc()) { $oldWhCodes[] = $owr['code']; } }
        $codesRes = $conn->query("SELECT GROUP_CONCAT(DISTINCT item_code ORDER BY item_code) codes FROM transferpicklist_item WHERE id IN ($idList)");
        $itemCodesCsv = $codesRes ? $codesRes->fetch_assoc()['codes'] : '';
        $conn->query("
            UPDATE transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList)
            SET i.to_outlet_id = $newWhId
            WHERE i.id IN ($idList) AND p.picklist_no = '$escPl' AND i.priority_type = 'warehouse' AND i.recycle = 0
        ");
        $affected = $conn->affected_rows;
        $totalQty = (int)$qtyRow['total_qty'];
        $newWhRow = $conn->query("SELECT code, acmm_id FROM outlet WHERE id = $newWhId")->fetch_assoc();
        logWhAction($conn, getPicklistIdByNo($conn, $picklistNo),
            'Changed warehouse for ' . count($itemIds) . " item row(s) in $region from " . (empty($oldWhCodes) ? '?' : implode('/', $oldWhCodes)) . ' to ' . ($newWhRow ? $newWhRow['code'] : $newWhId)
            . " ($affected row(s), qty $totalQty) — items: " . summarizeCodes($itemCodesCsv));
        echo json_encode(array('ok' => true, 'affected' => $affected, 'total_qty' => $totalQty, 'wh_code' => $newWhRow ? $newWhRow['code'] : '', 'acmm_id' => $newWhRow ? $newWhRow['acmm_id'] : ''));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'Invalid params'));
    }
    exit;
}

// AJAX: change warehouse for a region
if (isset($_POST['ajax']) && $_POST['ajax'] === 'change_warehouse') {
    ob_clean();
    header('Content-Type: application/json');
    $newWhId    = (int)(isset($_POST['new_wh_id'])    ? $_POST['new_wh_id']    : 0);
    $picklistNo = isset($_POST['picklist_no']) ? trim($_POST['picklist_no']) : '';
    $region     = isset($_POST['region'])     ? trim($_POST['region'])     : '';
    if (isPicklistPublished($conn, $picklistNo)) { echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.')); exit; }
    if ($newWhId > 0 && $picklistNo !== '' && in_array($region, array('peninsular', 'sabah', 'sarawak'))) {
        $regionAcmm = array(
            'peninsular' => array(1001, 1004),
            'sabah'      => array(1016, 1013),
            'sarawak'    => array(1005, 1002),
        );
        $acmmList = implode(',', $regionAcmm[$region]);
        // Only match warehouse outlets (to_outlet_id) that belong to THIS region's acmm_id.
        // This prevents cross-region contamination — e.g. changing Peninsular warehouse
        // must NOT affect Sabah or Sarawak rows even if their outlet IDs happen to be
        // in the same numeric range.
        $esc = $conn->real_escape_string($picklistNo);
        // Get total qty before updating so we can report it
        $qtyRow = $conn->query("
            SELECT COALESCE(SUM(i.qty_transfer),0) AS total_qty
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList) AND wh.recycle = 0
            WHERE p.picklist_no = '$esc' AND i.priority_type = 'warehouse' AND i.recycle = 0
        ")->fetch_assoc();
        $totalQty = (int)$qtyRow['total_qty'];
        $oldWhRes = $conn->query("
            SELECT DISTINCT wh.code
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList) AND wh.recycle = 0
            WHERE p.picklist_no = '$esc' AND i.priority_type = 'warehouse' AND i.recycle = 0
        ");
        $oldWhCodes = array();
        if ($oldWhRes) { while ($owr = $oldWhRes->fetch_assoc()) { $oldWhCodes[] = $owr['code']; } }
        $conn->query("
            UPDATE transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            JOIN outlet wh ON wh.id = i.to_outlet_id AND wh.acmm_id IN ($acmmList) AND wh.recycle = 0
            SET i.to_outlet_id = $newWhId
            WHERE p.picklist_no = '$esc'
              AND i.priority_type = 'warehouse'
              AND i.recycle = 0
        ");
        $affected = $conn->affected_rows;
        $newWhRow = $conn->query("SELECT code FROM outlet WHERE id = $newWhId")->fetch_assoc();
        logWhAction($conn, getPicklistIdByNo($conn, $picklistNo),
            'Changed warehouse for ALL items in ' . $region . ' from ' . (empty($oldWhCodes) ? '?' : implode('/', $oldWhCodes)) . ' to ' . ($newWhRow ? $newWhRow['code'] : $newWhId)
            . " ($affected row(s), qty $totalQty)");
        echo json_encode(array('ok' => true, 'affected' => $affected, 'total_qty' => $totalQty, 'wh_code' => $newWhRow ? $newWhRow['code'] : ''));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'Invalid params'));
    }
    exit;
}

// AJAX: item detail by item_code + region
if (isset($_POST['ajax']) && $_POST['ajax'] === 'item_region_detail') {
    ob_clean();
    header('Content-Type: application/json');
    $itemCode   = isset($_POST['item_code'])   ? trim($_POST['item_code'])   : '';
    $picklistNo = isset($_POST['picklist_no']) ? trim($_POST['picklist_no']) : '';
    $region     = isset($_POST['region'])      ? trim($_POST['region'])      : '';
    $regionAcmm = array(
        'peninsular' => array(1001, 1004),
        'sabah'      => array(1016, 1013),
        'sarawak'    => array(1005, 1002),
    );
    if ($itemCode !== '' && $picklistNo !== '' && isset($regionAcmm[$region])) {
        $acmmList = implode(',', $regionAcmm[$region]);
        $whRes = $conn->query("SELECT id FROM outlet WHERE acmm_id IN ($acmmList) AND recycle = 0");
        $whIds = array(0);
        if ($whRes) { while ($r = $whRes->fetch_assoc()) { $whIds[] = (int)$r['id']; } }
        $whList = implode(',', $whIds);
        $escIc = $conn->real_escape_string($itemCode);
        $escPl = $conn->real_escape_string($picklistNo);
        $dtRes = $conn->query("
            SELECT o.code as from_code, o.acmm_id as from_acmm, SUM(i.qty_transfer) as qty
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            LEFT JOIN outlet o ON o.id = i.from_outlet_id
            WHERE p.picklist_no = '$escPl'
              AND i.item_code = '$escIc'
              AND i.to_outlet_id IN ($whList)
              AND i.recycle = 0
            GROUP BY o.code, o.acmm_id
            ORDER BY o.code
        ");
        $rows = array();
        if ($dtRes) { while ($r = $dtRes->fetch_assoc()) { $rows[] = $r; } }
        echo json_encode(array('ok' => true, 'rows' => $rows));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'Invalid params'));
    }
    exit;
}

// AJAX: delete all items by item_code + region
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_by_item_region') {
    ob_clean();
    header('Content-Type: application/json');
    $itemCode   = isset($_POST['item_code'])   ? trim($_POST['item_code'])   : '';
    $picklistNo = isset($_POST['picklist_no']) ? trim($_POST['picklist_no']) : '';
    $region     = isset($_POST['region'])      ? trim($_POST['region'])      : '';
    if (isPicklistPublished($conn, $picklistNo)) { echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.')); exit; }
    $regionAcmm = array(
        'peninsular' => array(1001, 1004),
        'sabah'      => array(1016, 1013),
        'sarawak'    => array(1005, 1002),
    );
    if ($itemCode !== '' && $picklistNo !== '' && isset($regionAcmm[$region])) {
        $acmmList = implode(',', $regionAcmm[$region]);
        $whRes = $conn->query("SELECT id FROM outlet WHERE acmm_id IN ($acmmList) AND recycle = 0");
        $whIds = array(0);
        if ($whRes) { while ($r = $whRes->fetch_assoc()) { $whIds[] = (int)$r['id']; } }
        $whList = implode(',', $whIds);
        $escIc  = $conn->real_escape_string($itemCode);
        $escPl  = $conn->real_escape_string($picklistNo);
        // inactivate itemgrade
        $conn->query("
            UPDATE transferpicklist_itemgrade ig
            JOIN transferpicklist_item i ON i.from_outlet_id = ig.outlet_id AND i.item_code = ig.item_code
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            SET ig.is_active = 0
            WHERE p.picklist_no = '$escPl' AND ig.item_code = '$escIc'
              AND i.to_outlet_id IN ($whList) AND i.recycle = 0
        ");
        $conn->query("
            UPDATE transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            SET i.recycle = 1, i.return_confirmed = 0
            WHERE p.picklist_no = '$escPl' AND i.item_code = '$escIc'
              AND i.to_outlet_id IN ($whList) AND i.recycle = 0
        ");
        $affected = $conn->affected_rows;
        logWhAction($conn, getPicklistIdByNo($conn, $picklistNo), "Deleted item $itemCode in $region ($affected row(s))");
        echo json_encode(array('ok' => true, 'affected' => $affected));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'Invalid params'));
    }
    exit;
}

// AJAX: delete ALL items for an entire region
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_region_all') {
    ob_clean();
    header('Content-Type: application/json');
    $picklistNo = isset($_POST['picklist_no']) ? trim($_POST['picklist_no']) : '';
    $region     = isset($_POST['region'])      ? trim($_POST['region'])      : '';
    if (isPicklistPublished($conn, $picklistNo)) { echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.')); exit; }
    $regionAcmm = array(
        'peninsular' => array(1001, 1004),
        'sabah'      => array(1016, 1013),
        'sarawak'    => array(1005, 1002),
    );
    if ($picklistNo !== '' && isset($regionAcmm[$region])) {
        $acmmList = implode(',', $regionAcmm[$region]);
        $whRes = $conn->query("SELECT id FROM outlet WHERE acmm_id IN ($acmmList) AND recycle = 0");
        $whIds = array(0);
        if ($whRes) { while ($r = $whRes->fetch_assoc()) { $whIds[] = (int)$r['id']; } }
        $whList = implode(',', $whIds);
        $escPl = $conn->real_escape_string($picklistNo);
        // Capture which item codes are affected before recycle=1 excludes them from lookup
        $codesRes = $conn->query("
            SELECT GROUP_CONCAT(DISTINCT i.item_code ORDER BY i.item_code) codes
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            WHERE p.picklist_no = '$escPl' AND i.to_outlet_id IN ($whList) AND i.recycle = 0
        ");
        $affectedCodes = $codesRes ? $codesRes->fetch_assoc()['codes'] : '';
        // inactivate itemgrade for all affected items
        $conn->query("
            UPDATE transferpicklist_itemgrade ig
            JOIN transferpicklist_item i ON i.from_outlet_id = ig.outlet_id AND i.item_code = ig.item_code
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            SET ig.is_active = 0
            WHERE p.picklist_no = '$escPl' AND i.to_outlet_id IN ($whList) AND i.recycle = 0
        ");
        $conn->query("
            UPDATE transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id
            SET i.recycle = 1, i.return_confirmed = 0
            WHERE p.picklist_no = '$escPl'
              AND i.to_outlet_id IN ($whList)
              AND i.recycle = 0
        ");
        $affected = $conn->affected_rows;
        logWhAction($conn, getPicklistIdByNo($conn, $picklistNo), "Deleted ALL items in $region ($affected row(s)) — items: " . summarizeCodes($affectedCodes));
        echo json_encode(array('ok' => true, 'affected' => $affected));
    } else {
        echo json_encode(array('ok' => false, 'msg' => 'Invalid params'));
    }
    exit;
}

// Load warehouse options per region (for dropdown)
$warehouseOptions = array('peninsular' => array(), 'sabah' => array(), 'sarawak' => array());
$whOptRes = $conn->query("SELECT id, code, comp_name, acmm_id FROM outlet WHERE acmm_id IN (1001,1004,1002,1005,1013,1016) AND recycle = 0 ORDER BY acmm_id");
if ($whOptRes) {
    while ($r = $whOptRes->fetch_assoc()) {
        $aid = (int)$r['acmm_id'];
        if (in_array($aid, array(1001, 1004)))       { $warehouseOptions['peninsular'][] = $r; }
        elseif (in_array($aid, array(1016, 1013)))   { $warehouseOptions['sabah'][]      = $r; }
        elseif (in_array($aid, array(1005, 1002)))   { $warehouseOptions['sarawak'][]    = $r; }
    }
}

// Load picklist list FIRST so we can set the default $filterPicklist before running heavy queries
$picklistOptions = array();
// EXISTS + the (picklist_id, priority_type, recycle) index = one index probe per
// pick list, instead of joining every warehouse item of every pick list (~4s on production).
$_plRes = $conn->query("
    SELECT p.id, p.picklist_no, p.status, p.timestamp
    FROM transferpicklist_picklist p
    WHERE EXISTS (SELECT 1 FROM transferpicklist_item i WHERE i.picklist_id = p.id AND i.priority_type = 'warehouse')
    ORDER BY p.timestamp DESC
");
if ($_plRes) { while ($r = $_plRes->fetch_assoc()) { $picklistOptions[] = $r; } }
tplT('Pick list dropdown (all warehouse pick lists)');

if ($filterPicklist === '' && !empty($picklistOptions)) {
    $filterPicklist = $picklistOptions[0]['picklist_no'];
}

// Resolve picklist ID — allows direct i.picklist_id = X filter, avoiding JOIN in heavy queries
$filterPicklistId = 0;
$isReadOnly = false;
foreach ($picklistOptions as $po) {
    if ($po['picklist_no'] === $filterPicklist) {
        $filterPicklistId = (int)$po['id'];
        $isReadOnly = ($po['status'] === 'published');
        break;
    }
}

// Detect current warehouse per region for the selected picklist (must run after $filterPicklistId is set)
$currentWhIds = array('peninsular' => 0, 'sabah' => 0, 'sarawak' => 0);
if ($filterPicklistId > 0) {
    $cwRes = $conn->query("
        SELECT wh.id as wh_id, wh.acmm_id, COUNT(*) as cnt
        FROM transferpicklist_item i
        JOIN outlet wh ON wh.id = i.to_outlet_id
        WHERE i.picklist_id = $filterPicklistId
          AND i.priority_type = 'warehouse'
          AND i.recycle = 0
          AND wh.acmm_id IN (1001,1004,1002,1005,1013,1016)
        GROUP BY wh.id, wh.acmm_id
        ORDER BY cnt DESC, wh.acmm_id ASC
    ");
    if ($cwRes) {
        while ($r = $cwRes->fetch_assoc()) {
            $aid = (int)$r['acmm_id'];
            if (in_array($aid, array(1001, 1004))     && !$currentWhIds['peninsular']) { $currentWhIds['peninsular'] = (int)$r['wh_id']; }
            elseif (in_array($aid, array(1016, 1013)) && !$currentWhIds['sabah'])      { $currentWhIds['sabah']      = (int)$r['wh_id']; }
            elseif (in_array($aid, array(1005, 1002)) && !$currentWhIds['sarawak'])    { $currentWhIds['sarawak']    = (int)$r['wh_id']; }
        }
    }
}
tplT('Current warehouse per region');

$perPage = tplPerPage(50);
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// Officer dropdown — Step 1: get distinct item_codes from transferpicklist_item (fast, indexed)
$officerList  = array();
$staffNameMap = array();
if ($filterPicklistId > 0) {
    $_icRes = $conn->query("SELECT DISTINCT item_code FROM transferpicklist_item WHERE picklist_id = $filterPicklistId AND priority_type = 'warehouse' AND recycle = 0");
} else {
    $_icRes = $conn->query("SELECT DISTINCT item_code FROM transferpicklist_item WHERE priority_type = 'warehouse' AND recycle = 0");
}
$_icCodes = array();
if ($_icRes) { while ($r = $_icRes->fetch_assoc()) { $_icCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
tplT('Item codes in this pick list');

// Step 2: P.Officer / Model / Return / Ownership dropdown options — ONE pass over simple
// for this pick list's item codes (was 4 separate lookups with the same long IN list).
$modelList     = array();
$returnList    = array();
$ownershipList = array();
if (!empty($_icCodes)) {
    $_optSets = array('procurement_officer' => array(), 'model' => array(), 'hq_return_policy' => array(), 'product_ownership' => array());
    $optRes = $conn->query("SELECT DISTINCT procurement_officer, model, hq_return_policy, product_ownership FROM simple WHERE item_code IN (" . implode(',', $_icCodes) . ")");
    if ($optRes) {
        while ($r = $optRes->fetch_assoc()) {
            foreach ($_optSets as $_col => $_set) {
                if ($r[$_col] !== null && $r[$_col] !== '') { $_optSets[$_col][$r[$_col]] = true; }
            }
        }
    }
    // Same order the old ORDER BY gave (case-insensitive A–Z). array_keys() turns numeric-looking
    // values (e.g. officer code "12345678") into ints — cast back to string so the dropdown's
    // `=== $filterOfficer` "selected" check still matches.
    foreach ($_optSets as $_col => $_set) { $_optSets[$_col] = array_map('strval', array_keys($_set)); usort($_optSets[$_col], 'strcasecmp'); }
    $officerList   = $_optSets['procurement_officer'];
    $modelList     = $_optSets['model'];
    $returnList    = $_optSets['hq_return_policy'];
    $ownershipList = $_optSets['product_ownership'];
}
tplT('P.Officer / Model / Return / Ownership dropdowns (1 item master lookup)');

// Step 3: get staff names using username column (same logic as original)
if (!empty($officerList)) {
    $staffNameMap = array();
    $stRes = $conn->query("SELECT nama_staff, username FROM staff WHERE recycle = 0");
    if ($stRes) {
        while ($st = $stRes->fetch_assoc()) {
            $ic = preg_replace('/\D/', '', $st['username']);
            if (strlen($ic) >= 8) {
                $id = substr($ic, 0, 6) . substr($ic, -2);
                $staffNameMap[$id] = $st['nama_staff'];
            }
        }
    }
}
tplT('Staff names (whole staff table)');


// Outlet dropdown — direct picklist_id filter, no JOIN to picklist table
$outletList = array();
if ($filterPicklistId > 0) {
    $olRes = $conn->query("
        SELECT DISTINCT o.code
        FROM transferpicklist_item i
        LEFT JOIN outlet o ON o.id = i.from_outlet_id
        WHERE i.picklist_id = $filterPicklistId AND i.priority_type = 'warehouse' AND i.recycle = 0
        ORDER BY o.code
    ");
} else {
    $olRes = $conn->query("
        SELECT DISTINCT o.code
        FROM transferpicklist_item i
        LEFT JOIN outlet o ON o.id = i.from_outlet_id
        WHERE i.priority_type = 'warehouse' AND i.recycle = 0
        ORDER BY o.code
    ");
}
if ($olRes) { while ($r = $olRes->fetch_assoc()) { $outletList[] = $r['code']; } }
tplT('Outlet dropdown');

// Build WHERE — use picklist_id directly when available (avoids JOIN to picklist table)
$whereClauses     = array("i.priority_type = 'warehouse'", "i.recycle = 0");
$needPicklistJoin = false;
if ($filterPicklistId > 0) {
    $whereClauses[] = "i.picklist_id = $filterPicklistId";
} elseif ($filterPicklist !== '') {
    $whereClauses[]   = "p.picklist_no = '" . $conn->real_escape_string($filterPicklist) . "'";
    $needPicklistJoin = true;
}
if ($filterOutlet !== '') {
    $escFo = $conn->real_escape_string($filterOutlet);
    $foRes = $conn->query("SELECT id FROM outlet WHERE code = '$escFo' AND recycle = 0 LIMIT 1");
    if ($foRes && $foRow = $foRes->fetch_assoc()) {
        $whereClauses[] = "i.from_outlet_id = " . (int)$foRow['id'];
    } else {
        $whereClauses[] = "1=0";
    }
}
if ($filterStatus !== '') { $whereClauses[] = "i.status = '" . $conn->real_escape_string($filterStatus) . "'"; }
if ($filterOfficer !== '') {
    $escOf   = $conn->real_escape_string($filterOfficer);
    $ofIcRes = $conn->query("SELECT DISTINCT item_code FROM simple WHERE procurement_officer = '$escOf'");
    $ofCodes = array();
    if ($ofIcRes) { while ($r = $ofIcRes->fetch_assoc()) { $ofCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
    $whereClauses[] = !empty($ofCodes) ? "i.item_code IN (" . implode(',', $ofCodes) . ")" : "1=0";
}
if ($filterSearch !== '') {
    $escSr   = $conn->real_escape_string($filterSearch);
    $srIcRes = $conn->query("SELECT DISTINCT item_code FROM simple WHERE item_code LIKE '$escSr%' OR name LIKE '%$escSr%'");
    $srCodes = array();
    if ($srIcRes) { while ($r = $srIcRes->fetch_assoc()) { $srCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
    $whereClauses[] = !empty($srCodes) ? "i.item_code IN (" . implode(',', $srCodes) . ")" : "1=0";
}
if ($filterModel !== '') {
    $escMd   = $conn->real_escape_string($filterModel);
    $mdIcRes = $conn->query("SELECT DISTINCT item_code FROM simple WHERE model = '$escMd'");
    $mdCodes = array();
    if ($mdIcRes) { while ($r = $mdIcRes->fetch_assoc()) { $mdCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
    $whereClauses[] = !empty($mdCodes) ? "i.item_code IN (" . implode(',', $mdCodes) . ")" : "1=0";
}
if ($filterReturn !== '') {
    $escRt   = $conn->real_escape_string($filterReturn);
    $rtIcRes = $conn->query("SELECT DISTINCT item_code FROM simple WHERE hq_return_policy = '$escRt'");
    $rtCodes = array();
    if ($rtIcRes) { while ($r = $rtIcRes->fetch_assoc()) { $rtCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
    $whereClauses[] = !empty($rtCodes) ? "i.item_code IN (" . implode(',', $rtCodes) . ")" : "1=0";
}
if ($filterOwnership !== '') {
    $escOw   = $conn->real_escape_string($filterOwnership);
    $owIcRes = $conn->query("SELECT DISTINCT item_code FROM simple WHERE product_ownership = '$escOw'");
    $owCodes = array();
    if ($owIcRes) { while ($r = $owIcRes->fetch_assoc()) { $owCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'"; } }
    $whereClauses[] = !empty($owCodes) ? "i.item_code IN (" . implode(',', $owCodes) . ")" : "1=0";
}
if ($filterConfirm !== '') { $whereClauses[] = "i.return_confirmed = " . ($filterConfirm === '1' ? '1' : '0'); }
$whereStr = implode(' AND ', $whereClauses);
$_plJoin  = $needPicklistJoin ? "JOIN transferpicklist_picklist p ON p.id = i.picklist_id" : "";
tplT('Apply filters');

// AJAX: Confirm Return for EVERY item matching the current filter (all pages), not just
// the 50 rows on screen. Posted to this page WITH the current query string, so $whereStr
// above is exactly the list the user is looking at.
if (isset($_POST['ajax']) && $_POST['ajax'] === 'confirm_filtered') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    if ($filterPicklistId <= 0) { echo json_encode(array('ok' => false, 'msg' => 'Please select a picklist first.')); exit; }
    if ($isReadOnly)            { echo json_encode(array('ok' => false, 'msg' => 'Picklist is published. Read-only.')); exit; }
    $state = (isset($_POST['state']) && (int)$_POST['state'] === 1) ? 1 : 0;
    $cRes  = $conn->query("SELECT COUNT(DISTINCT i.item_code) AS items FROM transferpicklist_item i $_plJoin WHERE $whereStr");
    $items = $cRes ? (int)$cRes->fetch_assoc()['items'] : 0;
    $ok    = $conn->query("UPDATE transferpicklist_item i $_plJoin SET i.return_confirmed = $state WHERE $whereStr");
    if (!$ok) { echo json_encode(array('ok' => false, 'msg' => 'Update failed: ' . $conn->error)); exit; }
    $rowsChanged = $conn->affected_rows;
    $filterDesc  = array();
    foreach (array('outlet' => $filterOutlet, 'officer' => $filterOfficer, 'model' => $filterModel, 'return' => $filterReturn,
                   'ownership' => $filterOwnership, 'status' => $filterStatus, 'search' => $filterSearch, 'confirm' => $filterConfirm) as $_k => $_v) {
        if ($_v !== '') { $filterDesc[] = "$_k=$_v"; }
    }
    logWhAction($conn, $filterPicklistId, ($state ? 'Confirmed' : 'Unconfirmed') . " return-to-warehouse for ALL $items item(s) in filter"
        . ($filterDesc ? ' (' . implode(', ', $filterDesc) . ')' : ' (no filter)') . " — $rowsChanged row(s) changed");
    echo json_encode(array('ok' => true, 'items' => $items, 'rows' => $rowsChanged, 'state' => $state));
    exit;
}

// Export CSV — joins simple directly, no preload needed
if (isset($_GET['export'])) {
    ob_end_clean();
    $expRes = $conn->query("
        SELECT i.item_code, i.qty_transfer, i.status,
               p.picklist_no,
               o.code as from_code, o.acmm_id as from_acmm,
               wh.code as wh_code,
               s.name as item_name, s.model, s.hq_return_policy, s.product_ownership, s.procurement_officer
        FROM transferpicklist_item i
        JOIN transferpicklist_picklist p ON p.id = i.picklist_id
        LEFT JOIN outlet o  ON o.id  = i.from_outlet_id
        LEFT JOIN outlet wh ON wh.id = i.to_outlet_id
        LEFT JOIN simple s  ON s.item_code = i.item_code
        WHERE $whereStr
        ORDER BY o.code, i.item_code
    ");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="warehouse_returns_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('#','Pick List','From Outlet','Warehouse','Item Code','Description','Model','HQ Return Policy','Product Ownership','Procurement Officer','Qty','Status'));
    $i = 1;
    while ($r = $expRes->fetch_assoc()) {
        fputcsv($out, array(
            $i++, $r['picklist_no'], $r['from_code'], $r['wh_code'],
            $r['item_code'],
            $r['item_name'] ? $r['item_name'] : '',
            $r['model'] ? $r['model'] : '',
            $r['hq_return_policy'] ? $r['hq_return_policy'] : '',
            $r['product_ownership'] ? $r['product_ownership'] : '',
            $r['procurement_officer'] ? $r['procurement_officer'] : '',
            $r['qty_transfer'], ucfirst($r['status'])
        ));
    }
    fclose($out);
    exit;
}

$totalItems = 0;
$cntRes = $conn->query("
    SELECT COUNT(DISTINCT i.item_code) as cnt
    FROM transferpicklist_item i $_plJoin
    WHERE $whereStr
");
if ($cntRes) { $totalItems = (int)$cntRes->fetch_assoc()['cnt']; }
$totalPages = max(1, ceil($totalItems / $perPage));
// Header "confirm all" checkbox: ticked only when EVERY row in the current filter is confirmed
$allFilteredConfirmed = false;
if ($totalItems > 0 && !$isReadOnly && $filterPicklistId > 0) {
    $acRes = $conn->query("SELECT MIN(i.return_confirmed) AS m FROM transferpicklist_item i $_plJoin WHERE $whereStr");
    $allFilteredConfirmed = $acRes && ((int)$acRes->fetch_assoc()['m'] === 1);
}
tplT('Count items');

// Stats
$pendingCount     = 0;
$transferredCount = 0;
$_statsWhere = "i.priority_type = 'warehouse' AND i.recycle = 0"
    . ($filterPicklistId > 0 ? " AND i.picklist_id = $filterPicklistId" : ($filterPicklist !== '' ? " AND p.picklist_no = '" . $conn->real_escape_string($filterPicklist) . "'" : ""));
$_statsJoin  = ($filterPicklistId <= 0 && $filterPicklist !== '') ? "JOIN transferpicklist_picklist p ON p.id = i.picklist_id" : "";
$stRes = $conn->query("
    SELECT i.status, COUNT(*) as cnt
    FROM transferpicklist_item i $_statsJoin
    WHERE $_statsWhere
    GROUP BY i.status
");
if ($stRes) {
    while ($r = $stRes->fetch_assoc()) {
        if ($r['status'] === 'pending')     { $pendingCount     = (int)$r['cnt']; }
        if ($r['status'] === 'transferred') { $transferredCount = (int)$r['cnt']; }
    }
}
tplT('Stats (pending / transferred)');

$rows = array();
$res = $conn->query("
    SELECT i.item_code,
           SUM(CASE WHEN wh.acmm_id IN (1001,1004) THEN i.qty_transfer ELSE 0 END) as pen_qty,
           SUM(CASE WHEN wh.acmm_id IN (1016,1013) THEN i.qty_transfer ELSE 0 END) as sab_qty,
           SUM(CASE WHEN wh.acmm_id IN (1005,1002) THEN i.qty_transfer ELSE 0 END) as sar_qty,
           GROUP_CONCAT(DISTINCT CASE WHEN wh.acmm_id IN (1001,1004) THEN wh.acmm_id ELSE NULL END ORDER BY wh.acmm_id) as pen_wh_ids,
           GROUP_CONCAT(DISTINCT CASE WHEN wh.acmm_id IN (1016,1013) THEN wh.acmm_id ELSE NULL END ORDER BY wh.acmm_id) as sab_wh_ids,
           GROUP_CONCAT(DISTINCT CASE WHEN wh.acmm_id IN (1005,1002) THEN wh.acmm_id ELSE NULL END ORDER BY wh.acmm_id) as sar_wh_ids,
           GROUP_CONCAT(i.id ORDER BY i.id) as item_ids,
           MIN(i.return_confirmed) as return_confirmed
    FROM transferpicklist_item i $_plJoin
    LEFT JOIN outlet wh ON wh.id = i.to_outlet_id
    WHERE $whereStr
    GROUP BY i.item_code
    ORDER BY i.item_code
    LIMIT $perPage OFFSET $offset
");
$pageItemCodes = array();
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $pageItemCodes[] = "'" . $conn->real_escape_string($r['item_code']) . "'";
        $rows[] = $r;
    }
}
tplT('Load 50 rows (grouped by item)');

// Load simple details for only the current page's item codes (max 200)
$outlet_arr = array();
if (!empty($pageItemCodes)) {
    $saRes = $conn->query("
        SELECT item_code, name, model, hq_return_policy, product_ownership, procurement_officer, reorder_point
        FROM simple WHERE item_code IN (" . implode(',', $pageItemCodes) . ")
    ");
    if ($saRes) { while ($r = $saRes->fetch_assoc()) { $outlet_arr[$r['item_code']] = $r; } }
}
tplT('Item details for the 50 rows');

// Enrich rows with simple details
foreach ($rows as &$r) {
    $si = isset($outlet_arr[$r['item_code']]) ? $outlet_arr[$r['item_code']] : array();
    $r['item_name']           = isset($si['name'])                ? $si['name']                : '';
    $r['model']               = isset($si['model'])               ? $si['model']               : '';
    $r['hq_return_policy']    = isset($si['hq_return_policy'])    ? $si['hq_return_policy']    : '';
    $r['product_ownership']   = isset($si['product_ownership'])   ? $si['product_ownership']   : '';
    $r['procurement_officer'] = isset($si['procurement_officer']) ? $si['procurement_officer'] : '';
    $r['officer_name']        = isset($si['procurement_officer']) && isset($staffNameMap[$si['procurement_officer']]) ? $staffNameMap[$si['procurement_officer']] : '';
    $r['reorder_point']       = isset($si['reorder_point'])       ? $si['reorder_point']       : '';
}
unset($r);

// Sort current page by Description A-Z (blank descriptions sort last)
usort($rows, function($a, $b) {
    $an = $a['item_name'] !== '' ? $a['item_name'] : null;
    $bn = $b['item_name'] !== '' ? $b['item_name'] : null;
    if ($an === null && $bn === null) { return 0; }
    if ($an === null) { return 1; }
    if ($bn === null) { return -1; }
    return strcasecmp($an, $bn);
});

function buildWhUrl($params) {
    $base = 'transferpicklist_warehouse.php';
    return $params ? $base . '?' . http_build_query($params) : $base;
}
$currentParams = array();
if ($filterPicklist  !== '') { $currentParams['picklist_no'] = $filterPicklist; }
if ($filterOutlet    !== '') { $currentParams['outlet']      = $filterOutlet; }
if ($filterStatus    !== '') { $currentParams['status']      = $filterStatus; }
if ($filterOfficer   !== '') { $currentParams['officer']     = $filterOfficer; }
if ($filterModel     !== '') { $currentParams['model']       = $filterModel; }
if ($filterReturn    !== '') { $currentParams['hq_return']   = $filterReturn; }
if ($filterOwnership !== '') { $currentParams['ownership']   = $filterOwnership; }
if ($filterConfirm   !== '') { $currentParams['confirm']     = $filterConfirm; }
if ($filterSearch    !== '') { $currentParams['search']      = $filterSearch; }
?>
<!DOCTYPE html>
<html>
<head>
<title>Warehouse Returns</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
<style>
.stat-box { display:inline-block; background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:10px 18px; margin-right:10px; margin-bottom:10px; text-align:center; min-width:120px; }
.stat-box .stat-num { font-size:20px; font-weight:bold; color:#1565c0; }
.stat-box .stat-lbl { font-size:11px; color:#888; margin-top:2px; }
.tbl-wrap { overflow-x:auto; }
table.wh-tbl { width:100%; border-collapse:collapse; font-size:12px; }
table.wh-tbl th { background:#e8ecef; color:#2c3e50; padding:8px 10px; text-align:left; white-space:nowrap; border-bottom:2px solid #cfd8dc; }
table.wh-tbl td { padding:6px 10px; border-bottom:1px solid #eee; vertical-align:middle; text-align:left; }
table.wh-tbl tr:hover td { background:#f5f8ff; }
.badge-hq  { background:#1565c0; color:#fff; border-radius:3px; padding:2px 6px; font-size:11px; font-weight:bold; }
.badge-nhq { background:#e67e22; color:#fff; border-radius:3px; padding:2px 6px; font-size:11px; font-weight:bold; }
.filter-bar { display:flex; gap:6px; align-items:flex-end; flex-wrap:wrap; margin-bottom:10px; background:#fff; border:1px solid #ddd; border-radius:6px; padding:8px 10px; }
.filter-bar label { display:block; font-size:11px; color:#888; margin-bottom:2px; white-space:nowrap; }
.filter-bar select, .filter-bar input[type=text] { padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; height:32px; box-sizing:border-box; }
.pagination { margin-top:12px; display:flex; gap:4px; flex-wrap:wrap; align-items:center; }
.pagination a, .pagination span { padding:4px 10px; border:1px solid #ddd; border-radius:4px; font-size:12px; text-decoration:none; color:#333; }
.pagination a:hover { background:#eaf0fb; }
.pagination .active { background:#1565c0; color:#fff; border-color:#1565c0; font-weight:bold; }
</style>
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Warehouse Returns</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">


    <!-- Filter (applies automatically — see "Auto-apply filters" in the script below) -->
    <form method="get" action="transferpicklist_warehouse.php" id="wh-filter-form">
        <div class="filter-bar">
            <div>
                <label>Pick List</label>
                <select name="picklist_no" style="width:185px;">
                    <option value="">-- All Pick Lists --</option>
                    <?php foreach ($picklistOptions as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt['picklist_no']); ?>" <?php echo $filterPicklist === $opt['picklist_no'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($opt['picklist_no']); ?>
                        (<?php echo date('d M Y', strtotime($opt['timestamp'])); ?>)
                        <?php echo $opt['status'] === 'draft' ? '[Draft]' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Model</label>
                <select name="model" style="width:120px;">
                    <option value="">-- All Models --</option>
                    <?php foreach ($modelList as $md): ?>
                    <option value="<?php echo htmlspecialchars($md); ?>" <?php echo $filterModel === $md ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($md); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Return</label>
                <select name="hq_return" style="width:100px;">
                    <option value="">-- All --</option>
                    <?php foreach ($returnList as $rt): ?>
                    <option value="<?php echo htmlspecialchars($rt); ?>" <?php echo $filterReturn === $rt ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($rt); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Product Ownership</label>
                <select name="ownership" style="width:120px;">
                    <option value="">-- All --</option>
                    <?php foreach ($ownershipList as $ow): ?>
                    <option value="<?php echo htmlspecialchars($ow); ?>" <?php echo $filterOwnership === $ow ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ow); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Procurement Officer</label>
                <select name="officer" style="width:130px;">
                    <option value="">-- All Officers --</option>
                    <?php foreach ($officerList as $of): ?>
                    <option value="<?php echo htmlspecialchars($of); ?>" <?php echo $filterOfficer === $of ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(isset($staffNameMap[$of]) && $staffNameMap[$of] !== '' ? $staffNameMap[$of] : $of); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Confirm Return</label>
                <select name="confirm" style="width:110px;">
                    <option value="">-- All --</option>
                    <option value="1" <?php echo $filterConfirm === '1' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="0" <?php echo $filterConfirm === '0' ? 'selected' : ''; ?>>Not Confirmed</option>
                </select>
            </div>
            <div style="flex:1; min-width:140px;">
                <label>Search (Item Code / Description)</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Item code or name..." style="width:100%;">
            </div>
            <div style="margin-left:auto;"><!-- push Search / Reset / Export to the far right -->
                <label>&nbsp;</label>
                <div style="display:flex; gap:6px;">
                    <button type="submit" class="alpro-btn alpro-btn-blue" style="height:32px; padding:0 12px; font-size:13px;">Search</button>
                    <a href="transferpicklist_warehouse.php" class="alpro-btn alpro-btn-grey" style="height:32px; padding:0 10px; line-height:32px; font-size:13px;">Reset</a>
                    <?php
                    $expParams = $currentParams;
                    $expParams['export'] = '1';
                    ?>
                    <a href="<?php echo buildWhUrl($expParams); ?>" class="alpro-btn alpro-btn-grey" style="height:32px; padding:0 10px; line-height:32px; font-size:13px; color:#1565c0;">&#8595; Export CSV</a>
                </div>
            </div>
        </div>
    </form>

    <?php if ($isReadOnly): ?>
    <div style="margin-bottom:8px; display:inline-flex; align-items:center; gap:8px; background:#fff3e0; border:1px solid #ffb300; border-radius:6px; padding:6px 14px; font-size:13px; color:#e65100; font-weight:600;">
        &#128274; Read Only — This picklist is published. No changes allowed.
    </div>
    <?php endif; ?>

    <div class="tbl-wrap">
    <table class="wh-tbl">
        <thead>
            <tr>
                <th>#</th>
                <?php if (!$isReadOnly): ?><th style="text-align:center;"><input type="checkbox" id="chk-all" title="Select All"></th><?php endif; ?>
                <th>Item Code</th>
                <th>Description</th>
                <th>Model</th>
                <th>Return</th>
                <th>Product Ownership</th>
                <th>P.Officer</th>
                <th style="text-align:center;">Reorder Point</th>
                <th style="text-align:center;">
                    Confirm Return
                    <?php if (!$isReadOnly && $totalItems > 0 && $filterPicklistId > 0): ?>
                    <div style="margin-top:4px; font-weight:normal; font-size:11px; white-space:nowrap;">
                        <label title="Tick / untick Confirm Return for ALL <?php echo number_format($totalItems); ?> item(s) matching the current filter — all pages, not just this one" style="cursor:pointer;">
                            <input type="checkbox" id="confirm-all-filtered" data-items="<?php echo (int)$totalItems; ?>"
                                   <?php echo $allFilteredConfirmed ? 'checked' : ''; ?>
                                   style="width:15px; height:15px; cursor:pointer; vertical-align:middle;">
                            All (<?php echo number_format($totalItems); ?>)
                        </label>
                    </div>
                    <?php endif; ?>
                </th>
                <?php foreach (array('peninsular' => 'Semenanjung', 'sabah' => 'Sabah', 'sarawak' => 'Sarawak') as $thRgn => $thLabel): ?>
                <th style="text-align:center;">
                    <?php echo $thLabel; ?>
                    <?php if (!empty($warehouseOptions[$thRgn]) && !$isReadOnly): ?>
                    <div style="margin-top:4px; display:flex; align-items:center; justify-content:center; gap:4px;">
                        <select class="tbl-wh-select" data-region="<?php echo $thRgn; ?>"
                                style="font-size:11px; padding:2px 4px; border:1px solid #888; border-radius:3px; background:#fff; color:#333; max-width:70px;">
                            <?php foreach ($warehouseOptions[$thRgn] as $wh): ?>
                            <option value="<?php echo (int)$wh['id']; ?>" <?php echo $currentWhIds[$thRgn] === (int)$wh['id'] ? 'selected' : ''; ?>><?php echo (int)$wh['acmm_id']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="rgn-del-all-btn" data-region="<?php echo $thRgn; ?>" data-label="<?php echo $thLabel; ?>"
                                style="background:none; border:none; cursor:pointer; padding:1px 2px; line-height:1; font-size:18px;" title="Remove ALL <?php echo $thLabel; ?> items">
                            &#128465;
                        </button>
                    </div>
                    <span class="tbl-wh-msg" data-region="<?php echo $thRgn; ?>" style="font-size:10px; color:#a5d6a7; display:none;"></span>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="13" style="text-align:center; color:#999; padding:20px;">No warehouse return items found.</td></tr>
        <?php endif; ?>
        <?php $rowNum = $offset + 1; foreach ($rows as $r): ?>
        <tr id="row-<?php echo htmlspecialchars($r['item_code']); ?>">
            <td style="color:#999; font-size:11px;"><?php echo $rowNum++; ?></td>
            <?php if (!$isReadOnly): ?>
            <td style="text-align:center;">
                <input type="checkbox" class="row-chk" value="<?php echo htmlspecialchars($r['item_ids']); ?>" style="width:15px; height:15px; cursor:pointer; vertical-align:middle;">
            </td>
            <?php endif; ?>
            <td style="font-family:monospace;"><?php echo htmlspecialchars($r['item_code']); ?></td>
            <td><?php echo htmlspecialchars($r['item_name'] ? $r['item_name'] : '—'); ?></td>
            <td>
                <?php if ($r['model'] === 'Store/HQ Order'): ?>
                    <span class="badge-nhq">Non-HQ</span>
                <?php else: ?>
                    <span class="badge-hq">HQ</span>
                <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($r['hq_return_policy'] ? $r['hq_return_policy'] : '—'); ?></td>
            <td><?php echo htmlspecialchars($r['product_ownership'] ? $r['product_ownership'] : '—'); ?></td>
            <td>
                <?php
                $displayOfficer = !empty($r['officer_name']) ? $r['officer_name'] : ($r['procurement_officer'] ? $r['procurement_officer'] : '—');
                echo htmlspecialchars($displayOfficer);
                ?>
            </td>
            <td style="text-align:center; color:#555;"><?php echo $r['reorder_point'] !== '' ? number_format((float)$r['reorder_point'], 1) : '—'; ?></td>
            <td style="text-align:center;">
                <input type="checkbox" class="confirm-chk" data-ids="<?php echo htmlspecialchars($r['item_ids']); ?>"
                       <?php echo ((int)$r['return_confirmed'] === 1) ? 'checked' : ''; ?> <?php echo $isReadOnly ? 'disabled' : ''; ?>
                       title="Tick to agree this item returns to warehouse when published"
                       style="width:16px; height:16px; cursor:<?php echo $isReadOnly ? 'not-allowed' : 'pointer'; ?>; vertical-align:middle;">
            </td>
            <?php foreach (array('pen' => 'peninsular', 'sab' => 'sabah', 'sar' => 'sarawak') as $qKey => $qRgn): ?>
            <td style="text-align:center; font-weight:bold;">
                <?php $qVal = (int)$r[$qKey . '_qty']; if ($qVal > 0): ?>
                <?php
                $detParams = array(
                    'picklist_no'  => $filterPicklist,
                    'item_code'    => $r['item_code'],
                    'region'       => $qRgn,
                    'name'         => $r['item_name'],
                    'model'        => $r['model'],
                    'hq'           => $r['hq_return_policy'],
                    'ownership'    => $r['product_ownership'],
                    'officer_code' => $r['procurement_officer'],
                    'officer_name' => $r['officer_name'],
                    'reorder'      => $r['reorder_point'],
                );
                ?>
                <?php
                $whIdsKey = $qKey . '_wh_ids';
                $whIds    = isset($r[$whIdsKey]) && $r[$whIdsKey] !== '' ? explode(',', $r[$whIdsKey]) : array();
                ?>
                <div style="display:inline-flex; align-items:center; gap:6px; flex-direction:column;">
                    <div style="display:inline-flex; align-items:center; gap:8px;">
                        <a href="transferpicklist_warehouse_detail.php?<?php echo htmlspecialchars(http_build_query($detParams)); ?>"
                           target="_blank" style="color:#c62828; text-decoration:none; border-bottom:1px dashed #c62828; font-weight:bold;"><?php echo number_format($qVal); ?></a>
                        <?php if (!$isReadOnly): ?>
                        <button class="item-rgn-del-btn" data-ic="<?php echo htmlspecialchars($r['item_code']); ?>" data-region="<?php echo $qRgn; ?>"
                                style="background:none; border:none; cursor:pointer; padding:0; line-height:1; font-size:18px; opacity:0.7;" title="Remove <?php echo $qRgn; ?> qty for this item">
                            &#128465;
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($whIds)): ?>
                    <div style="display:flex; gap:3px; flex-wrap:wrap; justify-content:center;">
                        <?php foreach ($whIds as $whId): ?>
                        <span style="background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; border-radius:3px; padding:1px 5px; font-size:10px; font-weight:bold; font-family:monospace;"><?php echo htmlspecialchars(trim($whId)); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?><span style="color:#ccc;">—</span><?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Bulk action bar — hidden when read-only -->
    <?php if (!$isReadOnly): ?>
    <div id="bulk-bar" style="display:flex; margin-top:10px; padding:10px 14px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; align-items:center; gap:12px; flex-wrap:wrap;">
        <span id="bulk-count" style="font-size:13px; color:#555; margin-right:auto;">0 item(s) selected</span>

        <!-- Change warehouse for selected -->
        <div style="display:flex; align-items:center; gap:6px; border-left:2px solid #ffc107; padding-left:12px;">
            <span style="font-size:12px; color:#555; white-space:nowrap;">Change Warehouse:</span>
            <select id="bulk-region-sel" style="font-size:12px; padding:4px 6px; border:1px solid #ccc; border-radius:4px; height:30px; min-width:120px;">
                <option value="">-- Region --</option>
                <option value="peninsular">Semenanjung</option>
                <option value="sabah">Sabah</option>
                <option value="sarawak">Sarawak</option>
            </select>
            <select id="bulk-wh-sel" style="font-size:12px; padding:4px 6px; border:1px solid #ccc; border-radius:4px; height:30px; min-width:110px;" disabled>
                <option value="">-- Warehouse --</option>
            </select>
            <button id="bulk-changewh-btn" style="background:#1565c0; color:#fff; border:none; cursor:pointer; padding:5px 12px; border-radius:4px; font-size:12px; font-weight:bold; height:30px; white-space:nowrap;">
                &#8646; Apply
            </button>
        </div>

        <button id="bulk-delete-btn" style="background:#c62828; color:#fff; border:none; cursor:pointer; padding:6px 14px; border-radius:4px; font-size:13px; font-weight:bold;">
            &#128465; Delete Selected
        </button>
    </div>
    <?php endif; ?>

    <!-- Delete confirm modal -->
    <div id="del-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9000;"></div>
    <div id="del-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:8px; box-shadow:0 8px 32px rgba(0,0,0,0.25); z-index:9001; width:580px; max-width:96vw; max-height:80vh; flex-direction:column;">
        <div style="background:#c62828; color:#fff; padding:12px 18px; border-radius:8px 8px 0 0; display:flex; align-items:center; justify-content:space-between;">
            <span style="font-weight:bold; font-size:14px;">&#128465; Move to Rubbish — Confirm</span>
            <button onclick="closeDelModal()" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div id="del-modal-body" style="overflow-y:auto; padding:14px 18px; flex:1; max-height:50vh;"></div>
        <div style="padding:12px 18px; border-top:1px solid #eee; display:flex; gap:10px; justify-content:flex-end;">
            <button onclick="closeDelModal()" style="padding:7px 20px; border:1px solid #ccc; border-radius:4px; background:#fff; cursor:pointer;">Cancel</button>
            <button id="del-confirm-btn" style="padding:7px 24px; background:#c62828; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">Yes, No way to revert back</button>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } // shared list footer (transferpicklist_pager.php) ?>

</div>





<script>
var rgnPicklist     = <?php echo json_encode($filterPicklist); ?>;
var isReadOnly      = <?php echo $isReadOnly ? 'true' : 'false'; ?>;
var warehouseOpts   = <?php
    $jsWh = array();
    foreach ($warehouseOptions as $rgn => $opts) {
        $jsWh[$rgn] = array();
        foreach ($opts as $wh) {
            $jsWh[$rgn][] = array('id' => (int)$wh['id'], 'code' => $wh['code'], 'acmm_id' => (int)$wh['acmm_id']);
        }
    }
    echo json_encode($jsWh);
?>;

// Table header warehouse dropdown — auto-change on select
document.querySelectorAll('.tbl-wh-select').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var region     = this.getAttribute('data-region');
        var msg        = document.querySelector('.tbl-wh-msg[data-region="' + region + '"]');
        var newWhId    = this.value;
        var picklistNo = rgnPicklist || '';
        if (!picklistNo) { alert('Please select a picklist first.'); return; }
        sel.disabled = true;
        msg.style.display = 'none';
        var fd = new FormData();
        fd.append('ajax', 'change_warehouse');
        fd.append('region', region);
        fd.append('new_wh_id', newWhId);
        fd.append('picklist_no', picklistNo);
        fetch('transferpicklist_warehouse.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                sel.disabled = false;
                if (data.ok) {
                    msg.textContent = (data.total_qty !== undefined ? data.total_qty + ' units' : data.affected + ' rows') + ' → updated';
                    msg.style.display = 'inline';
                } else { alert(data.msg || 'Error'); }
            })
            .catch(function() { sel.disabled = false; alert('Network error'); });
    });
});

// Delete ALL items for a region
document.querySelectorAll('.rgn-del-all-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var region     = this.getAttribute('data-region');
        var label      = this.getAttribute('data-label');
        var picklistNo = rgnPicklist || '';
        if (!picklistNo) { alert('Please select a picklist first.'); return; }
        if (!confirm('Remove ALL ' + label + ' items from this picklist?\n\nThis cannot be undone.\nThere is no way to revert back.')) { return; }
        btn.disabled = true;
        var fd = new FormData();
        fd.append('ajax', 'delete_region_all');
        fd.append('region', region);
        fd.append('picklist_no', picklistNo);
        fetch('transferpicklist_warehouse.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.ok) {
                    // Show the message first — reloading first could cancel it
                    alert(data.affected + ' items removed from ' + label);
                    location.reload();
                } else { alert(data.msg || 'Error'); }
            })
            .catch(function() { btn.disabled = false; alert('Network error'); });
    });
});


// Per-item per-region bin button
document.querySelectorAll('.item-rgn-del-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var ic     = this.getAttribute('data-ic');
        var region = this.getAttribute('data-region');
        var label  = { peninsular: 'Semenanjung', sabah: 'Sabah', sarawak: 'Sarawak' }[region] || region;
        if (!confirm('Remove ' + label + ' qty for item ' + ic + '?\n\nThis cannot be undone.')) { return; }
        btn.disabled = true;
        var fd = new FormData();
        fd.append('ajax', 'delete_by_item_region');
        fd.append('item_code', ic);
        fd.append('region', region);
        fd.append('picklist_no', rgnPicklist || '');
        fetch('transferpicklist_warehouse.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.ok) {
                    // Update just this region's cell in place — don't reload the page.
                    // Reloading re-runs the query sorted by total qty, and since this
                    // item's total just dropped, it would jump far down the list (or
                    // off the page entirely), making it hard to find right after the
                    // delete. Keeping the row where it is avoids that.
                    var cell = btn.closest('td');
                    if (cell) { cell.innerHTML = '<span style="color:#ccc;">&mdash;</span>'; }
                } else { alert(data.msg || 'Error'); }
            })
            .catch(function() { btn.disabled = false; alert('Network error'); });
    });
});

// Confirm-return checkbox — ticking means "agree to return this item to warehouse";
// only confirmed items become visible to the outlet once the picklist is published.
document.querySelectorAll('.confirm-chk').forEach(function(chk) {
    chk.addEventListener('change', function() {
        var box   = this;
        var ids   = box.getAttribute('data-ids');
        var state = box.checked ? 1 : 0;
        box.disabled = true;
        var fd = new FormData();
        fd.append('ajax', 'toggle_confirm');
        fd.append('picklist_no', rgnPicklist || '');
        fd.append('ids', ids);
        fd.append('state', state);
        fetch('transferpicklist_warehouse.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                box.disabled = false;
                if (!data.ok) {
                    box.checked = !box.checked;
                    alert(data.msg || 'Error updating confirmation.');
                }
            })
            .catch(function() {
                box.disabled = false;
                box.checked = !box.checked;
                alert('Network error');
            });
    });
});

// (Filters auto-apply via the shared transferpicklist_autofilter.php, loaded by the sidebar.)

// Confirm Return — "All (N)" header checkbox: applies to EVERY item matching the current
// filter (all pages). Posts with the page's own query string so the server uses the same filter.
var confirmAllFiltered = document.getElementById('confirm-all-filtered');
if (confirmAllFiltered) {
    confirmAllFiltered.addEventListener('change', function() {
        var box   = this;
        var state = box.checked ? 1 : 0;
        var items = parseInt(box.getAttribute('data-items'), 10) || 0;
        var msg   = (state ? 'CONFIRM return' : 'UNCONFIRM return') + ' for ALL ' + items.toLocaleString() +
                    ' item(s) matching the current filter?\n\nThis applies to every page, not just the rows shown.';
        if (!confirm(msg)) { box.checked = !box.checked; return; }
        box.disabled = true;
        var fd = new FormData();
        fd.append('ajax', 'confirm_filtered');
        fd.append('state', state);
        fetch('transferpicklist_warehouse.php' + window.location.search, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                box.disabled = false;
                if (data.ok) {
                    alert((state ? 'Confirmed' : 'Unconfirmed') + ' ' + data.items.toLocaleString() + ' item(s).');
                    location.reload();
                } else {
                    box.checked = !box.checked;
                    alert(data.msg || 'Error updating confirmation.');
                }
            })
            .catch(function() {
                box.disabled = false;
                box.checked = !box.checked;
                alert('Network error');
            });
    });
}

// Checkbox select logic
var chkAll    = document.getElementById('chk-all');
var bulkCount = document.getElementById('bulk-count');

function getChecked() {
    return Array.prototype.slice.call(document.querySelectorAll('.row-chk:checked'));
}
function updateBulkCount() {
    var checked = getChecked();
    bulkCount.textContent = checked.length + ' item(s) selected';
}
if (chkAll) {
    chkAll.addEventListener('change', function() {
        document.querySelectorAll('.row-chk').forEach(function(c) { c.checked = chkAll.checked; });
        updateBulkCount();
    });
}
document.querySelectorAll('.row-chk').forEach(function(c) {
    c.addEventListener('change', function() {
        var all = document.querySelectorAll('.row-chk');
        var checked = getChecked();
        if (chkAll) { chkAll.checked = checked.length === all.length && all.length > 0; }
        updateBulkCount();
    });
});

function closeDelModal() {
    document.getElementById('del-overlay').style.display = 'none';
    document.getElementById('del-modal').style.display   = 'none';
}


// Bulk bar (and its Delete button) only exists when the picklist is editable —
// on a published picklist it isn't rendered, so don't attach to it.
var bulkDeleteBtn = document.getElementById('bulk-delete-btn');
function whEsc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
// Region cell holds the qty link plus a bin button and warehouse badges — read the qty only
function whRegionQty(cell) { var a = cell ? cell.querySelector('a') : null; return a ? a.textContent.trim() : '—'; }
if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', function() {
    var checked = getChecked();
    if (!checked.length) { alert('Please select at least one item.'); return; }

    // Build item list from DOM — cells (editable view): 0=#, 1=checkbox, 2=Item Code, 3=Description,
    // 4=Model, 5=Return, 6=Product Ownership, 7=P.Officer, 8=Reorder Point, 9=Confirm Return,
    // 10=Semenanjung, 11=Sabah, 12=Sarawak
    var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
    html += '<thead><tr>';
    html += '<th style="background:#455a64;color:#fff;padding:6px 10px;text-align:left;">Item Code</th>';
    html += '<th style="background:#455a64;color:#fff;padding:6px 10px;text-align:left;">Description</th>';
    html += '<th style="background:#455a64;color:#fff;padding:6px 10px;text-align:center;">Semenanjung</th>';
    html += '<th style="background:#455a64;color:#fff;padding:6px 10px;text-align:center;">Sabah</th>';
    html += '<th style="background:#455a64;color:#fff;padding:6px 10px;text-align:center;">Sarawak</th>';
    html += '</tr></thead><tbody>';
    var allIds = [];
    var rowKeys = [];
    checked.forEach(function(c, idx) {
        var ids = c.value.split(',');
        ids.forEach(function(id) { if (id) { allIds.push(id); } });
        var row = c.closest('tr');
        var cells = row ? row.querySelectorAll('td') : [];
        var ic   = whEsc(cells[2] ? cells[2].textContent.trim() : '—');
        var desc = whEsc(cells[3] ? cells[3].textContent.trim() : '—');
        var pen  = whEsc(whRegionQty(cells[10]));
        var sab  = whEsc(whRegionQty(cells[11]));
        var sar  = whEsc(whRegionQty(cells[12]));
        rowKeys.push(ic);
        html += '<tr style="' + (idx % 2 === 1 ? 'background:#fafafa;' : '') + '">';
        html += '<td style="padding:5px 10px;font-family:monospace;">' + ic   + '</td>';
        html += '<td style="padding:5px 10px;">' + desc + '</td>';
        html += '<td style="padding:5px 10px;text-align:center;font-weight:bold;">' + pen + '</td>';
        html += '<td style="padding:5px 10px;text-align:center;font-weight:bold;">' + sab + '</td>';
        html += '<td style="padding:5px 10px;text-align:center;font-weight:bold;">' + sar + '</td>';
        html += '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('del-modal-body').innerHTML = html;
    document.getElementById('del-overlay').style.display = 'block';
    document.getElementById('del-modal').style.display   = 'flex';

    var ids = allIds.join(',');

    document.getElementById('del-confirm-btn').onclick = function() {
        closeDelModal();
        var btn = document.getElementById('bulk-delete-btn');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('ajax', 'delete_items');
        fd.append('ids', ids);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'transferpicklist_warehouse.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) { return; }
            btn.disabled = false;
            var res;
            try { res = JSON.parse(xhr.responseText); } catch(e) { alert('Delete failed: unexpected server response.'); return; }
            if (res && res.ok) {
                checked.forEach(function(c) {
                    var row = c.closest('tr');
                    if (row) { row.parentNode.removeChild(row); }
                });
                if (chkAll) { chkAll.checked = false; }
                updateBulkCount();
            } else {
                alert('Delete failed' + (res && res.msg ? ': ' + res.msg : '. Please try again.'));
            }
        };
        xhr.send(fd);
    };
});

document.getElementById('del-overlay').addEventListener('click', closeDelModal);

// Bulk region → populate warehouse dropdown
var bulkRegionSel = document.getElementById('bulk-region-sel');
var bulkWhSel     = document.getElementById('bulk-wh-sel');
if (bulkRegionSel) {
    bulkRegionSel.addEventListener('change', function() {
        var region = this.value;
        bulkWhSel.innerHTML = '<option value="">-- Warehouse --</option>';
        bulkWhSel.disabled  = !region;
        if (region && warehouseOpts[region]) {
            warehouseOpts[region].forEach(function(wh) {
                var opt = document.createElement('option');
                opt.value       = wh.id;
                opt.textContent = wh.acmm_id + ' (' + wh.code + ')';
                bulkWhSel.appendChild(opt);
            });
        }
    });
}

// Bulk change warehouse — Apply button
var bulkChangeWhBtn = document.getElementById('bulk-changewh-btn');
if (bulkChangeWhBtn) {
    bulkChangeWhBtn.addEventListener('click', function() {
        var checked = getChecked();
        if (!checked.length) { alert('Please select at least one item.'); return; }
        var newWhId = bulkWhSel ? bulkWhSel.value : '';
        if (!newWhId) { alert('Please select a warehouse.'); return; }
        var whLabel = bulkWhSel.options[bulkWhSel.selectedIndex].textContent;
        if (!confirm('Change warehouse to ' + whLabel + ' for ' + checked.length + ' selected item(s)?')) { return; }
        var itemCodes = [];
        checked.forEach(function(c) { if (c.value) { itemCodes.push(c.value); } });
        bulkChangeWhBtn.disabled = true;
        var fd = new FormData();
        fd.append('ajax',        'change_warehouse_selected');
        fd.append('new_wh_id',   newWhId);
        fd.append('region',      bulkRegionSel ? bulkRegionSel.value : '');
        fd.append('picklist_no', rgnPicklist || '');
        fd.append('item_codes',  itemCodes.join(','));
        fetch('transferpicklist_warehouse.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                bulkChangeWhBtn.disabled = false;
                if (data.ok) {
                    var qtyMsg = data.total_qty !== undefined ? data.total_qty + ' unit(s)' : data.affected + ' line(s)';
                    alert(qtyMsg + ' updated to ' + (data.acmm_id || '') + ' (' + data.wh_code + ').');
                    location.reload();
                } else {
                    alert(data.msg || 'Error updating warehouse.');
                }
            })
            .catch(function() { bulkChangeWhBtn.disabled = false; alert('Network error.'); });
    });
}
</script>

<?php
tplT('Build page HTML');
if (isset($_GET['debug']) && (int)$transferpicklist_autho === 1):
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
<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>