<?php
// AUTO-GENERATE MODE: `php transferpicklist_generate.php --auto` — command line only,
// run at the end of the scheduled sync .bat files (hyper/past3m_sync.bat,
// sync_group_a.bat, sync_group_b.bat). It generates only when every sync has
// finished this month and nothing was generated since (see tplAutoGenCheck), then
// runs exactly the same generation code as the button. Never reachable from a browser.
$_tplAutoGen = (php_sapi_name() === 'cli') && in_array('--auto', isset($argv) ? $argv : array(), true);
if (!$_tplAutoGen) { require_once('../lock_adv.php'); }
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);
include(__DIR__ . '/../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
// PHP 8.1+ makes mysqli throw on query errors by default; this script's own
// error handling (the per-step checks below) expects the legacy
// return-false-on-error behavior, so restore it.
mysqli_report(MYSQLI_REPORT_OFF);
if (!$_tplAutoGen && (int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }

// ── Auto-generate readiness check ─────────────────────────────
// Returns a list of reasons NOT to generate (empty = ready).
function tplAutoGenCheck($conn) {
    $syncs = array(
        'Past 3 Months'       => 'transferpicklist_past3months_status',
        'Item Grade'          => 'transferpicklist_itemgrade_status',
        'Slow Moving'         => 'transferpicklist_slowmoving_status',
        'Slow Moving Ethical' => 'transferpicklist_slowmoving_ethical_status',
        'Non Moving'          => 'transferpicklist_nonmoving_status',
        'Non Moving Ethical'  => 'transferpicklist_nonmoving_ethical_status',
    );
    $monthStart = date('Y-m-01 00:00:00');
    $latest     = null;
    $problems   = array();
    foreach ($syncs as $label => $table) {
        $r = $conn->query("SELECT COUNT(*) AS total, SUM(status = 0) AS pending, MAX(last_sync) AS last FROM $table");
        if (!$r) { $problems[] = "$label: status table not found"; continue; }
        $row = $r->fetch_assoc();
        if ((int)$row['total'] === 0) {
            $problems[] = "$label: no outlets registered";
        } elseif ((int)$row['pending'] > 0) {
            $problems[] = "$label: " . (int)$row['pending'] . " outlet(s) not synced yet";
        } elseif ($row['last'] === null || $row['last'] < $monthStart) {
            $problems[] = "$label: not synced this month yet (last: " . ($row['last'] ? $row['last'] : 'never') . ")";
        }
        if ($row['last'] !== null && ($latest === null || $row['last'] > $latest)) { $latest = $row['last']; }
    }
    if (empty($problems) && $latest !== null) {
        $esc = $conn->real_escape_string($latest);
        $r = $conn->query("SELECT picklist_no, timestamp FROM transferpicklist_picklist
                           WHERE picklist_no LIKE '%-outlet' AND timestamp >= '$esc' ORDER BY id DESC LIMIT 1");
        if ($r && ($pl = $r->fetch_assoc())) {
            $problems[] = "Already generated after the last sync ($latest): " . $pl['picklist_no'] . " at " . $pl['timestamp'];
        }
    }
    return $problems;
}

if ($_tplAutoGen) {
    if (!is_dir(__DIR__ . '/logs')) { mkdir(__DIR__ . '/logs', 0755, true); }
    $_autoLog = function($msg) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        echo $line;
        file_put_contents(__DIR__ . '/logs/auto_generate.log', $line, FILE_APPEND);
    };
    $_problems = tplAutoGenCheck($conn);
    if (!empty($_problems)) {
        $_autoLog('AUTO GENERATE SKIPPED — ' . implode(' | ', $_problems));
        exit(0);
    }
    $_autoLog('AUTO GENERATE STARTED — all 6 syncs complete for ' . date('F Y') . '. Details: logs/generate_picklist.log');
    register_shutdown_function(function() use ($_autoLog) {
        $_autoLog('AUTO GENERATE FINISHED — see logs/generate_picklist.log for the result.');
    });
    // Run the normal generate handler below exactly as if the button was clicked
    $_GET['ajax']      = '1';
    $_POST['generate'] = '1';
}

$msg        = '';
$msg_type   = '';
$preview    = array();
$generated  = null;

// ============================================================
// AJAX GENERATE HANDLER — returns JSON, no HTML
// ============================================================
// ── Log file helpers ─────────────────────────────────────────
$_genLogFile = __DIR__ . '/logs/generate_picklist.log';
if (!is_dir(__DIR__ . '/logs')) { mkdir(__DIR__ . '/logs', 0755, true); }
function genLog($msg) {
    global $_genLogFile;
    file_put_contents($_genLogFile, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ── Log read endpoint ─────────────────────────────────────────
if (isset($_GET['genlog'])) {
    session_write_close(); // release session lock immediately so other requests aren't blocked
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/plain');
    echo file_exists($_genLogFile) ? file_get_contents($_genLogFile) : '';
    exit;
}

$_isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($_isAjax) { ob_start(); } // buffer all output so we can discard it before sending JSON
if ($_isAjax && isset($_POST['generate'])) {
    ignore_user_abort(true);
    session_write_close(); // release session lock so poll requests aren't blocked during long generation
    // Clear old log
    file_put_contents($_genLogFile, '');
    genLog('Starting pick list generation...');

    $generated_by = $_tplAutoGen ? 'AUTO (after sync)' : (isset($nama_staff) && $nama_staff !== '' ? $nama_staff : 'HQ');
    $timestamp    = date('Y-m-d H:i:s');

    // Generate picklist_no: TPL-YYYYMMDD-XXX-P1 (outlet) and -P2 (warehouse)
    $date_str = date('Ymd');
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM transferpicklist_picklist WHERE DATE(timestamp) = CURDATE() AND picklist_no LIKE '%-outlet'");
    $stmt->execute();
    $cnt_row   = $stmt->get_result()->fetch_assoc();
    $seq       = str_pad((int)$cnt_row['cnt'] + 1, 3, '0', STR_PAD_LEFT);
    $stmt->close();
    $base_no        = 'TPL-' . $date_str . '-' . $seq;
    $picklist_no_p1 = $base_no . '-outlet';
    $picklist_no_p2 = $base_no . '-warehouse';

    // Insert Phase 1 header (outlet: same_area, region, maintain)
    $stmt = $conn->prepare("INSERT INTO transferpicklist_picklist (picklist_no, generated_by, status, timestamp) VALUES (?,?,?,?)");
    $status = 'draft';
    $stmt->bind_param('ssss', $picklist_no_p1, $generated_by, $status, $timestamp);
    $stmt->execute();
    $picklist_id_p1 = $conn->insert_id;
    $stmt->close();

    // Insert Phase 2 header (warehouse returns)
    $stmt = $conn->prepare("INSERT INTO transferpicklist_picklist (picklist_no, generated_by, status, timestamp) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $picklist_no_p2, $generated_by, $status, $timestamp);
    $stmt->execute();
    $picklist_id_p2 = $conn->insert_id;
    $stmt->close();

    // Bail out if either header insert failed — otherwise every item row
    // below would be written with picklist_id=0 (orphaned, unrecoverable).
    if ($picklist_id_p1 <= 0 || $picklist_id_p2 <= 0) {
        genLog('ERROR: Failed to create pick list header row(s). ' . $conn->error);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'msg' => 'Failed to create pick list: ' . $conn->error));
        exit;
    }

    // ============================================================
    // ALGORITHM: redistribute slow-moving stock (same manager first, then other managers)🌷
    // ============================================================

    genLog('Pick list numbers: ' . $picklist_no_p1 . ' (outlet) | ' . $picklist_no_p2 . ' (warehouse)');

    // 1. Outlet → area manager mapping
    $outletManager = array();
    $res = $conn->query("SELECT id, outlet FROM staff WHERE status_semasa = 'Area Manager' AND recycle = 0 AND outlet != ''");
    if (!$res) {
        genLog('ERROR in Step 1 (staff) query: ' . $conn->error);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'msg' => 'Step 1 query failed: ' . $conn->error));
        exit;
    }
    while ($row = $res->fetch_assoc()) {
        $managerId = (int)$row['id'];
        foreach (explode(',', $row['outlet']) as $oid) {
            $oid = (int)trim($oid);
            if ($oid > 0) { $outletManager[$oid] = $managerId; }
        }
    }
    genLog('Step 1: Loaded ' . count($outletManager) . ' outlet-manager mappings from ' . count(array_unique(array_values($outletManager))) . ' area managers.');

    // 2. Outlet → region mapping (Q prefix = Sarawak, SKK prefix = Sabah, else Peninsular)
    $outletRegion = array();
    $outletCode   = array();
    $res = $conn->query("SELECT id, code FROM outlet WHERE recycle = 0");
    if (!$res) {
        genLog('ERROR in Step 2 (outlet) query: ' . $conn->error);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'msg' => 'Step 2 query failed: ' . $conn->error));
        exit;
    }
    while ($row = $res->fetch_assoc()) {
        $id   = (int)$row['id'];
        $code = strtoupper(trim($row['code']));
        $outletCode[$id] = $code;
        if (strpos($code, 'Q') === 0) {
            $outletRegion[$id] = 'sarawak';
        } elseif (strpos($code, 'S') === 0) {
            $outletRegion[$id] = 'sabah';
        } else {
            $outletRegion[$id] = 'peninsular';
        }
    }

    // 3. Regional warehouse IDs🌷
    $regionalWarehouse = array(
        'peninsular' => null,
        'sabah'      => null,
        'sarawak'    => null,
    );
    // tukar sabah sarawak lain nanti
    // acmm_id: 1001=Peninsular (NSDWH1), 1002=Sarawak (QKCWH1), 1013=Sabah (SKKWH1)
    $res = $conn->query("SELECT id, acmm_id FROM outlet WHERE acmm_id IN (1001, 1002, 1013) AND recycle=0");
    if (!$res) {
        genLog('ERROR in Step 3 (warehouse) query: ' . $conn->error);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'msg' => 'Step 3 query failed: ' . $conn->error));
        exit;
    }
    while ($row = $res->fetch_assoc()) {
        if ((int)$row['acmm_id'] === 1001) { $regionalWarehouse['peninsular'] = (int)$row['id']; }
        if ((int)$row['acmm_id'] === 1013) { $regionalWarehouse['sabah']      = (int)$row['id']; }
        if ((int)$row['acmm_id'] === 1002) { $regionalWarehouse['sarawak']    = (int)$row['id']; }
    }

    genLog('Step 2: Region lookup ready (includes all outlets + warehouses for routing). Peninsular WH: ' . ($regionalWarehouse['peninsular'] ? 'OK' : 'missing') . ', Sabah WH: ' . ($regionalWarehouse['sabah'] ? 'OK' : 'missing') . ', Sarawak WH: ' . ($regionalWarehouse['sarawak'] ? 'OK' : 'missing') . '.');

    // 4a. Load manually excluded outlets (set via Outlet Exclusion page)
    // Use outlet_code to find all matching outlet IDs — avoids ID mismatch between tables
    $monthlyExcludedOutletIds = array(0);
    $exMonthRes = $conn->query("SELECT outlet_code FROM transferpicklist_outlet_exclusion");
    $excludedCodes = array();
    if ($exMonthRes) {
        while ($exMonthRow = $exMonthRes->fetch_assoc()) {
            $excludedCodes[] = "'" . $conn->real_escape_string($exMonthRow['outlet_code']) . "'";
        }
    }
    if (!empty($excludedCodes)) {
        $exIdRes = $conn->query("SELECT id FROM outlet WHERE code IN (" . implode(',', $excludedCodes) . ") AND recycle = 0");
        if ($exIdRes) { while ($exIdRow = $exIdRes->fetch_assoc()) { $monthlyExcludedOutletIds[] = (int)$exIdRow['id']; } }
    }
    $monthlyExcludedIdList = implode(',', array_unique($monthlyExcludedOutletIds));
    genLog('Step 4a: Manually excluded outlets: ' . count($excludedCodes) . ' outlet code(s) → ' . (count($monthlyExcludedOutletIds) - 1) . ' outlet ID(s) excluded.');

    // 4. Load supply: regular non-moving + ethical non-moving (UNION)
    // Ethical items are flagged — they can only transfer outlet-to-outlet (no warehouse return)
    $supply = array();
    $ethicalSupplyItems = array(); // item_codes that came from ethical nonmoving

    // Check if ethical table exists before UNION — may not exist on older installs
    $ethicalTableExists = false;
    $chkEth = $conn->query("SHOW TABLES LIKE 'transferpicklist_nonmoving_ethical'");
    if ($chkEth && $chkEth->num_rows > 0) { $ethicalTableExists = true; }

    // Pre-load global excluded item codes (all outlets)
    $excludedGlobal = array(); // key: item_code
    $exGRes = $conn->query("SELECT item_code FROM transferpicklist_excluded_items");
    if ($exGRes) { while ($exGRow = $exGRes->fetch_assoc()) { $excludedGlobal[$exGRow['item_code']] = true; } }

    // Pre-load per-outlet excluded pairs
    $excludedPairs = array(); // key: "item_code|outlet_code"
    $exRes = $conn->query("SELECT item_code, outlet_code FROM transferpicklist_excluded");
    if ($exRes) { while ($exRow = $exRes->fetch_assoc()) { $excludedPairs[$exRow['item_code'] . '|' . $exRow['outlet_code']] = true; } }
    genLog('Step 4: Loading supply' . ($ethicalTableExists ? ' (regular + ethical)' : ' (regular only — ethical table not found)') . '. Global exclusions: ' . count($excludedGlobal) . ' item(s). Per-outlet exclusions: ' . count($excludedPairs) . ' pair(s)...');

    $ethicalUnion = $ethicalTableExists ? "
        UNION ALL
        SELECT nme.outlet_id, nme.item_code,
               GREATEST(0, GREATEST(0, nme.closing_stock) - GREATEST(0, COALESCE(nme.avg_monthly, 0))) AS transferable_qty,
               'ethical' AS category
        FROM transferpicklist_nonmoving_ethical nme
        WHERE nme.closing_stock > 0
          AND GREATEST(0, GREATEST(0, nme.closing_stock) - GREATEST(0, COALESCE(nme.avg_monthly, 0))) > 0
          AND nme.outlet_id NOT IN ($monthlyExcludedIdList)
    " : "";

    $res = $conn->query("
        SELECT nm.outlet_id, nm.item_code,
               GREATEST(0, GREATEST(0, nm.closing_stock) - GREATEST(0, COALESCE(nm.avg_monthly, 0))) AS transferable_qty,
               'regular' AS category
        FROM transferpicklist_nonmoving nm
        WHERE nm.closing_stock > 0
          AND GREATEST(0, GREATEST(0, nm.closing_stock) - GREATEST(0, COALESCE(nm.avg_monthly, 0))) > 0
          AND nm.outlet_id NOT IN ($monthlyExcludedIdList)
        $ethicalUnion
    ");
    if (!$res) {
        genLog('ERROR in supply query: ' . $conn->error);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'msg' => 'Supply query failed: ' . $conn->error));
        exit;
    }
    while ($row = $res->fetch_assoc()) {
        $oCode = isset($outletCode[(int)$row['outlet_id']]) ? $outletCode[(int)$row['outlet_id']] : '';
        // Skip globally excluded items (all outlets)
        if (isset($excludedGlobal[$row['item_code']])) { continue; }
        // Skip per-outlet excluded pairs
        if (isset($excludedPairs[$row['item_code'] . '|' . $oCode])) { continue; }
        $supply[$row['item_code']][] = array(
            'outlet_id' => (int)$row['outlet_id'],
            'qty'       => (float)$row['transferable_qty'],
        );
        if ($row['category'] === 'ethical') {
            $ethicalSupplyItems[$row['item_code']] = true;
        }
    }
    genLog('Step 4: Supply loaded — ' . count($supply) . ' unique item codes (' . count($ethicalSupplyItems) . ' ethical). Total sender entries: ' . array_sum(array_map('count', $supply)) . '.');

    // 5. Load demand: avg 3M sales > on-hand qty (load demand sekali)🌷
    // Outlets excluded from receiving transfers (by acmm_id)
    $excludedReceiverAcmmIds = array(119, 45); // 0119=NHQEV1 (Event), 0045=NLKJP1 (Lukut)
    $excludedReceiverIds = array_merge(array(0), $monthlyExcludedOutletIds);
    $exRes = $conn->query("SELECT id FROM outlet WHERE acmm_id IN (" . implode(',', $excludedReceiverAcmmIds) . ") AND recycle = 0");
    if ($exRes) { while ($exRow = $exRes->fetch_assoc()) { $excludedReceiverIds[] = (int)$exRow['id']; } }
    $excludedReceiverIdList = implode(',', array_unique($excludedReceiverIds));

    $demand = array();
    if (!empty($supply)) {
        $supplyItems = array_map(function($c) use ($conn) { return "'" . $conn->real_escape_string($c) . "'"; }, array_keys($supply));
        $inList = implode(',', $supplyItems);
        $res = $conn->query("
            SELECT a.outlet_id, a.item_code,
                   (GREATEST(0, a.avg_sold) - GREATEST(0, b.last_month_closing)) AS need
            FROM (
                SELECT outlet_id, item_code,
                       AVG(GREATEST(0, qty_stock)) AS avg_sold,
                       MAX(month_year)             AS latest_month
                FROM transferpicklist_past3months
                WHERE item_code IN ($inList)
                GROUP BY outlet_id, item_code
            ) a
            JOIN transferpicklist_past3months b
                ON  b.outlet_id  = a.outlet_id
                AND b.item_code  = a.item_code
                AND b.month_year = a.latest_month
            LEFT JOIN transferpicklist_nonmoving nm2
                ON  nm2.outlet_id = a.outlet_id
                AND nm2.item_code = a.item_code
                AND nm2.closing_stock >= a.avg_sold
            WHERE GREATEST(0, a.avg_sold) > GREATEST(0, b.last_month_closing)
              AND (GREATEST(0, a.avg_sold) - GREATEST(0, b.last_month_closing)) >= 1
              AND nm2.outlet_id IS NULL
              AND a.outlet_id NOT IN ($excludedReceiverIdList)
        ");
        if (!$res) {
            genLog('ERROR in demand query: ' . $conn->error);
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json');
            echo json_encode(array('success' => false, 'msg' => 'Demand query failed: ' . $conn->error));
            exit;
        }
        while ($row = $res->fetch_assoc()) {
            $outletId = (int)$row['outlet_id'];
            $demand[$row['item_code']][$outletId] = array(
                'outlet_id' => $outletId,
                'need'      => (float)$row['need'],
            );
        }
    }

    genLog('Step 5: Demand loaded — ' . array_sum(array_map('count', $demand)) . ' total demand entries across ' . count($demand) . ' item codes.');

    // 6. Load Non-HQ item codes (Store/HQ Order) — stop at outlet, no warehouse fallback
    // Ethical items also stop at outlet regardless of HQ/non-HQ status
    $nonHqItems = array();
    $res = $conn->query("SELECT item_code FROM simple WHERE model = 'Store/HQ Order'");
    if ($res) { while ($row = $res->fetch_assoc()) { $nonHqItems[$row['item_code']] = true; } }
    // Merge ethical items — they behave same as non-HQ (no warehouse return)
    foreach ($ethicalSupplyItems as $ec => $v) { $nonHqItems[$ec] = true; }

    genLog('Step 6: Non-HQ items loaded: ' . count($nonHqItems) . ' (including ' . count($ethicalSupplyItems) . ' ethical).');

    // 7. Distribution
    $item_count_p1 = 0;
    $item_count_p2 = 0;
    $item_status   = 'pending';
    genLog('Step 7: Starting distribution for ' . count($supply) . ' item codes...');

    // Wrap the whole distribution loop in one transaction instead of letting
    // every single INSERT auto-commit individually — committing tens of
    // thousands of times (each with its own disk sync) is the main reason
    // this step is slow. One commit at the end is dramatically faster and
    // changes nothing about the actual data being written.
    $conn->autocommit(false);

    $stmtP1 = $conn->prepare("
        INSERT INTO transferpicklist_item
            (picklist_id, from_outlet_id, to_outlet_id, item_code, qty_transfer, priority_type, status, timestamp)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmtP2 = $conn->prepare("
        INSERT INTO transferpicklist_item
            (picklist_id, from_outlet_id, to_outlet_id, item_code, qty_transfer, priority_type, status, timestamp)
        VALUES (?,?,?,?,?,?,?,?)
    ");

    $supply_total   = count($supply);
    $supply_counter = 0;
    foreach ($supply as $itemCode => &$senders) {
        $supply_counter++;
        if ($supply_counter % 200 === 0 || $supply_counter === $supply_total) {
            genLog('  Distributing... ' . $supply_counter . ' / ' . $supply_total . ' item codes processed (outlet items so far: ' . $item_count_p1 . ', warehouse: ' . $item_count_p2 . ').');
        }
// 20/4/2026 - dekat sini $demand[$itemcode] ni dia akan share semua senders dekat bawah
        $receivers = isset($demand[$itemCode]) ? array_values($demand[$itemCode]) : array();
        usort($receivers, function($a, $b) {
            if ($b['need'] == $a['need']) { return 0; }
            return ($b['need'] > $a['need']) ? 1 : -1;
        });

        // Group receivers by manager for same-area pass
        $byManager = array();
        foreach ($receivers as $r) {
            $mgr = isset($outletManager[$r['outlet_id']]) ? $outletManager[$r['outlet_id']] : 0;
            $byManager[$mgr][] = $r['outlet_id'];
        }
// deduct $demand[$itemCode][$oid]['need'] - yang ni
        foreach ($senders as &$sender) {

            $available    = $sender['qty'];
            if ($available <= 0) { continue; }

            $senderOutlet  = $sender['outlet_id'];
            $senderManager = isset($outletManager[$senderOutlet]) ? $outletManager[$senderOutlet] : null;
            $senderRegion  = isset($outletRegion[$senderOutlet]) ? $outletRegion[$senderOutlet] : 'peninsular';

            // PASS 1 — SAME AREA MANAGER (same region only)
            if ($senderManager && isset($byManager[$senderManager])) {
                foreach ($byManager[$senderManager] as $oid) {
                    if ($available <= 0) { break; }
                    if ($oid === $senderOutlet) { continue; }
                    $receiverRegion1 = isset($outletRegion[$oid]) ? $outletRegion[$oid] : 'peninsular';
                    if ($receiverRegion1 !== $senderRegion) { continue; }
                    if (!isset($demand[$itemCode][$oid]) || $demand[$itemCode][$oid]['need'] <= 0) { continue; }
                    $qty = (int)round(min($available, $demand[$itemCode][$oid]['need']));
                    if ($qty <= 0) { continue; }
                    $priorityType = 'same_area';
                    $stmtP1->bind_param('iiisdsss', $picklist_id_p1, $senderOutlet, $oid, $itemCode, $qty, $priorityType, $item_status, $timestamp);
                    $stmtP1->execute();
                    $item_count_p1++;
                    $available -= $qty;
                    $demand[$itemCode][$oid]['need'] -= $qty;
                }
            }

            // PASS 2 — SAME REGION (Peninsular / Sabah / Sarawak)
            if ($available > 0) {
                foreach ($receivers as $r) {
                    if ($available <= 0) { break; }
                    $oid = $r['outlet_id'];
                    if ($oid === $senderOutlet) { continue; }
                    // skip same manager outlets (already handled in pass 1)
                    $mgr = isset($outletManager[$oid]) ? $outletManager[$oid] : 0;
                    if ($senderManager && $mgr === $senderManager) { continue; }
                    // same region only
                    $receiverRegion = isset($outletRegion[$oid]) ? $outletRegion[$oid] : 'peninsular';
                    if ($receiverRegion !== $senderRegion) { continue; }
                    if (!isset($demand[$itemCode][$oid]) || $demand[$itemCode][$oid]['need'] <= 0) { continue; }
                    $qty = (int)round(min($available, $demand[$itemCode][$oid]['need']));
                    if ($qty <= 0) { continue; }
                    $priorityType = $senderRegion; // 'peninsular', 'sabah', or 'sarawak'
                    $stmtP1->bind_param('iiisdsss', $picklist_id_p1, $senderOutlet, $oid, $itemCode, $qty, $priorityType, $item_status, $timestamp);
                    $stmtP1->execute();
                    $item_count_p1++;
                    $available -= $qty;
                    $demand[$itemCode][$oid]['need'] -= $qty;
                }
            }

            // PASS 3 — WAREHOUSE (HQ items only) or MAINTAIN (Non-HQ items — record for outlet visibility)
            if ($available > 0) {
                if (!isset($nonHqItems[$itemCode])) {
                    $whId = isset($regionalWarehouse[$senderRegion]) ? $regionalWarehouse[$senderRegion] : null;
                    if ($whId && $senderOutlet !== $whId) {
                        $priorityType = 'warehouse';
                        $stmtP2->bind_param('iiisdsss', $picklist_id_p2, $senderOutlet, $whId, $itemCode, $available, $priorityType, $item_status, $timestamp);
                        $stmtP2->execute();
                        $item_count_p2++;
                        $available = 0;
                    }
                } else {
                    $priorityType = 'maintain';
                    $stmtP1->bind_param('iiisdsss', $picklist_id_p1, $senderOutlet, $senderOutlet, $itemCode, $available, $priorityType, $item_status, $timestamp);
                    $stmtP1->execute();
                    $item_count_p1++;
                    $available = 0;
                }
            }

            $sender['qty'] = $available;
        }
        unset($sender);
    }
    unset($senders);

    $stmtP1->close();
    $stmtP2->close();

    // Commit everything from the distribution loop in one go, then restore
    // normal auto-commit behavior for the rest of the script.
    $conn->commit();
    $conn->autocommit(true);

    genLog('Step 7 done. Outlet items: ' . $item_count_p1 . ' | Warehouse items: ' . $item_count_p2 . '.');

    // Log both phases
    $logStmt = $conn->prepare("INSERT INTO transferpicklist_log (picklist_id, action, by_user, timestamp) VALUES (?,?,?,?)");
    $actionP1 = 'Generated Phase 1 (Outlet) with ' . $item_count_p1 . ' items';
    $logStmt->bind_param('isss', $picklist_id_p1, $actionP1, $generated_by, $timestamp);
    $logStmt->execute();
    $actionP2 = 'Generated Phase 2 (Warehouse) with ' . $item_count_p2 . ' items';
    $logStmt->bind_param('isss', $picklist_id_p2, $actionP2, $generated_by, $timestamp);
    $logStmt->execute();
    $logStmt->close();

    genLog('DONE. Pick list ' . $picklist_no_p1 . ' (' . $item_count_p1 . ' outlet items) and ' . $picklist_no_p2 . ' (' . $item_count_p2 . ' warehouse items) created successfully.');
    $msg      = 'Pick lists generated — <b>' . htmlspecialchars($picklist_no_p1) . '</b> (' . $item_count_p1 . ' outlet items) and <b>' . htmlspecialchars($picklist_no_p2) . '</b> (' . $item_count_p2 . ' warehouse items).';
    $msg_type = 'success';
    $generated = $picklist_id_p1;

    if ($_isAjax) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(array(
            'success'        => true,
            'id'             => (int)$picklist_id_p1,
            'picklist_no'    => $picklist_no_p1,
            'id_p2'          => (int)$picklist_id_p2,
            'picklist_no_p2' => $picklist_no_p2,
        ));
        exit;
    }
}

// Preview counts sebelum generating
$stmt = $conn->prepare("SELECT COUNT(DISTINCT outlet_id, item_code) as total FROM transferpicklist_nonmoving");
$stmt->execute();
$preview_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nonmoving_count = $preview_row ? (int)$preview_row['total'] : 0;

// Data month/year info
$nm_sync_date = '';
$res = $conn->query("SELECT MAX(last_sync) as latest FROM transferpicklist_nonmoving_status WHERE status=1");
if ($r = $res->fetch_assoc()) { $nm_sync_date = $r['latest']; }

$p3m_months = array();
$res = $conn->query("SELECT DISTINCT month_year FROM transferpicklist_past3months ORDER BY month_year ASC");
while ($r = $res->fetch_assoc()) { $p3m_months[] = $r['month_year']; }

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM transferpicklist_nonmoving");
$stmt->execute();
$nm_outlets = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check for active published picklists with pending items
$activePicklists = array();
$res = $conn->query("
    SELECT p.id, p.picklist_no, p.timestamp,
           COUNT(i.id) as pending_count
    FROM transferpicklist_picklist p
    JOIN transferpicklist_item i ON i.picklist_id = p.id AND i.status = 'pending'
    WHERE p.status = 'published'
    GROUP BY p.id, p.picklist_no, p.timestamp
    ORDER BY p.timestamp DESC
");
if ($res) { while ($r = $res->fetch_assoc()) { $activePicklists[] = $r; } }
?>
<!DOCTYPE html>
<html>
<head>
<title>Generate Pick List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Generate Transfer Pick List</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<?php if ($msg): ?>
<div class="alpro-alert alpro-<?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<?php if ($generated): ?>
<div class="alpro-box">
    <div class="alpro-actions">
        <a href="transferpicklist_view.php?id=<?php echo (int)$generated; ?>" class="alpro-btn alpro-btn-blue">View Pick List</a>
        <a href="index.php" class="alpro-btn alpro-btn-grey">Back to Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="alpro-box">
    <div class="alpro-grid">
        <div class="alpro-field">
            <label class="alpro-muted">Non-Moving Records Available</label>
            <div style="font-size:20px;font-weight:bold;"><?php echo $nonmoving_count; ?></div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">Non-Moving Data As Of</label>
            <div style="font-size:16px;font-weight:bold;">
                <?php echo $nm_sync_date ? date('M Y', strtotime($nm_sync_date)) : '—'; ?>
            </div>
        </div>
        <div class="alpro-field">
            <label class="alpro-muted">3-Month Sales Period</label>
            <div style="font-size:16px;font-weight:bold;">
                <?php if (!empty($p3m_months)): ?>
                    <?php echo date('M Y', strtotime($p3m_months[0] . '-01')); ?>
                    &nbsp;–&nbsp;
                    <?php echo date('M Y', strtotime(end($p3m_months) . '-01')); ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="alpro-alert alpro-warn">
        Algorithm will:<br>
        1. Load Area Managers from staff (status_semasa = Area Manager) and their outlets<br>
        2. Supply: non-moving outlets with closing stock &gt; 0<br>
        3. Demand: outlets where avg 3-month sales &gt; current on-hand qty (all outlets)<br>
        4. Distribute per item per sender:<br>
        &nbsp;&nbsp;&nbsp;Pass 1 — <b>Same Area Manager</b> → same_area<br>
        &nbsp;&nbsp;&nbsp;Pass 2 — <b>Same Region</b> → peninsular / sabah / sarawak<br>
        &nbsp;&nbsp;&nbsp;Pass 3 — <b>HQ items</b>: return to regional warehouse (NSDWHR / SKKWHR / QKCWHR) &nbsp;|&nbsp; <b>Non-HQ items</b>: maintain at outlet<br>
        5. Self-transfer is prevented; quantities reduced in real-time
    </div>

    <?php if (!empty($activePicklists)): ?>
    <div class="alpro-alert alpro-warn" style="border:2px solid #e65100; background:#fff8f0;">
        <b style="color:#e65100;">&#9888; Warning — Active Pick List Has Pending Items</b><br>
        The following published pick list(s) still have pending items. You may still generate a new pick list, but please ensure outlets are aware to avoid duplicate tasks.
        <table style="margin-top:10px; width:100%; border-collapse:collapse; font-size:13px;">
            <tr style="background:#fbe9e7;">
                <th style="padding:6px 10px; text-align:left;">Pick List No</th>
                <th style="padding:6px 10px; text-align:left;">Generated</th>
                <th style="padding:6px 10px; text-align:center;">Pending Items</th>
                <th style="padding:6px 10px; text-align:center;">Action</th>
            </tr>
            <?php foreach ($activePicklists as $ap): ?>
            <tr style="border-bottom:1px solid #f5c6c6;">
                <td style="padding:6px 10px; font-weight:bold;"><?php echo htmlspecialchars($ap['picklist_no']); ?></td>
                <td style="padding:6px 10px; color:#555;"><?php echo date('d M Y, h:i A', strtotime($ap['timestamp'])); ?></td>
                <td style="padding:6px 10px; text-align:center; color:#c62828; font-weight:bold;"><?php echo number_format($ap['pending_count']); ?></td>
                <td style="padding:6px 10px; text-align:center;">
                    <a href="transferpicklist_view.php?id=<?php echo (int)$ap['id']; ?>" class="alpro-btn alpro-btn-blue" style="font-size:12px; padding:3px 10px;">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <form method="post" id="generateForm">
    <div class="alpro-actions">
        <input class="alpro-btn alpro-btn-blue" type="submit" name="generate" id="generateBtn" value="Generate Pick List">
        <a href="index.php" class="alpro-btn alpro-btn-grey" id="cancelBtn">Cancel</a>
    </div>
    </form>
    <?php else: ?>
    <form method="post" id="generateForm">
        <div class="alpro-actions">
            <input class="alpro-btn alpro-btn-blue" type="submit" name="generate" id="generateBtn" value="Generate Pick List">
            <a href="index.php" class="alpro-btn alpro-btn-grey" id="cancelBtn">Cancel</a>
        </div>
    </form>
    <?php endif; ?>

    <div id="loadingOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:12px; padding:30px 36px; text-align:center; box-shadow:0 4px 24px rgba(0,0,0,0.25); width:560px; max-width:95vw;">
            <div style="margin-bottom:14px;">
                <svg width="44" height="44" viewBox="0 0 56 56" xmlns="http://www.w3.org/2000/svg" style="animation:spin 1s linear infinite; display:block; margin:0 auto;">
                    <circle cx="28" cy="28" r="22" fill="none" stroke="#e0e0e0" stroke-width="6"/>
                    <path d="M28 6 a22 22 0 0 1 22 22" fill="none" stroke="#1565c0" stroke-width="6" stroke-linecap="round"/>
                </svg>
            </div>
            <div style="font-size:17px; font-weight:600; color:#1565c0; margin-bottom:4px;">Generating Pick List...</div>
            <div style="font-size:12px; color:#888; margin-bottom:14px;">This may take several minutes. You can close this tab — generation will continue in the background.</div>
            <div id="genLogBox" style="text-align:left; background:#1e1e1e; color:#d4d4d4; font-family:monospace; font-size:11px; line-height:1.6; border-radius:6px; padding:12px 14px; height:180px; overflow-y:auto; white-space:pre-wrap;"></div>
        </div>
    </div>

    <style>
        @keyframes spin { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }
    </style>

    <script>
        var _logPoller = null;

        function startLogPolling() {
            var box = document.getElementById('genLogBox');
            _logPoller = setInterval(function() {
                var lx = new XMLHttpRequest();
                lx.open('GET', 'transferpicklist_generate.php?genlog=1', true);
                lx.onload = function() {
                    if (lx.status === 200 && box) {
                        box.textContent = lx.responseText;
                        box.scrollTop   = box.scrollHeight;
                    }
                };
                lx.send();
            }, 2000);
        }

        function stopLogPolling() {
            if (_logPoller) { clearInterval(_logPoller); _logPoller = null; }
        }

        document.getElementById('generateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var overlay = document.getElementById('loadingOverlay');
            var btn     = document.getElementById('generateBtn');
            var cancel  = document.getElementById('cancelBtn');
            var box     = document.getElementById('genLogBox');
            overlay.style.display      = 'flex';
            btn.disabled               = true;
            cancel.style.pointerEvents = 'none';
            cancel.style.opacity       = '0.5';
            if (box) { box.textContent = 'Starting...'; }

            startLogPolling();

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'transferpicklist_generate.php?ajax=1', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                stopLogPolling();
                // Do one final log read before redirect
                var lx = new XMLHttpRequest();
                lx.open('GET', 'transferpicklist_generate.php?genlog=1', true);
                lx.onload = function() {
                    if (lx.status === 200 && box) { box.textContent = lx.responseText; box.scrollTop = box.scrollHeight; }
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            if (box) { box.textContent += '\nRedirecting...'; }
                            setTimeout(function() { window.location = 'transferpicklist_view.php?id=' + data.id; }, 800);
                        } else {
                            overlay.style.display = 'none';
                            btn.disabled = false;
                            cancel.style.pointerEvents = '';
                            cancel.style.opacity = '';
                            alert(data.msg || 'Error generating pick list. Please try again.');
                        }
                    } catch(ex) {
                        // Response wasn't valid JSON — most likely the request took too
                        // long and the server/session returned HTML (login page, timeout
                        // error page, etc.) instead of JSON. Reload so the user sees the
                        // real current state (e.g. login screen, or the picklist that may
                        // have actually finished generating in the background) instead of
                        // a confusing generic error.
                        if (/<html|<!doctype/i.test(xhr.responseText || '')) {
                            window.location.reload();
                            return;
                        }
                        overlay.style.display = 'none';
                        btn.disabled = false;
                        cancel.style.pointerEvents = '';
                        cancel.style.opacity = '';
                        alert('Unexpected error. Please try again.');
                    }
                };
                lx.send();
            };
            xhr.onerror = function() {
                stopLogPolling();
                overlay.style.display = 'none';
                btn.disabled = false;
                cancel.style.pointerEvents = '';
                cancel.style.opacity = '';
                alert('Network error. Please try again.');
            };
            xhr.send('generate=1');
        });
    </script>
</div>
<?php endif; ?>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
