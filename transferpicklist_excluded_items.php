<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) { die("Database connection not available."); }
if ((int)$transferpicklist_autho < 3) { redirect('/odb/permission.php'); }
require_once('../minmax/minmax_campaign_sync.php');

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

    if ($action === 'add') {
        $raw   = isset($_POST['item_codes']) ? trim($_POST['item_codes']) : '';
        $note  = isset($_POST['note'])       ? trim($_POST['note'])       : '';
        $codes = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $added = 0; $skip = 0;
        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '') { continue; }
            $ec   = mysqli_real_escape_string($conn, $code);
            $en   = mysqli_real_escape_string($conn, $note);
            mysqli_query($conn, "INSERT IGNORE INTO transferpicklist_excluded_items (item_code, note) VALUES ('$ec', '$en')");
            if (mysqli_affected_rows($conn) > 0) {
                $added++;
                mmSyncCampaignAdd($conn, 'ALL', $code, MM_SYNC_TAG_GLOBAL);
            } else {
                $skip++;
            }
        }
        // Delete existing records from slowmoving, nonmoving and itemgrade
        if ($added > 0) {
            $inList = "'" . implode("','", array_map(function($c) use ($conn) { return mysqli_real_escape_string($conn, $c); }, $codes)) . "'";
            mysqli_query($conn, "DELETE FROM transferpicklist_slowmoving  WHERE item_code IN ($inList)");
            mysqli_query($conn, "DELETE FROM transferpicklist_nonmoving   WHERE item_code IN ($inList)");
            mysqli_query($conn, "DELETE FROM transferpicklist_itemgrade   WHERE item_code IN ($inList)");
        }
        $msg = "<span style='color:green;'>Added $added item(s). Skipped $skip (already excluded). Existing records removed from slow-moving, non-moving and item grade tables.</span>";

    } elseif ($action === 'download_template') {
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
                $added = 0; $skip = 0;
                $en  = mysqli_real_escape_string($conn, $note);
                foreach ($codes as $code) {
                    $ec = mysqli_real_escape_string($conn, $code);
                    mysqli_query($conn, "INSERT IGNORE INTO transferpicklist_excluded_items (item_code, note) VALUES ('$ec', '$en')");
                    if (mysqli_affected_rows($conn) > 0) {
                        $added++;
                        mmSyncCampaignAdd($conn, 'ALL', $code, MM_SYNC_TAG_GLOBAL);
                    } else {
                        $skip++;
                    }
                }
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
            if ($itemRow) { mmSyncCampaignRemove($conn, 'ALL', $itemRow['item_code']); }
            $msg = "<span style='color:#c62828;'>Item removed from exclusion list. It will be re-synced on next sync run.</span>";
        }

    } elseif ($action === 'delete_all') {
        mysqli_query($conn, "TRUNCATE TABLE transferpicklist_excluded_items");
        mmSyncCampaignRemoveAllBySource($conn, MM_SYNC_TAG_GLOBAL);
        $msg = "<span style='color:#c62828;'>All exclusions cleared.</span>";
    }
}

// ── Load list ─────────────────────────────────────────────────
$rows = array();
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$where  = $search !== '' ? "WHERE item_code LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' OR note LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'" : '';
$res = mysqli_query($conn, "SELECT id, item_code, note, created_at FROM transferpicklist_excluded_items $where ORDER BY created_at DESC");
while ($r = $res->fetch_assoc()) { $rows[] = $r; }

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

