<?php
/*
 * PHP twin of hyper/csv_archive.py — saves a raw Tableau CSV, exactly as Tableau sent it, into
 * the same archive the Python syncs use, so it shows on transferpicklist_csv_archive.php:
 *
 *   hyper/csv_archive/<dashboard>/<YYYYMMDD>/<name>.csv
 *
 * Same rules as the Python version:
 *   - one folder per DAY; a later sync the same day is saved as <name>_<HHMMSS>.csv
 *     (nothing is overwritten);
 *   - day folders of that dashboard older than TPL_CSV_KEEP_MONTHS calendar months are deleted
 *     each time it saves;
 *   - best-effort: any error is ignored and never breaks the sync that called it.
 *
 * Used by the MinMax syncs ../../minmax/minmax_delist_sync.php and minmax_discontinue_sync.php
 * (dashboards "delist" and "discontinue").
 */

if (!function_exists('tplCsvArchiveSave')) {

define('TPL_CSV_KEEP_MONTHS', 5); // keep in step with KEEP_MONTHS in csv_archive.py

/** Delete this dashboard's day folders older than TPL_CSV_KEEP_MONTHS months. */
function tplCsvArchivePurge($dashDir) {
    if (TPL_CSV_KEEP_MONTHS <= 0 || !is_dir($dashDir)) { return; }
    // Same day TPL_CSV_KEEP_MONTHS calendar months ago (day clamped to month end), like _cutoff()
    $now   = new DateTime();
    $first = new DateTime($now->format('Y-m-01'));
    $first->modify('-' . TPL_CSV_KEEP_MONTHS . ' months');
    $day   = min((int)$now->format('j'), (int)$first->format('t'));
    $cutoff = $first->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    foreach (scandir($dashDir) as $n) {
        // only touch folders this archive created: YYYYMMDD or YYYYMMDD_HHMMSS
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(_\d{6})?$/', $n, $m) || !is_dir($dashDir . '/' . $n)) { continue; }
        if ("$m[1]-$m[2]-$m[3]" < $cutoff) {
            foreach (glob($dashDir . '/' . $n . '/*') as $f) { if (is_file($f)) { @unlink($f); } }
            @rmdir($dashDir . '/' . $n);
        }
    }
}

/**
 * Save $csvText as <name>.csv in today's folder of $dashboard.
 * Returns the saved file path, or '' if nothing was saved.
 */
function tplCsvArchiveSave($dashboard, $name, $csvText) {
    try {
        if (!preg_match('/^[a-z0-9_]+$/', $dashboard)) { return ''; }
        $root    = __DIR__ . '/csv_archive';
        $dashDir = $root . '/' . $dashboard;
        tplCsvArchivePurge($dashDir);
        $dayDir  = $dashDir . '/' . date('Ymd');
        if (!is_dir($dayDir) && !@mkdir($dayDir, 0755, true)) { return ''; }
        // Raw files must only be reachable through the archive page's access check
        if (!file_exists($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); }

        $base = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$name);
        $file = $dayDir . '/' . $base . '.csv';
        if (file_exists($file)) { $file = $dayDir . '/' . $base . '_' . date('His') . '.csv'; } // earlier sync today — keep it
        // UTF-8 BOM (like utf-8-sig in Python) so Excel opens item descriptions correctly
        $text = (string)$csvText;
        if (substr($text, 0, 3) !== "\xEF\xBB\xBF") { $text = "\xEF\xBB\xBF" . $text; }
        return @file_put_contents($file, $text) !== false ? $file : '';
    } catch (Throwable $e) {
        return '';
    }
}

}
