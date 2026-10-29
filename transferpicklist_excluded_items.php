<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho !== 1) { redirect('/odb/permission.php'); }
require_once('../minmax/minmax_campaign_sync.php');
require_once(__DIR__ . '/transferpicklist_exclusion_log.php');
tplExLogEnsure($conn);

// "Excluded At — by who": created_by column, added automatically on first load if missing
// (rows imported before this column existed simply show no name).
$_exColRes = $conn->query("SHOW COLUMNS FROM transferpicklist_excluded_items LIKE 'created_by'");
if ($_exColRes && $_exColRes->num_rows === 0) {
    $conn->query("ALTER TABLE transferpicklist_excluded_items ADD COLUMN created_by VARCHAR(100) NULL AFTER note");
}
$_exBy = (isset($nama_staff) && $nama_staff !== '') ? $nama_staff : '';

// ── Read .xlsx without external library ───────────────────────
function readXlsxItemCodes($filePath) {
    if (!class_exists('ZipArchive')) { return array(); }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) { return array(); }
    $sharedStrings = array();
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $t = '';
                if (isset($si->t)) { $t = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) { $t .= (string)$r->t; } } }
                $sharedStrings[] = $t;
            }
        }
    }
    $rows = array();
    $shXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$shXml) { return array(); }
    $sh = simplexml_load_string($shXml);
    if (!$sh || !isset($sh->sheetData->row)) { return array(); }
    foreach ($sh->sheetData->row as $row) {
        $first = null;
        foreach ($row->c as $cell) {
            $type = isset($cell['t']) ? (string)$cell['t'] : '';
            $val  = isset($cell->v)   ? (string)$cell->v   : '';
            if ($type === 's') { $val = isset($sharedStrings[(int)$val]) ? $sharedStrings[(int)$val] : ''; }
            $first = trim($val);
            break; // only need column A
        }
        if ($first !== null) { $rows[] = $first; }
    }
    return $rows;
}

// ── Handle POST actions ───────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // (Manual "Add Items" box removed — items are added only via Import from Excel/CSV.)
    if ($action === 'download_template') {
        ob_clean();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="excluded_items_template.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('Item Code', 'Note'));
        fputcsv($out, array('100368', 'Example'));
        fputcsv($out, array('100449', ''));
        fclose($out);
        exit;

    } elseif ($action === 'import_file') {
        $note       = trim(isset($_POST['import_note']) ? $_POST['import_note'] : '');
        $skipHeader = isset($_POST['skip_header']) && $_POST['skip_header'] === '1';

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== 0) {
            $msg = "<span style='color:#c62828;'>No file uploaded or upload error.</span>";
        } else {
            $tmpPath  = $_FILES['import_file']['tmp_name'];
            $ext      = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            $codes    = array();

            if ($ext === 'xlsx') {
                $rawRows = readXlsxItemCodes($tmpPath);
                foreach ($rawRows as $i => $val) {
                    if ($skipHeader && $i === 0) { continue; }
                    $c = trim($val);
                    if ($c !== '') { $codes[] = $c; }
                }
            } elseif ($ext === 'csv') {
                $handle = fopen($tmpPath, 'r');
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }
                $i = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    if ($skipHeader && $i === 0) { $i++; continue; }
                    $c = trim(isset($row[0]) ? $row[0] : '');
                    if ($c !== '') { $codes[] = $c; }
                    $i++;
                }
                fclose($handle);
            } else {
                $msg = "<span style='color:#c62828;'>Unsupported file. Use .xlsx or .csv only.</span>";
            }

            if (!empty($codes)) {
                $added = 0; $skip = 0; $addedCodes = array();
                $en  = mysqli_real_escape_string($conn, $note);
                $eby = mysqli_real_escape_string($conn, $_exBy);
                foreach ($codes as $code) {
                    $ec = mysqli_real_escape_string($conn, $code);
                    mysqli_query($conn, "INSERT IGNORE INTO transferpicklist_excluded_items (item_code, note, created_by) VALUES ('$ec', '$en', '$eby')");
                    if (mysqli_affected_rows($conn) > 0) {
                        $added++;
                        $addedCodes[] = $code;
                        mmSyncCampaignAdd($conn, 'ALL', $code, MM_SYNC_TAG_GLOBAL);
                    } else {
                        $skip++;
                    }
                }
                tplExLog($conn, 'global', 'insert', $addedCodes, $note);
                if ($added > 0) {
                    $inList = "'" . implode("','", array_map(function($c) use ($conn) { return mysqli_real_escape_string($conn, $c); }, $codes)) . "'";
                    mysqli_query($conn, "DELETE FROM transferpicklist_slowmoving WHERE item_code IN ($inList)");
                    mysqli_query($conn, "DELETE FROM transferpicklist_nonmoving  WHERE item_code IN ($inList)");
                    mysqli_query($conn, "DELETE FROM transferpicklist_itemgrade  WHERE item_code IN ($inList)");
                }
                $msg = "<span style='color:green;'>Import done: Added <b>$added</b> item(s). Removed from sync tables.</span>";
                if ($skip > 0) { $msg .= " <span style='color:#888;'>$skip already existed.</span>"; }
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
        if ($id > 0) {
            $itemRes  = mysqli_query($conn, "SELECT item_code FROM transferpicklist_excluded_items WHERE id=$id");
            $itemRow  = $itemRes ? mysqli_fetch_assoc($itemRes) : null;
            mysqli_query($conn, "DELETE FROM transferpicklist_excluded_items WHERE id=$id");
            if ($itemRow) {
                mmSyncCampaignRemove($conn, 'ALL', $itemRow['item_code']);
                tplExLog($conn, 'global', 'delete', array($itemRow['item_code']));
            }
            $msg = "<span style='color:#c62828;'>Item removed from exclusion list. It will be re-synced on next sync run.</span>";
        }

    // (Clear All removed — items are removed one by one with the bin.)
    }
}

