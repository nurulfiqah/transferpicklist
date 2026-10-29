<?php
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho < 3) { redirect('/odb/permission.php'); }

$msg     = '';
$msgType = '';
$preview = array();

// ── All warehouse acmm_ids in order ──────────────────────────
$ALL_ACMM = array(1004, 1001, 1016, 1013, 1005, 1002);
$ACMM_REGION = array(
    1004 => 'peninsular', 1001 => 'peninsular',
    1016 => 'sabah',      1013 => 'sabah',
    1005 => 'sarawak',    1002 => 'sarawak',
);
$REGION_ACMM = array(
    'peninsular' => array(1001, 1004),
    'sabah'      => array(1016, 1013),
    'sarawak'    => array(1005, 1002),
);

// outlet.id per acmm_id + outlet.code for display
$whOutletId   = array(); // acmm_id => outlet.id
$whOutletCode = array(); // acmm_id => outlet.code
$res = $conn->query("SELECT id, acmm_id, code FROM outlet WHERE acmm_id IN (1001,1004,1016,1013,1005,1002) AND recycle=0");
while ($r = $res->fetch_assoc()) {
    $whOutletId[(int)$r['acmm_id']]   = (int)$r['id'];
    $whOutletCode[(int)$r['acmm_id']] = $r['code'];
}