<?php if ($msg): ?>
<div style="padding:8px 12px; background:#f5f5f5; border-left:3px solid #1976d2; margin-bottom:14px; font-size:13px;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Add Form -->
<fieldset style="margin-bottom:16px;">
    <legend style="font-weight:bold; font-size:13px;">Add Items to Exclusion List</legend>
    <form method="post" action="">
        <input type="hidden" name="action" value="add">
        <div style="display:flex; gap:8px; align-items:stretch;">
            <div style="flex:3;">
                <label style="font-size:11px; color:#888;">Item Code(s) — paste multiple codes separated by comma, space, or newline</label><br>
                <textarea name="item_codes" rows="4" class="alpro-input" style="width:100%; height:90px; font-family:monospace; font-size:12px; box-sizing:border-box; resize:vertical;" placeholder="e.g. 10368, 10449, 100051101"></textarea>
            </div>
            <div style="flex:2;">
                <label style="font-size:11px; color:#888;">Note (optional)</label><br>
                <textarea name="note" rows="4" class="alpro-input" style="width:100%; height:90px; font-size:13px; box-sizing:border-box; resize:vertical;" placeholder="e.g. Discontinued, Not for transfer"></textarea>
            </div>
            <div style="flex:0 0 auto; display:flex; align-items:center; padding-top:18px;">
                <button type="submit" class="alpro-btn alpro-btn-blue" style="height:90px; padding:0 20px; white-space:nowrap;">Add &amp;<br>Remove</button>
            </div>
        </div>
        <div style="font-size:11px; color:#888; margin-top:4px;">Adding items will also immediately <b>delete</b> them from the slow-moving and non-moving tables.</div>
    </form>
</fieldset>

<!-- Import Excel/CSV -->
<fieldset style="margin-bottom:16px;">
    <legend style="font-weight:bold; font-size:13px;">&#8679; Import from Excel (.xlsx) or CSV</legend>
    <p style="font-size:12px; color:#555; margin:0 0 8px;">Column A = Item Code. First row header will be skipped. Imported items are immediately removed from sync tables.</p>
    <form method="post" style="display:inline; margin-bottom:10px;">
        <input type="hidden" name="action" value="download_template">
        <button type="submit" class="alpro-btn alpro-btn-grey" style="font-size:12px;">&#8659; Download Template</button>
    </form><br><br>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_file">
        <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
            <div style="flex:0 0 280px;">
                <label style="font-size:11px; color:#888;">File (.xlsx or .csv)</label><br>
                <input type="file" name="import_file" accept=".xlsx,.csv" class="alpro-input" style="width:100%;">
            </div>
            <div style="flex:1; min-width:160px;">
                <label style="font-size:11px; color:#888;">Note for all (optional)</label><br>
                <input type="text" name="import_note" class="alpro-input" style="width:100%;" placeholder="e.g. July exclusion list">
            </div>
            <div>
                <label style="font-size:11px; color:#888; display:block;">&nbsp;</label>
                <label style="font-size:12px; display:flex; align-items:center; gap:4px; height:32px;">
                    <input type="checkbox" name="skip_header" value="1" checked> Skip header row
                </label>
            </div>
            <div>
                <button type="submit" class="alpro-btn alpro-btn-blue">Import</button>
            </div>
        </div>
    </form>
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
        <th>Item Code</th>
        <th>Note</th>
        <th width="140">Excluded At</th>
        <th width="60" class="alpro-center">Action</th>
    </tr>
    <?php if (empty($rows)): ?>
    <tr><td colspan="5" class="alpro-center alpro-muted">No excluded items.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
    <tr>
        <td class="alpro-center alpro-muted" style="font-size:11px;"><?php echo $i + 1; ?></td>
        <td><b style="font-family:monospace;"><?php echo htmlspecialchars($r['item_code']); ?></b></td>
        <td style="font-size:12px; color:#555;"><?php echo htmlspecialchars($r['note']); ?></td>
        <td style="font-size:11px; color:#888;"><?php echo htmlspecialchars(substr($r['created_at'], 0, 16)); ?></td>
        <td class="alpro-center">
            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Remove this item from exclusion list?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" class="alpro-btn alpro-btn-orange" style="font-size:10px;padding:2px 6px;">Remove</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</fieldset>

<div style="margin-top:6px; font-size:11px; color:#aaa; display:flex; justify-content:space-between; align-items:center;">
    <span>Showing <?php echo count($rows); ?> excluded item(s).</span>
    <?php if (!empty($rows)): ?>
    <form method="post" action="" onsubmit="return confirm('Clear ALL exclusions? Items will be re-synced on next run.');">
        <input type="hidden" name="action" value="delete_all">
        <button type="submit" class="alpro-btn alpro-btn-orange" style="font-size:11px;padding:2px 8px;">Clear All</button>
    </form>
    <?php endif; ?>
</div>

</div>

<?php
$connect = 0;
include('../common/index_adv.php');
?>
</body>
</html>