// ── Load list ─────────────────────────────────────────────────
$rows = array();
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$where  = $search !== '' ? "WHERE item_code LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR note LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'" : '';
$res = mysqli_query($conn, "SELECT id, item_code, note, created_by, created_at FROM transferpicklist_excluded_items $where ORDER BY created_at DESC");
while ($res && $r = $res->fetch_assoc()) { $rows[] = $r; }

// Item descriptions for the listed codes (one lookup)
$exItemNames = array();
if (!empty($rows)) {
    $exIn = implode(',', array_map(function($r) use ($conn) { return "'" . mysqli_real_escape_string($conn, $r['item_code']) . "'"; }, $rows));
    $nRes = mysqli_query($conn, "SELECT item_code, name FROM simple WHERE item_code IN ($exIn)");
    while ($nRes && $n = $nRes->fetch_assoc()) { $exItemNames[$n['item_code']] = $n['name']; }
}

// ============================================================
// EXPORT XLS — respects the Search filter above, every matching row
// ============================================================
if (isset($_GET['export'])) {
    if (ob_get_level() > 0) { ob_end_clean(); }
    $fname = 'transferpicklist_excluded_items_' . date('Ymd_His') . '.xls';
    $xls  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?mso-application progid=\"Excel.Sheet\"?>\n";
    $xls .= "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    $xls .= "<Worksheet ss:Name=\"Excluded Items\"><Table>\n<Row>";
    foreach (array('#', 'Item Code', 'Note', 'Excluded At') as $c) {
        $xls .= "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($c) . "</Data></Cell>";
    }
    $xls .= "</Row>\n";
    $i = 1;
    foreach ($rows as $er) {
        $xls .= "<Row>"
            . "<Cell><Data ss:Type=\"Number\">" . $i++ . "</Data></Cell>"
            . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['item_code']) . "</Data></Cell>"
            . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['note']) . "</Data></Cell>"
            . "<Cell><Data ss:Type=\"String\">" . htmlspecialchars($er['created_at']) . "</Data></Cell>"
            . "</Row>\n";
    }
    $xls .= "</Table></Worksheet></Workbook>";
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($xls));
    echo $xls;
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Excluded Items</title>
<link rel="stylesheet" href="../common/css/layout.css">
<link rel="stylesheet" href="../common/css/page.css">
<link rel="stylesheet" href="../common/css/alpro-core.css">
<link rel="stylesheet" href="../common/css/alpro-components.css">
<link rel="stylesheet" href="../common/css/alpro-utils.css">
</head>
<body>

<div class="header">
    <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
    <h1 class="headerH1"><img src="../common/img/library2.png"> Excluded Items</h1>
    <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('transferpicklist_sidebar.php'); ?>

<div class="alpro-box">

<p style="font-size:13px; color:#555; margin-bottom:14px;">
    Items listed here are <b>skipped during pick list generation</b> — the item code will not be transferred <b>from any outlet</b>. They are also removed from the Non Moving and Slow Moving lists.<br>
    To block an item code from <b>one specific outlet only</b>, use <a href="transferpicklist_excluded.php">Excluded Items (Per Outlet)</a>.
</p>