// ── Load existing warehouse picklists ─────────────────────────
$picklists = array();
$res = $conn->query("
    SELECT DISTINCT p.id, p.picklist_no, p.status, p.timestamp
    FROM transferpicklist_picklist p
    JOIN transferpicklist_item i ON i.picklist_id = p.id AND i.priority_type = 'warehouse'
    WHERE p.status != 'completed'
    ORDER BY p.timestamp DESC
");
while ($r = $res->fetch_assoc()) { $picklists[] = $r; }

// ── Download CSV template ─────────────────────────────────────
if (isset($_GET['template'])) {
    $tplId = (int)(isset($_GET['picklist_id']) ? $_GET['picklist_id'] : 0);
    ob_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="warehouse_import_template.csv"');
    $out = fopen('php://output', 'w');

    // Header row: Item Code | 1004 (NSDWHR) | 1001 (NSDWH1) | ...
    $hdrs = array('Item Code');
    foreach ($ALL_ACMM as $acmm) {
        $code = isset($whOutletCode[$acmm]) ? $whOutletCode[$acmm] : '';
        $hdrs[] = $acmm . ($code ? ' (' . $code . ')' : '');
    }
    fputcsv($out, $hdrs);

    if ($tplId > 0) {
        // Pre-fill with actual items — qty per warehouse
        $tplRes = $conn->query("
            SELECT i.item_code,
                SUM(CASE WHEN wh.acmm_id = 1004 THEN i.qty_transfer ELSE 0 END) AS q1004,
                SUM(CASE WHEN wh.acmm_id = 1001 THEN i.qty_transfer ELSE 0 END) AS q1001,
                SUM(CASE WHEN wh.acmm_id = 1016 THEN i.qty_transfer ELSE 0 END) AS q1016,
                SUM(CASE WHEN wh.acmm_id = 1013 THEN i.qty_transfer ELSE 0 END) AS q1013,
                SUM(CASE WHEN wh.acmm_id = 1005 THEN i.qty_transfer ELSE 0 END) AS q1005,
                SUM(CASE WHEN wh.acmm_id = 1002 THEN i.qty_transfer ELSE 0 END) AS q1002
            FROM transferpicklist_item i
            LEFT JOIN outlet wh ON wh.id = i.to_outlet_id
            WHERE i.picklist_id = $tplId AND i.priority_type = 'warehouse' AND i.recycle = 0
            GROUP BY i.item_code ORDER BY i.item_code
        ");
        while ($tr = $tplRes->fetch_assoc()) {
            fputcsv($out, array(
                $tr['item_code'],
                (int)$tr['q1004'], (int)$tr['q1001'],
                (int)$tr['q1016'], (int)$tr['q1013'],
                (int)$tr['q1005'], (int)$tr['q1002'],
            ));
        }
    } else {
        fputcsv($out, array('12345', '10', '0', '5', '0', '0', '0'));
        fputcsv($out, array('67890', '0', '0', '0', '3', '8', '0'));
    }
    fclose($out);
    exit;
}

// ── Handle import POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $picklistId = (int)(isset($_POST['picklist_id']) ? $_POST['picklist_id'] : 0);
    $action     = isset($_POST['action']) ? trim($_POST['action']) : 'preview';

    if ($picklistId <= 0) {
        $msg = 'Please select a picklist.'; $msgType = 'error';
    } elseif (!isset($_FILES['csvfile']) || $_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Please upload a CSV file.'; $msgType = 'error';
    } else {
        $timestamp = date('Y-m-d H:i:s');
        $content   = file_get_contents($_FILES['csvfile']['tmp_name']);
        $content   = str_replace(array("\r\n", "\r"), "\n", $content);
        $lines     = explode("\n", trim($content));
        $rawHdr    = str_getcsv(array_shift($lines));

        // Detect format — either:
        // WIDE: headers contain acmm_ids (1001, 1004...) → each column = one warehouse
        // TALL: headers contain "return to" column → each row has item_code + target warehouse acmm_id
        $colItem    = null;
        $colReturnTo = null; // TALL format: "Return to" column index
        $colAcmm    = array(); // WIDE format: acmm_id => column index
        // Strip BOM if present
        $rawHdr[0]  = ltrim($rawHdr[0], "\xEF\xBB\xBF\xFF\xFE");
        foreach ($rawHdr as $i => $col) {
            $cl = strtolower(trim($col));
            if ($colItem === null && (strpos($cl, 'item') !== false || strpos($cl, 'code') !== false)) { $colItem = $i; }
            if ($colReturnTo === null && (strpos($cl, 'return') !== false || $cl === 'warehouse' || $cl === 'wh')) { $colReturnTo = $i; }
            foreach ($ALL_ACMM as $acmm) {
                if (!isset($colAcmm[$acmm]) && strpos($col, (string)$acmm) !== false) { $colAcmm[$acmm] = $i; }
            }
        }
        $isTallFormat = ($colReturnTo !== null && empty($colAcmm));

        if ($colItem === null) {
            $msg = 'Cannot detect Item Code column. Headers: ' . implode(', ', $rawHdr); $msgType = 'error';
        } elseif (!$isTallFormat && empty($colAcmm)) {
            $msg = 'Cannot detect warehouse columns. Headers: ' . implode(', ', $rawHdr); $msgType = 'error';
        } else {
            $rows = array();
            $skipped = 0;
            foreach ($lines as $line) {
                if (trim($line) === '') { continue; }
                $row      = str_getcsv($line);
                while (count($row) < count($rawHdr)) { $row[] = ''; }
                $itemCode = trim($row[$colItem]);
                if ($itemCode === '') { continue; }

                if ($isTallFormat) {
                    // TALL format: each row = one item + one target warehouse acmm_id
                    $acmm = (int)trim($row[$colReturnTo]);
                    if (!in_array($acmm, $ALL_ACMM)) { $skipped++; continue; }
                    $rows[] = array('item_code' => $itemCode, 'wh_qty' => array($acmm => 1));
                } else {
                    // WIDE format: each row = item with qty per warehouse column
                    $whQty = array();
                    $total = 0;
                    foreach ($colAcmm as $acmm => $ci) {
                        $qty = (float)str_replace(',', '', trim($row[$ci]));
                        $whQty[$acmm] = $qty;
                        $total += $qty;
                    }
                    if ($total <= 0) { $skipped++; continue; }
                    $rows[] = array('item_code' => $itemCode, 'wh_qty' => $whQty);
                }
            }

            if ($action === 'preview') {
                $preview = $rows;
                $msg     = count($rows) . ' row(s) ready. Review below then click Confirm Import.';
                $msgType = 'info';
            } else {
                $imported = 0; $updated = 0;
                foreach ($rows as $r) {
                    $ric = $conn->real_escape_string($r['item_code']);
                    foreach ($r['wh_qty'] as $acmm => $qty) {
                        if ($qty <= 0) { continue; }
                        $newWhId  = isset($whOutletId[$acmm]) ? $whOutletId[$acmm] : 0;
                        if ($newWhId <= 0) { continue; }
                        $region   = $ACMM_REGION[$acmm];
                        // All warehouse outlet IDs for this region
                        $rgnIds   = array(0);
                        foreach ($REGION_ACMM[$region] as $ra) {
                            if (isset($whOutletId[$ra])) { $rgnIds[] = $whOutletId[$ra]; }
                        }
                        $rgnList  = implode(',', $rgnIds);

                        // Check if existing rows for this item + region in this picklist
                        $existRes = $conn->query("
                            SELECT COUNT(*) as cnt FROM transferpicklist_item
                            WHERE picklist_id=$picklistId AND item_code='$ric'
                              AND to_outlet_id IN ($rgnList) AND priority_type='warehouse' AND recycle=0
                        ");
                        $existCnt = (int)$existRes->fetch_assoc()['cnt'];

                        if ($existCnt > 0) {
                            // UPDATE: change warehouse for existing rows
                            $conn->query("
                                UPDATE transferpicklist_item
                                SET to_outlet_id = $newWhId, timestamp = '$timestamp'
                                WHERE picklist_id=$picklistId AND item_code='$ric'
                                  AND to_outlet_id IN ($rgnList) AND priority_type='warehouse' AND recycle=0
                            ");
                            $updated++;
                        } else {
                            // INSERT: new row (from = warehouse itself, no outlet info)
                            $conn->query("
                                INSERT INTO transferpicklist_item
                                    (picklist_id, from_outlet_id, to_outlet_id, item_code, qty_transfer, priority_type, status, timestamp)
                                VALUES ($picklistId, $newWhId, $newWhId, '$ric', $qty, 'warehouse', 'pending', '$timestamp')
                            ");
                            $imported++;
                        }
                    }
                }
                $msg     = "Done! $updated item(s) warehouse changed. $imported new item(s) inserted. $skipped row(s) skipped.";
                $msgType = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Warehouse Import – Transfer Pick List</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>
<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Warehouse Returns – CSV Import</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

<?php if ($msg): ?>
<div style="padding:10px 14px; border-radius:5px; margin-bottom:14px; font-size:13px;
    background:<?php echo $msgType==='success'?'#e8f5e9':($msgType==='error'?'#ffebee':'#e3f2fd'); ?>;
    color:<?php echo $msgType==='success'?'#2e7d32':($msgType==='error'?'#c62828':'#1565c0'); ?>;
    border-left:4px solid <?php echo $msgType==='success'?'#388e3c':($msgType==='error'?'#e53935':'#1976d2'); ?>;">
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- Instructions -->
<fieldset style="margin-bottom:16px;">
<legend style="font-weight:bold; font-size:13px;">CSV Format — Per Warehouse Column</legend>
<p style="font-size:13px; color:#555; margin:8px 0;">
    Each column = one specific warehouse. To <b>change warehouse</b>: set old column to 0, put qty in new column.
    &nbsp;&nbsp;
    <a id="tpl-download-btn" href="transferpicklist_warehouse_import.php?template=1"
       class="alpro-btn alpro-btn-green" style="font-size:12px; padding:3px 12px;">&#8659; Download Template (.csv)</a>
    <span style="font-size:11px; color:#888; margin-left:6px;">Select a picklist first to pre-fill with existing items.</span>
</p>
<table class="myTable alpro-table" style="width:auto;margin-top:8px;" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th>Item Code</th>
        <?php foreach ($ALL_ACMM as $acmm): ?>
        <th><?php echo $acmm; ?> (<?php echo isset($whOutletCode[$acmm]) ? htmlspecialchars($whOutletCode[$acmm]) : '?'; ?>)</th>
        <?php endforeach; ?>
        <th style="color:#388e3c;">→ Action</th>
    </tr>
    <tr>
        <td>12345</td><td>10</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td>
        <td style="color:#888; font-size:11px;">Keep at 1004 (no change)</td>
    </tr>
    <tr style="background:#fff9e6;">
        <td>12345</td><td style="color:#c62828;text-decoration:line-through;">10</td><td style="color:#388e3c;font-weight:bold;">10</td><td>0</td><td>0</td><td>0</td><td>0</td>
        <td style="color:#1976d2; font-size:11px; font-weight:bold;">Change 1004 → 1001</td>
    </tr>
    <tr>
        <td>67890</td><td>0</td><td>0</td><td>5</td><td>0</td><td>3</td><td>0</td>
        <td style="color:#888; font-size:11px;">New item (insert)</td>
    </tr>
</table>
</fieldset>

<!-- Import form -->
<fieldset style="margin-bottom:16px;">
<legend style="font-weight:bold; font-size:13px;">Import</legend>
<form method="post" enctype="multipart/form-data">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="padding:6px; width:40%;">
                <label style="font-size:11px; color:#888;">Target Picklist</label><br>
                <select name="picklist_id" id="picklist-sel" class="alpro-select" style="width:100%;" required>
                    <option value="">-- Select Picklist --</option>
                    <?php foreach ($picklists as $pl): ?>
                    <option value="<?php echo (int)$pl['id']; ?>"
                        <?php echo (isset($_POST['picklist_id']) && (int)$_POST['picklist_id'] === (int)$pl['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($pl['picklist_no']); ?>
                        (<?php echo date('d M Y', strtotime($pl['timestamp'])); ?>)
                        – <?php echo ucfirst($pl['status']); ?>
                    </option>
                    <?php endforeach; ?>
                    <?php if (empty($picklists)): ?><option value="" disabled>No warehouse picklists found</option><?php endif; ?>
                </select>
            </td>
            <td style="padding:6px; width:40%;">
                <label style="font-size:11px; color:#888;">CSV File</label><br>
                <input type="file" name="csvfile" accept=".csv,.txt" class="alpro-input" style="width:100%;" required>
            </td>
            <td style="padding:6px; vertical-align:bottom;">
                <button type="submit" name="import" value="1" class="alpro-btn alpro-btn-blue"
                        onclick="document.querySelector('[name=action]').value='preview';">Preview</button>
                <input type="hidden" name="action" value="preview">
            </td>
        </tr>
    </table>

    <?php if (!empty($preview)): ?>
    <hr style="margin:14px 0;">
    <p style="font-size:13px; font-weight:bold; color:#1976d2; margin-bottom:8px;">
        Preview — <?php echo count($preview); ?> row(s)
    </p>
    <div style="max-height:320px; overflow-y:auto; border:1px solid #ddd; border-radius:4px;">
    <table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
        <tr>
            <th width="30">#</th>
            <th>Item Code</th>
            <?php foreach ($ALL_ACMM as $acmm): ?>
            <th width="80" class="alpro-center"><?php echo $acmm; ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach ($preview as $i => $r): ?>
        <tr>
            <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i+1; ?></td>
            <td class="alpro-mono"><?php echo htmlspecialchars($r['item_code']); ?></td>
            <?php foreach ($ALL_ACMM as $acmm): $qty = isset($r['wh_qty'][$acmm]) ? $r['wh_qty'][$acmm] : 0; ?>
            <td class="alpro-center">
                <?php if ($qty > 0): ?>
                <b style="color:#1976d2;"><?php echo number_format($qty, 0); ?></b>
                <?php else: ?>
                <span style="color:#ccc;">—</span>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <div style="margin-top:10px;">
        <button type="submit" name="import" value="1" class="alpro-btn alpro-btn-green"
                onclick="document.querySelector('[name=action]').value='confirm'; return confirm('Import <?php echo count($preview); ?> item(s)?');">
            &#10003; Confirm Import
        </button>
        <span style="font-size:11px; color:#888; margin-left:8px;">
            Existing items in same region will have their warehouse updated. New items will be inserted.
        </span>
    </div>
    <?php endif; ?>
</form>
</fieldset>

<?php if ($msgType === 'success'): ?>
<div style="margin-top:10px;">
    <a href="transferpicklist_warehouse.php" class="alpro-btn alpro-btn-blue">View Warehouse Returns</a>
</div>
<?php endif; ?>

</div>

<script>
(function() {
    var plSel  = document.getElementById('picklist-sel');
    var tplBtn = document.getElementById('tpl-download-btn');
    if (!plSel || !tplBtn) { return; }
    function updateLink() {
        var pid = plSel.value;
        tplBtn.href = 'transferpicklist_warehouse_import.php?template=1' + (pid ? '&picklist_id=' + pid : '');
        tplBtn.textContent = (pid ? '↓ Download Template with Items (.csv)' : '↓ Download Blank Template (.csv)');
    }
    plSel.addEventListener('change', updateLink);
    updateLink();
})();
</script>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
