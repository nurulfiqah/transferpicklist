<?php
// Shared list footer for every paged Transfer Pick List page (DataTables-style):
//   left:  Show [15|25|50|100] entries
//   right: Showing X to Y of Z entries   Previous  1 … 4 5 6 … 99  Next
// Links keep every current GET filter and only change ?page= / ?per_page=.
if (defined('TPL_PAGER_LOADED')) { return; }
define('TPL_PAGER_LOADED', true);

// Rows per page from ?per_page= (allowed values only), else the page's default.
function tplPerPage($default = 50) {
    $allowed = array(15, 25, 50, 100);
    $v = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 0;
    return in_array($v, $allowed, true) ? $v : $default;
}

// Current page URL with some GET params replaced (export/ajax never carried over).
function tplPagerUrl($overrides) {
    $q = $_GET;
    unset($q['export'], $q['ajax']);
    foreach ($overrides as $k => $v) {
        if ($v === null) { unset($q[$k]); } else { $q[$k] = $v; }
    }
    $q = array_filter($q, function($v) { return $v !== ''; });
    return basename($_SERVER['PHP_SELF']) . ($q ? '?' . http_build_query($q) : '');
}

// Renders the footer. $estimate = true when $totalItems is an approximation.
function tplPager($page, $totalPages, $totalItems, $perPage, $estimate = false) {
    $page       = max(1, (int)$page);
    $totalPages = max(1, (int)$totalPages);
    $from       = $totalItems > 0 ? ($page - 1) * $perPage + 1 : 0;
    $to         = $estimate ? $page * $perPage : min($page * $perPage, $totalItems);
    $approx     = $estimate ? '≈ ' : '';
    static $cssDone = false;
    if (!$cssDone) {
        $cssDone = true;
        echo '<style>
.tpl-pager{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:8px 0;font-size:12px;color:#444;}
.tpl-pager > div{display:flex;align-items:center;}
.tpl-pager select{height:26px;padding:0 4px;border:1px solid #ccc;border-radius:4px;font-size:12px;margin:0 5px;background:#fff;}
.tpl-pager-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.tpl-pager-info{color:#666;font-size:12px;}
.tpl-pager-pages{display:flex;gap:3px;align-items:center;}
.tpl-pager-pages a,.tpl-pager-pages span{display:inline-block;min-width:26px;height:26px;line-height:24px;padding:0 7px;border:1px solid #dee2e6;border-radius:4px;text-align:center;text-decoration:none;color:#0d6efd;background:#fff;box-sizing:border-box;font-size:12px;}
.tpl-pager-pages a:hover{background:#e9ecef;}
.tpl-pager-pages .active{background:#0d6efd;border-color:#0d6efd;color:#fff;}
.tpl-pager-pages .disabled{color:#adb5bd;background:#fff;cursor:default;}
.tpl-pager-pages .gap{border:none;min-width:auto;padding:0 4px;color:#888;}
</style>';
    }
    echo '<div class="tpl-pager">';

    // Left: Show [n] entries
    echo '<div>Show<select onchange="window.location.href=this.value">';
    foreach (array(15, 25, 50, 100) as $n) {
        $url = tplPagerUrl(array('per_page' => $n, 'page' => null));
        echo '<option value="' . htmlspecialchars($url) . '"' . ($n === (int)$perPage ? ' selected' : '') . '>' . $n . '</option>';
    }
    echo '</select>entries</div>';

    // Right: info + page buttons
    echo '<div class="tpl-pager-right"><span class="tpl-pager-info">Showing ' . number_format($from) . ' to ' . $approx . number_format($to)
       . ' of ' . $approx . number_format($totalItems) . ' entries</span><div class="tpl-pager-pages">';

    if ($page > 1) {
        echo '<a href="' . htmlspecialchars(tplPagerUrl(array('page' => 1))) . '" title="First page">&laquo; First</a>';
        echo '<a href="' . htmlspecialchars(tplPagerUrl(array('page' => $page - 1))) . '">Previous</a>';
    } else {
        echo '<span class="disabled">&laquo; First</span><span class="disabled">Previous</span>';
    }

    // 1 … (page-2 .. page+2) … last   (no "last" jump when the total is only an estimate)
    $pages = array(1);
    for ($p = $page - 2; $p <= $page + 2; $p++) { if ($p > 1 && $p < $totalPages) { $pages[] = $p; } }
    if ($totalPages > 1 && !$estimate) { $pages[] = $totalPages; }
    $pages = array_values(array_unique($pages));
    sort($pages);
    $prev = 0;
    foreach ($pages as $p) {
        if ($p - $prev > 1) { echo '<span class="gap">…</span>'; }
        if ($p === $page) { echo '<span class="active">' . $p . '</span>'; }
        else              { echo '<a href="' . htmlspecialchars(tplPagerUrl(array('page' => $p))) . '">' . $p . '</a>'; }
        $prev = $p;
    }
    if ($estimate && $page + 2 < $totalPages) { echo '<span class="gap">…</span>'; }

    if ($page < $totalPages) { echo '<a href="' . htmlspecialchars(tplPagerUrl(array('page' => $page + 1))) . '">Next</a>'; }
    else                     { echo '<span class="disabled">Next</span>'; }

    // Last — not offered when the total is only an estimate (Item Grade, unfiltered)
    if (!$estimate) {
        if ($page < $totalPages) { echo '<a href="' . htmlspecialchars(tplPagerUrl(array('page' => $totalPages))) . '" title="Last page">Last &raquo;</a>'; }
        else                     { echo '<span class="disabled">Last &raquo;</span>'; }
    }

    echo '</div></div></div>';
}