<?php if ($msg): ?>
<div style="padding:8px 12px; background:#f5f5f5; border-left:3px solid #1976d2; margin-bottom:14px; font-size:13px;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Import Excel/CSV (the only way to add items) — same layout as Excluded Items (Per Outlet) -->
<fieldset style="margin-bottom:16px;">
    <legend style="font-weight:bold; font-size:13px;">&#8679; Import from Excel (.xlsx) or CSV</legend>
    <p style="font-size:12px; color:#555; margin:0 0 10px;">
        File columns: <b>A = Item Code</b>. Extra columns are ignored.
        Excel: save as <b>.xlsx</b> &nbsp;|&nbsp; CSV: comma-separated, UTF-8. Imported items are immediately removed from the sync tables.
    </p>
    <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <form method="post" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; flex:1 1 auto; margin:0;">
            <input type="hidden" name="action" value="import_file">
            <div style="flex:0 0 280px;">
                <label style="font-size:11px; color:#888;">File (.xlsx or .csv)</label><br>
                <input type="file" name="import_file" accept=".xlsx,.csv" class="alpro-input" style="width:100%;">
            </div>
            <div style="flex:1; min-width:180px;">
                <label style="font-size:11px; color:#888;">Note for all (optional)</label><br>
                <input type="text" name="import_note" class="alpro-input" style="width:100%;" placeholder="e.g. July exclusion list">
            </div>
            <div style="flex:0 0 auto;">
                <label style="font-size:12px; display:flex; align-items:center; gap:4px; height:32px; white-space:nowrap;">
                    <input type="checkbox" name="skip_header" value="1" checked> Skip header row
                </label>
            </div>
            <div style="flex:0 0 auto;">
                <button type="submit" class="alpro-btn alpro-btn-blue">Import</button>
            </div>
        </form>
        <form method="post" style="margin:0; flex:0 0 auto;">
            <input type="hidden" name="action" value="download_template">
            <button type="submit" class="alpro-btn alpro-btn-grey">&#8659; Download Template</button>
        </form>
    </div>
</fieldset>

<!-- Search + Count -->
<form method="get" action="" style="display:flex; gap:8px; margin-bottom:10px; align-items:flex-end;">
    <div style="flex:1;">
        <label style="font-size:11px; color:#888;">Search</label>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="alpro-input" style="width:100%;" placeholder="Item code or note...">
    </div>
    <div>
        <button type="submit" class="alpro-btn alpro-btn-blue">Search</button>
        <a href="transferpicklist_excluded_items.php" class="alpro-btn alpro-btn-grey">Reset</a>
        <a href="transferpicklist_excluded_items.php?q=<?php echo urlencode($search); ?>&export=1" class="alpro-btn alpro-btn-grey">&#8595; Export to Excel</a>
    </div>
</form>

<!-- Table -->
<fieldset>
<table class="myTable alpro-table" width="100%" cellpadding="4" cellspacing="1" bgcolor="#EFEFEF">
    <tr>
        <th width="30">#</th>
        <th width="140">Item Code</th>
        <th>Item Description</th>
        <th width="200">Excluded At</th>
        <th width="60" class="alpro-center">Action</th>
    </tr>
    <?php if (empty($rows)): ?>
    <tr><td colspan="5" class="alpro-center alpro-muted">No excluded items.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r):
        $exDesc = isset($exItemNames[$r['item_code']]) && $exItemNames[$r['item_code']] !== '' ? $exItemNames[$r['item_code']] : '';
    ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
        <td><b style="font-family:monospace;"><?php echo htmlspecialchars($r['item_code']); ?></b></td>
        <td>
            <?php echo htmlspecialchars($exDesc !== '' ? $exDesc : '—'); ?>
            <?php if (!empty($r['note'])): ?>
            <div style="font-size:11px; color:#888;">Note: <?php echo htmlspecialchars($r['note']); ?></div>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;">
            <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($r['created_at']))); ?>
            <div style="font-size:11px; color:#888;"><?php echo !empty($r['created_by']) ? 'by ' . htmlspecialchars($r['created_by']) : '&nbsp;'; ?></div>
        </td>
        <td class="alpro-center">
            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Remove this item from exclusion list?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" title="Remove" style="background:none; border:none; cursor:pointer; padding:0; font-size:18px; line-height:1; opacity:0.75;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.75">&#128465;</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<div style="margin-top:6px; font-size:11px; color:#aaa; display:flex; justify-content:space-between; align-items:center;">
    <span>Showing <?php echo count($rows); ?> excluded item(s).</span>
</div>

</div>

<?php tplExLogRender($conn, 'global'); ?>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
