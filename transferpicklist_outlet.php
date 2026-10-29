<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
require_once(__DIR__ . '/transferpicklist_pager.php');
require_once(__DIR__ . '/transferpicklist_route_filter.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
// My Outlet Tasks: Super Admin (all outlets) + Area / Outlet Managers (only the outlets in their staff.outlet)
$_tpl_outlet_access = ((int)$transferpicklist_autho === 1) || (isset($status_semasa) && ($status_semasa === 'Area Manager' || $status_semasa === 'Outlet Manager'));
if (!$_tpl_outlet_access) { redirect('/odb/permission.php'); }

// Ensure recycle column exists on transferpicklist_item
$_cr = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transferpicklist_item' AND COLUMN_NAME = 'recycle' LIMIT 1");
if ($_cr && $_cr->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_item ADD COLUMN recycle TINYINT(1) NOT NULL DEFAULT 0");
}
// Who confirmed an item and when (see database/migrations/2026-10-29_item_confirmed_by.sql)
$_cb = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transferpicklist_item' AND COLUMN_NAME = 'confirmed_by' LIMIT 1");
if ($_cb && $_cb->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_item ADD COLUMN confirmed_by VARCHAR(100) NULL, ADD COLUMN confirmed_at DATETIME NULL");
}
$_confirmBy  = $conn->real_escape_string(isset($nama_staff) && $nama_staff !== '' ? $nama_staff : (isset($_SESSION['myusername']) ? $_SESSION['myusername'] : ''));
$_confirmSet = "confirmed_by = '$_confirmBy', confirmed_at = NOW()";

$msg      = '';
$msg_type = '';
$view     = (isset($_GET['view']) && $_GET['view'] === 'incoming') ? 'incoming' : 'outgoing';

// Get outlet IDs from the logged-in staff's outlet field (comma-separated)
$myOutletIds = array();
$username = isset($_SESSION['myusername']) ? $_SESSION['myusername'] : '';
if ($username) {
    $stmt = $conn->prepare("SELECT outlet FROM staff WHERE username = ? AND recycle != 1 LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $staffRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($staffRow && $staffRow['outlet'] != '') {
        foreach (explode(',', $staffRow['outlet']) as $oid) {
            $oid = (int)trim($oid);
            if ($oid > 0) { $myOutletIds[] = $oid; }
        }
    }
}
$outlet_id = !empty($myOutletIds) ? $myOutletIds[0] : 0;

// Admin outlet dropdown, limited to outlets that are actually in the selected pick list
// ($col = from_outlet_id for the outgoing tab, to_outlet_id for incoming). The selected
// outlet is always kept in the list so the dropdown never silently loses the selection.
function tplAdminOutletsForPicklist($conn, $picklistNo, $col, $keepCode) {
    $list = array();
    $res = $conn->query("
        SELECT DISTINCT o.code
        FROM transferpicklist_item i
        JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
        JOIN outlet o ON o.id = i.$col
        WHERE p.picklist_no = '" . $conn->real_escape_string($picklistNo) . "'
          AND i.recycle = 0 AND i.qty_transfer >= 1
          AND (i.priority_type != 'warehouse' OR i.return_confirmed = 1)
        ORDER BY o.code
    ");
    if ($res) { while ($r = $res->fetch_assoc()) { $list[] = $r['code']; } }
    if ($keepCode !== '' && !in_array($keepCode, $list, true)) { $list[] = $keepCode; sort($list); }
    return $list;
}

// Admin override: see all outlets or filter by a selected outlet
$_is_admin_tpl     = ((int)$transferpicklist_autho === 1);
$filterAdminOutlet = '';
$adminOutletList   = array();
if ($_is_admin_tpl) {
    $olRes = $conn->query("
        SELECT DISTINCT o.code
        FROM transferpicklist_item i
        JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
        JOIN outlet o ON o.id = i.from_outlet_id
        WHERE i.recycle = 0 AND (i.priority_type != 'warehouse' OR i.return_confirmed = 1)
        ORDER BY o.code
    ");
    while ($r = $olRes->fetch_assoc()) { $adminOutletList[] = $r['code']; }

    $filterAdminOutlet = isset($_GET['filter_outlet']) ? trim($_GET['filter_outlet']) : '';
    if ($filterAdminOutlet !== '') {
        $oRes = $conn->query("SELECT id FROM outlet WHERE code = '" . $conn->real_escape_string($filterAdminOutlet) . "' AND recycle = 0 LIMIT 1");
        $myOutletIds = array();
        if ($oRes && ($oRow = $oRes->fetch_assoc())) { $myOutletIds[] = (int)$oRow['id']; }
        $outlet_id = !empty($myOutletIds) ? $myOutletIds[0] : 0;
    } else {
        $allORes = $conn->query("
            SELECT DISTINCT from_outlet_id
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
            WHERE i.recycle = 0 AND (i.priority_type != 'warehouse' OR i.return_confirmed = 1)
        ");
        $myOutletIds = array();
        while ($r = $allORes->fetch_assoc()) { $myOutletIds[] = (int)$r['from_outlet_id']; }
        $outlet_id = !empty($myOutletIds) ? $myOutletIds[0] : 0;
    }
}

// Area / Outlet Manager with more than one outlet: dropdown of ONLY their own outlets.
// A filter_outlet value that isn't one of their outlets is ignored.
$mgrOutletList = array();
if (!$_is_admin_tpl && count($myOutletIds) > 1) {
    $_mRes = $conn->query("SELECT id, code FROM outlet WHERE id IN (" . implode(',', $myOutletIds) . ") ORDER BY code");
    $_mIds = array();
    while ($_mRes && $r = $_mRes->fetch_assoc()) { $mgrOutletList[] = $r['code']; $_mIds[$r['code']] = (int)$r['id']; }
    $_mPick = isset($_GET['filter_outlet']) ? trim($_GET['filter_outlet']) : '';
    if ($_mPick !== '' && isset($_mIds[$_mPick])) {
        $filterAdminOutlet = $_mPick;               // reused so pager / export links keep the outlet
        $myOutletIds       = array($_mIds[$_mPick]);
        $outlet_id         = $_mIds[$_mPick];
    }
}

$items           = null;
$myOutlets       = array();
$filterPicklist  = '';
$filterPriority  = '';
$filterStatus    = '';
$filterItemType  = '';
$filterSearch    = '';
$picklistOptions = array();
$totalItems      = 0;
$totalPages      = 1;
$page            = 1;
$offset          = 0;
$pendingAllCount = 0;
$confirmLog      = array();

// Confirm per outlet only: with more than one outlet in view (manager's "All My Outlets",
// Super Admin's "All Outlets") the confirm actions are blocked until one outlet is chosen.
$_multiOutlet = count($myOutletIds) > 1;
if ($_multiOutlet && (isset($_POST['confirm_all']) || isset($_POST['bulk_confirm']) || isset($_POST['mark_transferred']))) {
    unset($_POST['confirm_all'], $_POST['bulk_confirm'], $_POST['mark_transferred']);
    $msg      = 'Please choose <b>one outlet</b> in the Outlet filter before confirming — items are confirmed per outlet.';
    $msg_type = 'error';
}

if (!empty($myOutletIds)) {
    $inIds = implode(',', $myOutletIds);

    if ($view === 'outgoing') {
        // Bulk confirm all pending items on page
        if (isset($_POST['bulk_confirm']) && isset($_POST['pending_ids']) && is_array($_POST['pending_ids'])) {
            $confirmed = 0;
            foreach ($_POST['pending_ids'] as $rawId) {
                $item_id = (int)$rawId;
                $qRow = $conn->query("SELECT qty_transfer FROM transferpicklist_item WHERE id=$item_id AND from_outlet_id IN ($inIds) AND status='pending'")->fetch_assoc();
                if ($qRow) {
                    $maxQty = (float)$qRow['qty_transfer'];
                    // Check both integer and string key (PHP may store either)
                    $postQty = null;
                    if (isset($_POST['qty_actual'][$item_id])) {
                        $postQty = (float)$_POST['qty_actual'][$item_id];
                    } elseif (isset($_POST['qty_actual'][(string)$item_id])) {
                        $postQty = (float)$_POST['qty_actual'][(string)$item_id];
                    }
                    $qtyActual = ($postQty !== null) ? $postQty : $maxQty;
                    if ($qtyActual < 1)       { $qtyActual = 1; }
                    if ($qtyActual > $maxQty) { $qtyActual = $maxQty; }
                    $safeQty = number_format($qtyActual, 2, '.', '');
                    $res = $conn->query("UPDATE transferpicklist_item SET status='transferred', qty_actual=$safeQty, $_confirmSet WHERE id=$item_id AND from_outlet_id IN ($inIds)");
                    if (!$res) {
                        // qty_actual column may not exist yet — update status only
                        $conn->query("UPDATE transferpicklist_item SET status='transferred', $_confirmSet WHERE id=$item_id AND from_outlet_id IN ($inIds)");
                    }
                    $confirmed++;
                }
            }
            // PRG redirect to avoid resubmission and show fresh data
            $redirectBase = 'transferpicklist_outlet.php?view=outgoing';
            if (isset($_GET['picklist_no']) && $_GET['picklist_no'] !== '') {
                $redirectBase .= '&picklist_no=' . urlencode($_GET['picklist_no']);
            }
            if (isset($_GET['page']) && (int)$_GET['page'] > 1) {
                $redirectBase .= '&page=' . (int)$_GET['page'];
            }
            $redirectBase .= '&confirmed=' . $confirmed;
            header('Location: ' . $redirectBase);
            exit;
        }

        // Confirm ALL pending items matching current filters (across all pages)
        if (isset($_POST['confirm_all'])) {
            $caPicklist  = isset($_POST['ca_picklist'])  ? trim($_POST['ca_picklist'])  : '';
            $caPriority  = isset($_POST['ca_priority'])  ? trim($_POST['ca_priority'])  : '';
            $caItemType  = isset($_POST['ca_item_type']) ? trim($_POST['ca_item_type']) : '';
            $caSearch    = isset($_POST['ca_search'])    ? trim($_POST['ca_search'])    : '';

            $caWhere = array("i.from_outlet_id IN ($inIds)", "i.qty_transfer >= 1", "i.status = 'pending'");
            if ($caPicklist !== '') { $caWhere[] = "p.picklist_no = '" . $conn->real_escape_string($caPicklist) . "'"; }
            if ($caPriority !== '') { $caWhere[] = "i.priority_type = '" . $conn->real_escape_string($caPriority) . "'"; }
            if ($caSearch !== '') {
                $esc = $conn->real_escape_string($caSearch);
                $caOr = array("i.item_code LIKE '%$esc%'");
                $dcs = array();
                $dr = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
                while ($d = $dr->fetch_assoc()) { $dcs[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
                if (!empty($dcs)) { $caOr[] = "i.item_code IN (" . implode(',', $dcs) . ")"; }
                $oids = array();
                $or = $conn->query("SELECT id FROM outlet WHERE code LIKE '%$esc%'");
                while ($o = $or->fetch_assoc()) { $oids[] = (int)$o['id']; }
                if (!empty($oids)) { $caOr[] = "i.to_outlet_id IN (" . implode(',', $oids) . ")"; }
                $caWhere[] = "(" . implode(' OR ', $caOr) . ")";
            }
            if ($caItemType === 'non_hq' || $caItemType === 'hq') {
                $mc = array();
                $mcond = $caItemType === 'non_hq' ? "model = 'Store/HQ Order'" : "model != 'Store/HQ Order'";
                $mr = $conn->query("SELECT item_code FROM simple WHERE $mcond");
                while ($m = $mr->fetch_assoc()) { $mc[] = "'" . $conn->real_escape_string($m['item_code']) . "'"; }
                if (!empty($mc)) { $caWhere[] = "i.item_code IN (" . implode(',', $mc) . ")"; }
                else { $caWhere[] = "1=0"; }
            }
            $caWhereStr = implode(' AND ', $caWhere);

            $conn->query("
                UPDATE transferpicklist_item i
                JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
                SET i.status = 'transferred', i.confirmed_by = '$_confirmBy', i.confirmed_at = NOW()
                WHERE $caWhereStr AND i.recycle = 0 AND (i.priority_type != 'warehouse' OR i.return_confirmed = 1)
            ");
            $confirmed = $conn->affected_rows;

            $redirectBase = 'transferpicklist_outlet.php?view=outgoing';
            if ($caPicklist !== '') { $redirectBase .= '&picklist_no=' . urlencode($caPicklist); }
            $redirectBase .= '&confirmed=' . $confirmed;
            header('Location: ' . $redirectBase);
            exit;
        }

        // Mark item as transferred (single, legacy)
        if (isset($_POST['mark_transferred']) && isset($_POST['item_id'])) {
            $item_id = (int)$_POST['item_id'];
            $qRow = $conn->query("SELECT qty_transfer FROM transferpicklist_item WHERE id=$item_id AND from_outlet_id IN ($inIds)")->fetch_assoc();
            if ($qRow) {
                $maxQty    = (float)$qRow['qty_transfer'];
                $qtyActual = isset($_POST['qty_actual']) ? (float)$_POST['qty_actual'] : $maxQty;
                if ($qtyActual < 1)       { $qtyActual = 1; }
                if ($qtyActual > $maxQty) { $qtyActual = $maxQty; }
                $conn->query("UPDATE transferpicklist_item SET status='transferred', qty_transfer=$qtyActual, $_confirmSet WHERE id=$item_id AND from_outlet_id IN ($inIds)");
                $msg      = 'Item marked as transferred.';
                $msg_type = 'success';
            }
        }
        // Mark item as cancelled + auto-reassign
        if (isset($_POST['mark_cancelled']) && isset($_POST['item_id'])) {
            $item_id = (int)$_POST['item_id'];

            // Get cancelled item details
            $row = $conn->query("SELECT * FROM transferpicklist_item WHERE id=$item_id AND from_outlet_id IN ($inIds)")->fetch_assoc();

            if ($row) {
                $conn->query("UPDATE transferpicklist_item SET status='cancelled' WHERE id=$item_id");

                $fromOutlet  = (int)$row['from_outlet_id'];
                $oldToOutlet = (int)$row['to_outlet_id'];
                $itemCode    = $conn->real_escape_string($row['item_code']);
                $qty         = (float)$row['qty_transfer'];
                $picklistId  = (int)$row['picklist_id'];
                $timestamp   = date('Y-m-d H:i:s');

                // Build area manager map
                $outletManager = array();
                $res = $conn->query("SELECT id, outlet FROM staff WHERE status_semasa='Area Manager' AND recycle=0 AND outlet!=''");
                while ($r = $res->fetch_assoc()) {
                    foreach (explode(',', $r['outlet']) as $oid) {
                        $oid = (int)trim($oid);
                        if ($oid > 0) { $outletManager[$oid] = (int)$r['id']; }
                    }
                }

                $senderManager = isset($outletManager[$fromOutlet]) ? $outletManager[$fromOutlet] : null;

                // Find demand outlets for this item (excluding sender and old receiver)
                $res = $conn->query("
                    SELECT a.outlet_id,
                           (a.avg_sold - b.last_month_closing) AS need
                    FROM (
                        SELECT outlet_id, AVG(qty_stock) AS avg_sold, MAX(month_year) AS latest_month
                        FROM transferpicklist_past3months
                        WHERE item_code = '$itemCode'
                        GROUP BY outlet_id
                    ) a
                    JOIN transferpicklist_past3months b
                        ON b.outlet_id = a.outlet_id AND b.item_code = '$itemCode' AND b.month_year = a.latest_month
                    WHERE a.avg_sold > b.last_month_closing
                      AND a.outlet_id != $fromOutlet
                      AND a.outlet_id != $oldToOutlet
                    ORDER BY need DESC
                ");

                $newTo    = null;
                $sameMgrTo = null;
                $otherTo   = null;

                while ($r = $res->fetch_assoc()) {
                    $oid = (int)$r['outlet_id'];
                    if (!isset($outletManager[$oid])) { continue; }
                    if ($senderManager && $outletManager[$oid] === $senderManager) {
                        if (!$sameMgrTo) { $sameMgrTo = $oid; }
                    } else {
                        if (!$otherTo) { $otherTo = $oid; }
                    }
                }

                $newTo         = $sameMgrTo ? $sameMgrTo : $otherTo;
                $priorityType  = $sameMgrTo ? 'same_area' : 'other_area';

                if ($newTo) {
                    $conn->query("
                        INSERT INTO transferpicklist_item
                            (picklist_id, from_outlet_id, to_outlet_id, item_code, qty_transfer, priority_type, status, timestamp)
                        VALUES ($picklistId, $fromOutlet, $newTo, '$itemCode', $qty, '$priorityType', 'pending', '$timestamp')
                    ");
                    $toCode = '';
                    $toRow  = $conn->query("SELECT code FROM outlet WHERE id=$newTo")->fetch_assoc();
                    if ($toRow) { $toCode = $toRow['code']; }
                    $msg      = "Item cancelled and reassigned to <b>$toCode</b>.";
                    $msg_type = 'success';
                } else {
                    $msg      = 'Item cancelled. No outlet available for reassignment.';
                    $msg_type = 'warn';
                }
            }
        }

        // Remake cancelled item — cancel existing reassignment and re-run
        if (isset($_POST['mark_remake']) && isset($_POST['item_id'])) {
            $item_id = (int)$_POST['item_id'];
            $row = $conn->query("SELECT * FROM transferpicklist_item WHERE id=$item_id AND status='cancelled' AND from_outlet_id IN ($inIds)")->fetch_assoc();
            if ($row) {
                $fromOutlet  = (int)$row['from_outlet_id'];
                $oldToOutlet = (int)$row['to_outlet_id'];
                $itemCode    = $conn->real_escape_string($row['item_code']);
                $qty         = (float)$row['qty_transfer'];
                $picklistId  = (int)$row['picklist_id'];
                $timestamp   = date('Y-m-d H:i:s');

                // Cancel any existing reassigned item for this slot
                $conn->query("UPDATE transferpicklist_item SET status='cancelled'
                    WHERE picklist_id=$picklistId AND from_outlet_id=$fromOutlet
                      AND item_code='$itemCode' AND id > $item_id AND status='pending'");

                // Build area manager map
                $outletManager = array();
                $res2 = $conn->query("SELECT id, outlet FROM staff WHERE status_semasa='Area Manager' AND recycle=0 AND outlet!=''");
                while ($r2 = $res2->fetch_assoc()) {
                    foreach (explode(',', $r2['outlet']) as $oid) {
                        $oid = (int)trim($oid);
                        if ($oid > 0) { $outletManager[$oid] = (int)$r2['id']; }
                    }
                }
                $senderManager = isset($outletManager[$fromOutlet]) ? $outletManager[$fromOutlet] : null;

                // Collect already-assigned outlets for this item in this picklist to exclude
                $excludeIds = array($fromOutlet, $oldToOutlet);
                $res2 = $conn->query("SELECT to_outlet_id FROM transferpicklist_item
                    WHERE picklist_id=$picklistId AND from_outlet_id=$fromOutlet
                      AND item_code='$itemCode' AND status != 'cancelled' AND id != $item_id");
                while ($r2 = $res2->fetch_assoc()) { $excludeIds[] = (int)$r2['to_outlet_id']; }
                $excludeList = implode(',', array_unique($excludeIds));

                $res2 = $conn->query("
                    SELECT a.outlet_id, (a.avg_sold - b.last_month_closing) AS need
                    FROM (
                        SELECT outlet_id, AVG(qty_stock) AS avg_sold, MAX(month_year) AS latest_month
                        FROM transferpicklist_past3months WHERE item_code='$itemCode'
                        GROUP BY outlet_id
                    ) a
                    JOIN transferpicklist_past3months b
                        ON b.outlet_id=a.outlet_id AND b.item_code='$itemCode' AND b.month_year=a.latest_month
                    WHERE a.avg_sold > b.last_month_closing AND a.outlet_id NOT IN ($excludeList)
                    ORDER BY need DESC
                ");

                $sameMgrTo = null; $otherTo = null;
                while ($r2 = $res2->fetch_assoc()) {
                    $oid = (int)$r2['outlet_id'];
                    if (!isset($outletManager[$oid])) { continue; }
                    if ($senderManager && $outletManager[$oid] === $senderManager) {
                        if (!$sameMgrTo) { $sameMgrTo = $oid; }
                    } else {
                        if (!$otherTo) { $otherTo = $oid; }
                    }
                }

                $newTo        = $sameMgrTo ? $sameMgrTo : $otherTo;
                $priorityType = $sameMgrTo ? 'same_area' : 'other_area';

                if ($newTo) {
                    $conn->query("INSERT INTO transferpicklist_item
                        (picklist_id, from_outlet_id, to_outlet_id, item_code, qty_transfer, priority_type, status, timestamp)
                        VALUES ($picklistId, $fromOutlet, $newTo, '$itemCode', $qty, '$priorityType', 'pending', '$timestamp')");
                    $toRow = $conn->query("SELECT code FROM outlet WHERE id=$newTo")->fetch_assoc();
                    $toCode = $toRow ? $toRow['code'] : $newTo;
                    $msg      = "Remade — reassigned to <b>$toCode</b>.";
                    $msg_type = 'success';
                } else {
                    $msg      = 'No outlet available for reassignment.';
                    $msg_type = 'warn';
                }
            }
        }

        // Load all published picklists for this outlet (for filter dropdown)
        $picklistOptions = array();
        $res = $conn->query("
            SELECT DISTINCT p.id, p.picklist_no, p.timestamp
            FROM transferpicklist_picklist p
            JOIN transferpicklist_item i ON i.picklist_id = p.id
            WHERE p.status = 'published' AND i.from_outlet_id IN ($inIds) AND i.recycle = 0 AND (i.priority_type != 'warehouse' OR i.return_confirmed = 1)
            ORDER BY p.timestamp DESC
        ");
        while ($r = $res->fetch_assoc()) { $picklistOptions[] = $r; }

        // Default picklist_no = latest
        $defaultPicklistNo = !empty($picklistOptions) ? $picklistOptions[0]['picklist_no'] : '';
        $filterPicklist   = isset($_GET['picklist_no']) ? trim($_GET['picklist_no']) : $defaultPicklistNo;
        // Outlet dropdown: only senders in the selected pick list
        if ($_is_admin_tpl && $filterPicklist !== '') {
            $adminOutletList = tplAdminOutletsForPicklist($conn, $filterPicklist, 'from_outlet_id', $filterAdminOutlet);
        }
        $filterPriority   = isset($_GET['priority'])    ? trim($_GET['priority'])    : '';
        if (!tplRouteFitsKind($filterPriority, tplPicklistKind($filterPicklist))) { $filterPriority = ''; }
        $filterStatus     = isset($_GET['status'])      ? trim($_GET['status'])      : '';
        $filterItemType = isset($_GET['item_type']) ? trim($_GET['item_type']) : '';
        $filterSearch   = isset($_GET['search'])    ? trim($_GET['search'])    : '';

        $whereClauses = array("i.from_outlet_id IN ($inIds)", "i.qty_transfer >= 1", "i.status != 'cancelled'", "i.recycle = 0", "(i.priority_type != 'warehouse' OR i.return_confirmed = 1)");
        if ($filterPicklist !== '') {
            $whereClauses[] = "p.picklist_no = '" . $conn->real_escape_string($filterPicklist) . "'";
        }
        if ($filterPriority !== '') {
            $whereClauses[] = tplRouteWhere($conn, $filterPriority);
        }
        if ($filterStatus !== '') {
            $whereClauses[] = "i.status = '" . $conn->real_escape_string($filterStatus) . "'";
        }
        if ($filterSearch !== '') {
            $esc        = $conn->real_escape_string($filterSearch);
            $searchOr   = array("i.item_code LIKE '%$esc%'");
            // Pre-resolve matching item codes from simple.name
            $descCodes  = array();
            $dRes = $conn->query("SELECT item_code FROM simple WHERE name LIKE '%$esc%'");
            while ($d = $dRes->fetch_assoc()) { $descCodes[] = "'" . $conn->real_escape_string($d['item_code']) . "'"; }
            if (!empty($descCodes)) { $searchOr[] = "i.item_code IN (" . implode(',', $descCodes) . ")"; }
            // Pre-resolve matching outlet IDs from outlet.code
            $searchOids = array();
            $oRes = $conn->query("SELECT id FROM outlet WHERE code LIKE '%$esc%'");
            while ($o = $oRes->fetch_assoc()) { $searchOids[] = (int)$o['id']; }
            if (!empty($searchOids)) { $searchOr[] = "i.to_outlet_id IN (" . implode(',', $searchOids) . ")"; }
            $whereClauses[] = "(" . implode(' OR ', $searchOr) . ")";
        }
        if ($filterItemType === 'non_hq' || $filterItemType === 'hq') {
            $modelCodes = array();
            $modelCond  = $filterItemType === 'non_hq' ? "model = 'Store/HQ Order'" : "model != 'Store/HQ Order'";
            $mRes = $conn->query("SELECT item_code FROM simple WHERE $modelCond");
            while ($m = $mRes->fetch_assoc()) { $modelCodes[] = "'" . $conn->real_escape_string($m['item_code']) . "'"; }
            if (!empty($modelCodes)) {
                $whereClauses[] = "i.item_code IN (" . implode(',', $modelCodes) . ")";
            }
        }
        $whereStr = implode(' AND ', $whereClauses);

        // Count all pending items across all pages (for Confirm All button)
        $pendingAllRes = $conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_item i JOIN transferpicklist_picklist p ON p.id=i.picklist_id AND p.status='published' WHERE $whereStr AND i.status='pending'");
        $pendingAllCount = $pendingAllRes ? (int)$pendingAllRes->fetch_assoc()['cnt'] : 0;

        // Who confirmed and when — grouped per confirm action, shown once on the last page
        $clRes = $conn->query("
            SELECT i.confirmed_by, MAX(i.confirmed_at) AS at, COUNT(*) AS n
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
            WHERE $whereStr AND i.status = 'transferred' AND i.confirmed_at IS NOT NULL
            GROUP BY i.confirmed_by, DATE_FORMAT(i.confirmed_at, '%Y-%m-%d %H:%i')
            ORDER BY at DESC LIMIT 10
        ");
        while ($clRes && $cl = $clRes->fetch_assoc()) { $confirmLog[] = $cl; }

        // EXPORT CSV
        if (isset($_GET['export'])) {
            ob_end_clean();
            $expRes = $conn->query("
                SELECT i.item_code, i.qty_transfer, i.priority_type, i.status,
                    p.picklist_no,
                    o.code as from_code, o.acmm_id as from_acmm,
                    to2.code as to_code, to2.acmm_id as to_acmm, to2.comp_name as to_name
                FROM transferpicklist_item i
                JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
                JOIN outlet o   ON o.id   = i.from_outlet_id
                JOIN outlet to2 ON to2.id = i.to_outlet_id
                WHERE $whereStr
                ORDER BY i.status ASC, i.item_code ASC
            ");
            $expRows  = array();
            $expCodes = array();
            while ($r = $expRes->fetch_assoc()) { $expRows[] = $r; $expCodes[$r['item_code']] = true; }
            $expDesc = array();
            if (!empty($expCodes)) {
                $inList = implode(',', array_map(function($c) use ($conn) { return "'" . $conn->real_escape_string($c) . "'"; }, array_keys($expCodes)));
                $dRes = $conn->query("SELECT item_code, name FROM simple WHERE item_code IN ($inList)");
                while ($d = $dRes->fetch_assoc()) { $expDesc[$d['item_code']] = $d['name']; }
            }
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="outlet_tasks_' . date('Ymd') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, array('#', 'From Short Code', 'From Outlet Code', 'Transfer To Short Code', 'Transfer To Code', 'Item Code', 'Item Description', 'Priority', 'Status', 'Qty to Send'));
            $rowNum = 1;
            foreach ($expRows as $r) {
                $desc     = isset($expDesc[$r['item_code']]) ? $expDesc[$r['item_code']] : '';
                $status   = $r['status'] === 'transferred' ? 'Confirmed' : ucfirst($r['status']);
                $fromAcmm = !empty($r['from_acmm']) ? "'" . str_pad((int)$r['from_acmm'], 4, '0', STR_PAD_LEFT) : '';
                $toAcmm   = !empty($r['to_acmm'])   ? "'" . str_pad((int)$r['to_acmm'],   4, '0', STR_PAD_LEFT) : '';
                fputcsv($out, array($rowNum++, $r['from_code'], $fromAcmm, $r['to_code'], $toAcmm, $r['item_code'], $desc, $r['priority_type'], $status, $r['qty_transfer']));
            }
            fclose($out);
            exit;
        }

        $perPage    = tplPerPage(50);
        $page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset     = ($page - 1) * $perPage;

        $totalItems = (int)$conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_item i JOIN transferpicklist_picklist p ON p.id=i.picklist_id AND p.status='published' WHERE $whereStr")->fetch_assoc()['cnt'];
        $totalPages = max(1, ceil($totalItems / $perPage));

        $items = $conn->query("
            SELECT i.*,
                p.picklist_no, p.timestamp as pl_date,
                o.code as from_code, o.acmm_id as from_acmm,
                to2.code as to_code, to2.acmm_id as to_acmm, to2.comp_name as to_name
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
            JOIN outlet o   ON o.id   = i.from_outlet_id
            JOIN outlet to2 ON to2.id = i.to_outlet_id
            WHERE $whereStr
            ORDER BY i.status ASC, i.item_code ASC
            LIMIT $perPage OFFSET $offset
        ");

        // Build reassignment map scoped to current page items only
        $reassignMap = array();
        $pageIds = array();
        if ($items) {
            $tmpRows  = array();
            $pageCodes = array();
            while ($tmp = $items->fetch_assoc()) {
                $tmpRows[] = $tmp;
                $pageCodes[$tmp['item_code']] = true;
                if ($tmp['status'] === 'cancelled') { $pageIds[] = (int)$tmp['id']; }
            }
            // Load descriptions and model for this page's item codes in one query
            $outgoingDesc  = array();
            $outgoingModel = array();
            if (!empty($pageCodes)) {
                $inList = implode(',', array_map(function($c) use ($conn) {
                    return "'" . $conn->real_escape_string($c) . "'";
                }, array_keys($pageCodes)));
                $dRes = $conn->query("SELECT item_code, name, model FROM simple WHERE item_code IN ($inList)");
                while ($d = $dRes->fetch_assoc()) {
                    $outgoingDesc[$d['item_code']]  = $d['name'];
                    $outgoingModel[$d['item_code']] = $d['model'];
                }
            }
            foreach ($tmpRows as &$tmp) {
                $tmp['item_desc']  = isset($outgoingDesc[$tmp['item_code']])  ? $outgoingDesc[$tmp['item_code']]  : '';
                $tmp['item_model'] = isset($outgoingModel[$tmp['item_code']]) ? $outgoingModel[$tmp['item_code']] : '';
            }
            unset($tmp);
            $items = $tmpRows;
        }
        if (!empty($pageIds)) {
            $idList = implode(',', $pageIds);
            $res = $conn->query("
                SELECT a.id as cancelled_id, o.code as next_code
                FROM transferpicklist_item a
                JOIN transferpicklist_item b
                    ON b.picklist_id    = a.picklist_id
                   AND b.from_outlet_id = a.from_outlet_id
                   AND b.item_code      = a.item_code
                   AND b.id             > a.id
                   AND b.status        != 'cancelled'
                JOIN outlet o ON o.id = b.to_outlet_id
                WHERE a.id IN ($idList)
            ");
            while ($r = $res->fetch_assoc()) {
                $reassignMap[(int)$r['cancelled_id']] = $r['next_code'];
            }
        } else {
            $items = isset($tmpRows) ? $tmpRows : array();
        }
    } else {
        // Load all published picklists for this outlet (for filter dropdown)
        $picklistOptions = array();
        $res = $conn->query("
            SELECT DISTINCT p.id, p.picklist_no, p.timestamp
            FROM transferpicklist_picklist p
            JOIN transferpicklist_item i ON i.picklist_id = p.id
            WHERE p.status = 'published' AND i.to_outlet_id IN ($inIds) AND i.recycle = 0 AND (i.priority_type != 'warehouse' OR i.return_confirmed = 1)
            ORDER BY p.timestamp DESC
        ");
        while ($r = $res->fetch_assoc()) { $picklistOptions[] = $r; }

        $defaultPicklistNo = !empty($picklistOptions) ? $picklistOptions[0]['picklist_no'] : '';
        $filterPicklist  = isset($_GET['picklist_no']) ? trim($_GET['picklist_no']) : $defaultPicklistNo;
        // Outlet dropdown: only receivers in the selected pick list
        if ($_is_admin_tpl && $filterPicklist !== '') {
            $adminOutletList = tplAdminOutletsForPicklist($conn, $filterPicklist, 'to_outlet_id', $filterAdminOutlet);
        }
        $filterPriority  = isset($_GET['priority'])    ? trim($_GET['priority'])    : '';
        if (!tplRouteFitsKind($filterPriority, tplPicklistKind($filterPicklist))) { $filterPriority = ''; }
        $filterStatus    = isset($_GET['status'])      ? trim($_GET['status'])      : 'transferred';
        $filterItemCode  = isset($_GET['item_code'])   ? trim($_GET['item_code'])   : '';

        $whereClauses = array("i.to_outlet_id IN ($inIds)", "i.qty_transfer >= 1", "i.recycle = 0", "(i.priority_type != 'warehouse' OR i.return_confirmed = 1)");
        if ($filterPicklist !== '') {
            $whereClauses[] = "p.picklist_no = '" . $conn->real_escape_string($filterPicklist) . "'";
        }
        if ($filterPriority !== '') {
            $whereClauses[] = tplRouteWhere($conn, $filterPriority);
        }
        if ($filterStatus !== '') {
            $whereClauses[] = "i.status = '" . $conn->real_escape_string($filterStatus) . "'";
        }
        if ($filterItemCode !== '') {
            $whereClauses[] = "i.item_code LIKE '%" . $conn->real_escape_string($filterItemCode) . "%'";
        }
        $whereStr = implode(' AND ', $whereClauses);

        $perPage    = tplPerPage(50);
        $page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset     = ($page - 1) * $perPage;

        $totalItems = (int)$conn->query("SELECT COUNT(*) as cnt FROM transferpicklist_item i JOIN transferpicklist_picklist p ON p.id=i.picklist_id AND p.status='published' WHERE $whereStr")->fetch_assoc()['cnt'];
        $totalPages = max(1, ceil($totalItems / $perPage));

        $items = $conn->query("
            SELECT i.*,
                p.picklist_no, p.timestamp as pl_date,
                o.code as from_code, o.comp_name as from_name,
                to2.code as to_code
            FROM transferpicklist_item i
            JOIN transferpicklist_picklist p ON p.id = i.picklist_id AND p.status = 'published'
            JOIN outlet o   ON o.id   = i.from_outlet_id
            JOIN outlet to2 ON to2.id = i.to_outlet_id
            WHERE $whereStr
            ORDER BY i.status ASC, i.item_code ASC
            LIMIT $perPage OFFSET $offset
        ");
    }

    $res = $conn->query("SELECT code, comp_name FROM outlet WHERE id IN ($inIds) ORDER BY code");
    while ($r = $res->fetch_assoc()) {
        $myOutlets[] = $r;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>My Transfer Pick List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> My Transfer Pick List
        <?php if ($_is_admin_tpl): ?>
        — <span style="font-size:14px;"><?php echo $filterAdminOutlet !== '' ? htmlspecialchars($filterAdminOutlet) : 'All Outlets'; ?></span>
        <?php elseif (!empty($myOutlets)): ?>
        — <span style="font-size:14px;"><?php echo implode(' | ', array_column($myOutlets, 'code')); ?></span>
        <?php endif; ?>
    </h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if (isset($_GET['confirmed'])): ?>
<div class="alpro-alert alpro-success"><?php echo (int)$_GET['confirmed']; ?> item(s) confirmed and marked as transferred
    by <b><?php echo htmlspecialchars(isset($nama_staff) && $nama_staff !== '' ? $nama_staff : ''); ?></b> on <?php echo date('d M Y, h:i A'); ?>.</div>
<?php endif; ?>

<div class="alpro-box" style="margin-bottom:0;">
    <div class="alpro-actions">
</div>

<?php if (!empty($myOutletIds)): ?>
<style>
.tpl-filter-bar{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;}
.tpl-filter-label{display:block;font-size:11px;color:#888;margin-bottom:4px;text-align:center;}
.tpl-filter-bar input[type=text],.tpl-filter-bar select{width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;height:32px;}
.tpl-filter-bar input[type=text]:focus,.tpl-filter-bar select:focus{border-color:#1976d2;outline:none;}
.tpl-filter-grow{flex:1 1 0;min-width:0;}
.tpl-filter-fixed{flex:0 0 auto;white-space:nowrap;}
</style>
<form method="get" action="transferpicklist_outlet.php">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    <div class="tpl-filter-bar">
        <?php if ($_is_admin_tpl): ?>
        <div style="flex:0 0 150px;">
            <span class="tpl-filter-label">Outlet</span>
            <select name="filter_outlet">
                <option value="">-- All Outlets --</option>
                <?php foreach ($adminOutletList as $_aoc): ?>
                <option value="<?php echo htmlspecialchars($_aoc); ?>" <?php echo $filterAdminOutlet === $_aoc ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($_aoc); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php elseif (!empty($mgrOutletList)): ?>
        <div style="flex:0 0 150px;">
            <span class="tpl-filter-label">Outlet</span>
            <select name="filter_outlet">
                <option value="">-- All My Outlets --</option>
                <?php foreach ($mgrOutletList as $_moc): ?>
                <option value="<?php echo htmlspecialchars($_moc); ?>" <?php echo $filterAdminOutlet === $_moc ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($_moc); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div style="flex:0 0 280px;">
            <span class="tpl-filter-label">Pick List</span>
            <select name="picklist_no" style="width:100%">
                <option value="">-- All Pick Lists --</option>
                <?php foreach ($picklistOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt['picklist_no']); ?>"
                    <?php echo $filterPicklist === $opt['picklist_no'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($opt['picklist_no']); ?>
                    (<?php echo date('d M Y', strtotime($opt['timestamp'])); ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:0 0 140px;">
            <span class="tpl-filter-label">Transfer Route</span>
            <select name="priority" title="Where Generate sent each item: first to an outlet under the same Area Manager, then to any outlet in the same region; if no outlet needs it, HQ items return to that region's warehouse and Non-HQ items stay at the outlet.">
                <?php tplRouteOptions($filterPriority, tplPicklistKind($filterPicklist), true); ?>
            </select>
        </div>
        <div style="flex:0 0 130px;">
            <span class="tpl-filter-label">Status</span>
            <select name="status">
                <option value="">-- All Status --</option>
                <option value="pending"     <?php echo $filterStatus === 'pending'     ? 'selected' : ''; ?>>Pending</option>
                <option value="transferred" <?php echo $filterStatus === 'transferred' ? 'selected' : ''; ?>>Confirmed</option>
            </select>
        </div>
        <div style="flex:0 0 110px;">
            <span class="tpl-filter-label">Item Type</span>
            <select name="item_type">
                <option value="">-- All --</option>
                <option value="hq"     <?php echo $filterItemType === 'hq'     ? 'selected' : ''; ?>>HQ</option>
                <option value="non_hq" <?php echo $filterItemType === 'non_hq' ? 'selected' : ''; ?>>Non-HQ</option>
            </select>
        </div>
        <div class="tpl-filter-grow">
            <span class="tpl-filter-label">Search (Item Code / Description / Transfer To)</span>
            <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Code, description or outlet...">
        </div>
        <div class="tpl-filter-fixed">
            <span class="tpl-filter-label">&nbsp;</span>
            <input type="submit" value="Filter" class="alpro-btn alpro-btn-blue" style="height:32px;padding:0 18px;">
            <a href="transferpicklist_outlet.php?view=<?php echo htmlspecialchars($view); ?>" class="alpro-btn alpro-btn-grey" style="height:32px;padding:0 14px;line-height:32px;display:inline-block;">Reset</a>
            <a href="transferpicklist_outlet.php?view=outgoing&export=1<?php
                echo $filterAdminOutlet !== '' ? '&filter_outlet=' . urlencode($filterAdminOutlet) : '';
                echo $filterPicklist    !== '' ? '&picklist_no='   . urlencode($filterPicklist)    : '';
                echo $filterPriority    !== '' ? '&priority='      . urlencode($filterPriority)    : '';
                echo $filterStatus      !== '' ? '&status='        . urlencode($filterStatus)      : '';
                echo $filterItemType    !== '' ? '&item_type='     . urlencode($filterItemType)    : '';
                echo $filterSearch      !== '' ? '&search='        . urlencode($filterSearch)      : '';
            ?>" class="alpro-btn alpro-btn-green" style="height:32px;padding:0 18px;line-height:32px;display:inline-block;">&#8659; Export CSV</a>
        </div>
    </div>
</form>
<?php endif; ?>

<?php if ($view === 'outgoing'): ?>
<form method="post" id="confirmForm">
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th width="80">Type</th>
        <th>From</th>
        <th>Transfer To</th>
        <th>Item Code</th>
        <th>Description</th>
        <th>Priority</th>
        <th>Status</th>
        <th>Qty to Send</th>
    </tr>
    <?php if (empty($items)): ?>
    <tr><td colspan="9" class="alpro-center alpro-muted">No transfer tasks assigned.</td></tr>
    <?php endif; ?>
    <?php $rowNum = $offset; foreach ((array)$items as $item): $rowNum++; ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rowNum; ?></td>
        <td class="alpro-center">
            <?php if (isset($item['item_model']) && $item['item_model'] === 'Store/HQ Order'): ?>
                <span style="background:#f57c00;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">Non-HQ</span>
            <?php else: ?>
                <span style="background:#1976d2;color:#fff;font-size:11px;font-weight:bold;padding:2px 6px;border-radius:3px;">HQ</span>
            <?php endif; ?>
        </td>
        <td class="alpro-mono alpro-center">
            <?php echo htmlspecialchars($item['from_code']); ?>
            <?php if (!empty($item['from_acmm'])): ?>
                <br><span class="alpro-muted" style="font-weight:normal;font-size:11px;">(<?php echo str_pad((int)$item['from_acmm'], 4, '0', STR_PAD_LEFT); ?>)</span>
            <?php endif; ?>
        </td>
        <td class="alpro-mono alpro-center">
            <?php echo htmlspecialchars($item['to_code']); ?>
            <?php if (!empty($item['to_acmm'])): ?>
                <br><span class="alpro-muted" style="font-weight:normal;font-size:11px;">(<?php echo str_pad((int)$item['to_acmm'], 4, '0', STR_PAD_LEFT); ?>)</span>
            <?php endif; ?>
        </td>
        <td class="alpro-mono alpro-center"><?php echo htmlspecialchars($item['item_code']); ?></td>
        <td><?php echo htmlspecialchars($item['item_desc'] ? $item['item_desc'] : '—'); ?></td>
        <td class="alpro-center">
            <?php if ($item['priority_type'] === 'same_area'): ?>
                <span style="color:#388e3c;">Same Area</span>
            <?php elseif ($item['priority_type'] === 'peninsular'): ?>
                <span style="color:#1976d2;">Peninsular</span>
            <?php elseif ($item['priority_type'] === 'sabah'): ?>
                <span style="color:#7b1fa2;">Sabah</span>
            <?php elseif ($item['priority_type'] === 'sarawak'): ?>
                <span style="color:#0288d1;">Sarawak</span>
            <?php elseif ($item['priority_type'] === 'warehouse'): ?>
                <span style="color:#c62828;">Warehouse</span>
            <?php elseif ($item['priority_type'] === 'maintain'): ?>
                <span style="color:#888;">Maintain</span>
            <?php else: ?>
                <span style="color:#1976d2;"><?php echo htmlspecialchars($item['priority_type']); ?></span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php if ($item['status'] === 'pending'): ?>
                <span style="color:#f57c00;">Pending</span>
            <?php elseif ($item['status'] === 'transferred'): ?>
                <span style="color:#388e3c;">Confirmed</span>
            <?php else: ?>
                <span style="color:#c62828;">Rejected</span>
                <?php if (isset($reassignMap[(int)$item['id']])): ?>
                    <span style="color:#388e3c;"> &#8594; <?php echo htmlspecialchars($reassignMap[(int)$item['id']]); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </td>
        <td class="alpro-center" style="font-weight:bold;">
            <?php echo number_format((float)$item['qty_transfer'], 0); ?>
            <?php if ($item['status'] === 'pending'): ?>
                <input type="hidden" name="pending_ids[]" value="<?php echo (int)$item['id']; ?>">
                <input type="hidden" name="qty_actual[<?php echo (int)$item['id']; ?>]" value="<?php echo (int)$item['qty_transfer']; ?>">
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>
<?php
$pgBase = 'transferpicklist_outlet.php?view=outgoing'
    . ($filterAdminOutlet !== '' ? '&filter_outlet=' . urlencode($filterAdminOutlet) : '')
    . ($filterPicklist    !== '' ? '&picklist_no='   . urlencode($filterPicklist)    : '')
    . ($filterPriority    !== '' ? '&priority='      . urlencode($filterPriority)    : '')
    . ($filterStatus      !== '' ? '&status='        . urlencode($filterStatus)      : '')
    . ($filterItemType    !== '' ? '&item_type='     . urlencode($filterItemType)    : '')
    . ($filterSearch      !== '' ? '&search='        . urlencode($filterSearch)      : '');
?>
<?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } // shared list footer (transferpicklist_pager.php) ?>

</form>

<?php if ($pendingAllCount > 0 && $page == $totalPages && $_multiOutlet): ?>
<div class="tpl-confirm-bar" style="margin-top:10px; display:flex; justify-content:flex-end;">
    <span style="font-size:12px; color:#8d6e00; background:#fff8e1; border:1px solid #ffe082; border-radius:6px; padding:6px 12px;">
        &#9888; <?php echo number_format($pendingAllCount); ?> pending — choose <b>one outlet</b> in the Outlet filter above to confirm. Items are confirmed per outlet.
    </span>
</div>
<?php endif; ?>
<?php if ($pendingAllCount > 0 && $page == $totalPages && !$_multiOutlet):
    // Build modal detail values
    $modalPlNo   = $filterPicklist !== '' ? $filterPicklist : 'All Pick Lists';
    $modalPlDate = '';
    foreach ($picklistOptions as $_opt) {
        if ($_opt['picklist_no'] === $filterPicklist) {
            $modalPlDate = date('d M Y, h:i A', strtotime($_opt['timestamp']));
            break;
        }
    }
    if ($_is_admin_tpl) {
        $modalOutlet     = $filterAdminOutlet !== '' ? $filterAdminOutlet : 'All Outlets';
        $modalOutletName = '';
        if ($filterAdminOutlet !== '') {
            $_onRes = $conn->query("SELECT comp_name FROM outlet WHERE code = '" . $conn->real_escape_string($filterAdminOutlet) . "' LIMIT 1");
            if ($_onRes && $_on = $_onRes->fetch_assoc()) { $modalOutletName = $_on['comp_name']; }
        }
    } else {
        $modalOutlet     = implode(', ', array_column($myOutlets, 'code'));
        $modalOutletName = (count($myOutlets) === 1) ? $myOutlets[0]['comp_name'] : '';
    }
    $modalTimestamp = date('d M Y, h:i A');
?>
<!-- Confirm All Modal -->
<style>
.tpl-cm-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9999;align-items:center;justify-content:center;}
.tpl-cm{background:#fff;border-radius:12px;width:92%;max-width:440px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden;font-size:13px;text-align:left;}
.tpl-cm-head{display:flex;align-items:center;gap:12px;padding:18px 22px 14px;}
.tpl-cm-icon{flex:0 0 36px;height:36px;border-radius:50%;background:#e8f5e9;color:#2e7d32;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:bold;}
.tpl-cm-title{font-size:16px;font-weight:700;color:#222;}
.tpl-cm-sub{font-size:12px;color:#888;margin-top:2px;}
.tpl-cm-body{padding:0 22px;}
.tpl-cm-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:9px 0;border-top:1px solid #f0f0f0;}
.tpl-cm-row:first-child{border-top:none;}
.tpl-cm-label{color:#888;flex:0 0 auto;}
.tpl-cm-value{color:#222;font-weight:600;text-align:right;}
.tpl-cm-value small{display:block;font-weight:normal;color:#888;font-size:11px;margin-top:2px;}
.tpl-cm-count{font-size:15px;color:#2e7d32;}
.tpl-cm-warn{margin:12px 22px 0;padding:8px 12px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;color:#8d6e00;font-size:12px;}
.tpl-cm-foot{display:flex;justify-content:flex-end;gap:8px;padding:16px 22px 18px;}
.tpl-cm-btn{height:34px;padding:0 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;}
.tpl-cm-cancel{background:#fff;border:1px solid #ccc;color:#444;}
.tpl-cm-cancel:hover{background:#f5f5f5;}
.tpl-cm-ok{background:#2e7d32;border:none;color:#fff;}
.tpl-cm-ok:hover{background:#1b5e20;}
</style>
<div id="confirmAllModal" class="tpl-cm-overlay" onclick="if(event.target===this){this.style.display='none';}">
    <div class="tpl-cm">
        <div class="tpl-cm-head">
            <div class="tpl-cm-icon">&#10003;</div>
            <div>
                <div class="tpl-cm-title">Confirm all pending items?</div>
                <div class="tpl-cm-sub">They will be marked as transferred.</div>
            </div>
        </div>
        <div class="tpl-cm-body">
            <div class="tpl-cm-row">
                <span class="tpl-cm-label">Pick List</span>
                <span class="tpl-cm-value"><?php echo htmlspecialchars($modalPlNo); ?>
                    <?php if ($modalPlDate): ?><small><?php echo htmlspecialchars($modalPlDate); ?></small><?php endif; ?>
                </span>
            </div>
            <div class="tpl-cm-row">
                <span class="tpl-cm-label">Outlet</span>
                <span class="tpl-cm-value"><?php echo htmlspecialchars($modalOutlet); ?>
                    <?php if ($modalOutletName): ?><small><?php echo htmlspecialchars($modalOutletName); ?></small><?php endif; ?>
                </span>
            </div>
            <div class="tpl-cm-row">
                <span class="tpl-cm-label">Items</span>
                <span class="tpl-cm-value tpl-cm-count"><?php echo number_format($pendingAllCount); ?> pending</span>
            </div>
            <div class="tpl-cm-row">
                <span class="tpl-cm-label">Confirmed At</span>
                <span class="tpl-cm-value" style="font-weight:normal;"><?php echo $modalTimestamp; ?></span>
            </div>
        </div>
        <div class="tpl-cm-warn">&#9888; This can't be undone.</div>
        <div class="tpl-cm-foot">
            <button type="button" class="tpl-cm-btn tpl-cm-cancel" onclick="document.getElementById('confirmAllModal').style.display='none';">Cancel</button>
            <button type="button" class="tpl-cm-btn tpl-cm-ok" onclick="this.disabled=true; document.getElementById('confirmAllForm').submit();">&#10003; Yes, Confirm All</button>
        </div>
    </div>
</div>

<style>
.tpl-confirm-bar{margin-top:10px;display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;}
.tpl-confirm-hint{font-size:12px;color:#777;}
.tpl-confirm-btn{display:inline-flex;align-items:center;gap:8px;height:34px;padding:0 14px 0 16px;font-size:13px;font-weight:600;
    background:#2e7d32;color:#fff;border:none;border-radius:6px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.15);transition:background .15s;}
.tpl-confirm-btn:hover{background:#1b5e20;}
.tpl-confirm-count{background:rgba(255,255,255,.22);border-radius:10px;padding:1px 8px;font-size:12px;}
</style>
<div class="tpl-confirm-bar">
    <span class="tpl-confirm-hint">Confirms every pending item in the current filter, on all pages</span>
    <form id="confirmAllForm" method="post" style="display:inline;">
        <input type="hidden" name="confirm_all"  value="1">
        <input type="hidden" name="ca_picklist"  value="<?php echo htmlspecialchars($filterPicklist); ?>">
        <input type="hidden" name="ca_priority"  value="<?php echo htmlspecialchars($filterPriority); ?>">
        <input type="hidden" name="ca_item_type" value="<?php echo htmlspecialchars($filterItemType); ?>">
        <input type="hidden" name="ca_search"    value="<?php echo htmlspecialchars($filterSearch); ?>">
    </form>
    <button type="button" class="tpl-confirm-btn" onclick="document.getElementById('confirmAllModal').style.display='flex';">
        &#10003; Confirm All <span class="tpl-confirm-count"><?php echo (int)$pendingAllCount; ?> pending</span>
    </button>
</div>
<?php endif; ?>

<?php if (!empty($confirmLog) && $page == $totalPages): ?>
<div style="margin-top:10px; display:flex; flex-direction:column; align-items:flex-end; gap:3px; font-size:12px; color:#555;">
    <?php foreach ($confirmLog as $cl): ?>
    <div style="border-right:3px solid #2e7d32; padding:2px 10px 2px 0; text-align:right;">
        <span style="color:#2e7d32; font-weight:600;">&#10003; <?php echo number_format((int)$cl['n']); ?> item<?php echo (int)$cl['n'] === 1 ? '' : 's'; ?> confirmed</span>
        by <b><?php echo htmlspecialchars($cl['confirmed_by'] !== null && $cl['confirmed_by'] !== '' ? $cl['confirmed_by'] : 'Unknown'); ?></b>
        &middot; <?php echo date('d M Y, h:i A', strtotime($cl['at'])); ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th>Pick List No</th>
        <th>From</th>
        <th>Item Code</th>
        <th>Qty</th>
        <th>To</th>
        <th>Priority</th>
        <th>Status</th>
    </tr>
    <?php if (!$items || $items->num_rows === 0): ?>
    <tr><td colspan="8" class="alpro-center alpro-muted">No incoming transfers.</td></tr>
    <?php endif; ?>
    <?php $rowNum = $offset; while ($items && $item = $items->fetch_assoc()): $rowNum++; ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $rowNum; ?></td>
        <td class="alpro-mono"><?php echo htmlspecialchars($item['picklist_no']); ?></td>
        <td>
            <span class="alpro-mono"><?php echo htmlspecialchars($item['from_code']); ?></span>
            <?php echo htmlspecialchars($item['from_name']); ?>
        </td>
        <td class="alpro-mono"><?php echo htmlspecialchars($item['item_code']); ?></td>
        <td class="alpro-center"><?php echo number_format((float)$item['qty_transfer'], 0); ?></td>
        <td class="alpro-mono"><?php echo htmlspecialchars($item['to_code']); ?></td>
        <td class="alpro-center">
            <?php if ($item['priority_type'] === 'same_area'): ?>
                <span style="color:#388e3c;">Same Area</span>
            <?php elseif ($item['priority_type'] === 'peninsular'): ?>
                <span style="color:#1976d2;">Peninsular</span>
            <?php elseif ($item['priority_type'] === 'sabah'): ?>
                <span style="color:#7b1fa2;">Sabah</span>
            <?php elseif ($item['priority_type'] === 'sarawak'): ?>
                <span style="color:#0288d1;">Sarawak</span>
            <?php elseif ($item['priority_type'] === 'warehouse'): ?>
                <span style="color:#c62828;">Warehouse</span>
            <?php elseif ($item['priority_type'] === 'maintain'): ?>
                <span style="color:#888;">Maintain</span>
            <?php else: ?>
                <span style="color:#1976d2;"><?php echo htmlspecialchars($item['priority_type']); ?></span>
            <?php endif; ?>
        </td>
        <td class="alpro-center">
            <?php if ($item['status'] === 'pending'): ?>
                <span style="color:#f57c00;">Pending</span>
            <?php elseif ($item['status'] === 'transferred'): ?>
                <span style="color:#388e3c;">Confirmed</span>
            <?php else: ?>
                <span style="color:#c62828;">Cancelled</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
</table>
</fieldset>
<?php
$pgBase = 'transferpicklist_outlet.php?view=incoming'
    . ($filterPicklist  !== '' ? '&picklist_no=' . urlencode($filterPicklist)  : '')
    . ($filterPriority  !== '' ? '&priority='    . urlencode($filterPriority)  : '')
    . ($filterStatus    !== '' ? '&status='      . urlencode($filterStatus)    : '')
    . ($filterItemCode  !== '' ? '&item_code='   . urlencode($filterItemCode)  : '');
?>
<?php if ($totalItems > 0) { tplPager($page, $totalPages, $totalItems, $perPage); } // shared list footer (transferpicklist_pager.php) ?>
<?php endif; ?>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
