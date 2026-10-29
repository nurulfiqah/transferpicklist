<?php
// Activity log for the two exclusion pages:
//   transferpicklist_excluded_items.php  (list_type 'global'     — item codes)
//   transferpicklist_excluded.php        (list_type 'per_outlet' — ITEM × OUTLET pairs)
// Records ONLY items added (import) and removed (single remove / Clear All),
// with who and when. Table: transferpicklist_exclusion_log
// (see database/migrations/2026-10-29_exclusion_log.sql — also created here if missing).
if (defined('TPL_EXCLUSION_LOG_LOADED')) { return; }
define('TPL_EXCLUSION_LOG_LOADED', true);

function tplExLogEnsure($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS transferpicklist_exclusion_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        list_type  VARCHAR(20)  NOT NULL,
        action     VARCHAR(20)  NOT NULL,
        item_count INT          NOT NULL DEFAULT 0,
        items      MEDIUMTEXT   NULL,
        note       VARCHAR(255) NULL,
        by_user    VARCHAR(100) NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_list_time (list_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// $action: 'insert' | 'delete' | 'delete_all'.  $items: array of codes (or "ITEM × OUTLET").
function tplExLog($conn, $listType, $action, $items, $note = '') {
    $items = array_values(array_filter(array_map('strval', (array)$items), 'strlen'));
    if (empty($items) && $action !== 'delete_all') { return; }
    global $nama_staff;
    $by = (isset($nama_staff) && $nama_staff !== '') ? $nama_staff : '';
    $conn->query("INSERT INTO transferpicklist_exclusion_log (list_type, action, item_count, items, note, by_user, created_at) VALUES ('"
        . $conn->real_escape_string($listType) . "', '" . $conn->real_escape_string($action) . "', " . count($items) . ", '"
        . $conn->real_escape_string(implode(', ', $items)) . "', '" . $conn->real_escape_string($note) . "', '"
        . $conn->real_escape_string($by) . "', '" . date('Y-m-d H:i:s') . "')");
}

// Audit-log style box (latest entries first).
function tplExLogRender($conn, $listType, $limit = 50) {
    $unit = $listType === 'per_outlet' ? 'pair' : 'item';
    $res  = $conn->query("SELECT action, item_count, items, note, by_user, created_at FROM transferpicklist_exclusion_log
                          WHERE list_type = '" . $conn->real_escape_string($listType) . "' ORDER BY id DESC LIMIT " . (int)$limit);
    echo '<div class="alpro-box" style="margin-top:14px;">';
    echo '<div style="display:flex; align-items:center; gap:8px; font-size:17px; font-weight:bold; color:#222; padding-bottom:10px; border-bottom:1px solid #e5e7eb; margin-bottom:10px;">'
       . '<span style="color:#1976d2; font-size:18px;">&#128339;</span> Activity Log'
       . '<span style="font-size:11px; font-weight:normal; color:#999; margin-left:6px;">(latest ' . (int)$limit . ' — items added and removed)</span></div>';
    $any = false;
    while ($res && $r = $res->fetch_assoc()) {
        $any   = true;
        $n     = (int)$r['item_count'];
        $plur  = $n === 1 ? $unit : $unit . 's';
        $list  = (string)$r['items'];
        if ($r['action'] === 'insert')         { $title = "Added $n $plur to the exclusion list";         $color = '#2e7d32'; }
        elseif ($r['action'] === 'delete')     { $title = "Removed $n $plur from the exclusion list";     $color = '#c62828'; }
        else                                   { $title = "Cleared ALL exclusions ($n $plur removed)";     $color = '#c62828'; }
        echo '<div style="border-left:3px solid ' . $color . '; padding:4px 0 4px 12px; margin-bottom:10px; text-align:left;">';
        echo '<div style="font-size:14px; color:#222;">' . htmlspecialchars($title) . '</div>';
        if ($list !== '') {
            $codes = explode(', ', $list);
            $show  = array_slice($codes, 0, 15);
            echo '<div style="font-size:12px; color:#444; font-family:monospace; margin:2px 0;">' . htmlspecialchars(implode(', ', $show));
            if (count($codes) > 15) {
                echo ' <details style="display:inline;"><summary style="display:inline; cursor:pointer; color:#1976d2; font-family:inherit;">+'
                   . (count($codes) - 15) . ' more</summary><div>' . htmlspecialchars(implode(', ', array_slice($codes, 15))) . '</div></details>';
            }
            echo '</div>';
        }
        if (!empty($r['note'])) { echo '<div style="font-size:12px; color:#666;">Note: ' . htmlspecialchars($r['note']) . '</div>'; }
        echo '<div style="font-size:11px; color:#888;">' . htmlspecialchars($r['by_user'] !== null && $r['by_user'] !== '' ? $r['by_user'] : 'Unknown')
           . ' &middot; ' . htmlspecialchars($r['created_at']) . '</div>';
        echo '</div>';
    }
    if (!$any) { echo '<div style="font-size:13px; color:#999;">No activity recorded yet.</div>'; }
    echo '</div>';
}
