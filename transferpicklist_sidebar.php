<?php
$current_page   = basename($_SERVER['PHP_SELF']);
$_tpl_level        = isset($transferpicklist_autho) ? (int)$transferpicklist_autho : 0;
$_tpl_dept         = isset($department) ? (int)$department : 0;
$_tpl_is_super_admin = $_tpl_level === 1;
$_tpl_is_admin     = $_tpl_level === 1;
$_tpl_is_proc      = $_tpl_is_admin || ($_tpl_dept === 18);
$_tpl_is_admin2    = $_tpl_level === 2; // Admin 2: Warehouse Returns, Procurement List, Exclusions → Campaign only
// My Outlet Tasks button: same positions as the page's own check in transferpicklist_outlet.php — keep both lists in step
$_tpl_has_access   = $_tpl_is_admin || (isset($status_semasa) && in_array($status_semasa, array(
    'Area Manager', 'Associate Area Manager', 'Branch Manager', 'Assistant Branch Manager', 'Pharmacist In Charge (PhIC)', 'Pharmacist Manager', 'Operation Efficiency Manager', 'Supervisor', 'Senior Supervisor', 'Junior Supervisor', 'Supervisor (PSH)', 'Pharmacist On Duty (PhOD)', 'Pharmacist On Duty (PHOD)', 'Pharmacist On Duty (Resident)', 'Pharmacist On Duty (Resident) PSH', 'Pharmacy Assistant Grade I', 'Pharmacy Assistant Grade II',
), true));
?>
<style>
    .dropdown {
        position: relative;
        display: inline-block;
    }
    .dropdown-content {
        display: none;
        position: absolute;
        background-color: #ffffff;
        min-width: 180px;
        box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.1);
        z-index: 1000;
        border-radius: 4px;
        border: 1px solid #ddd;
        top: 100%;
        left: 0;
    }
    .dropdown-content a {
        color: #333;
        padding: 10px 15px;
        text-decoration: none;
        display: block;
        font-size: 13px;
        border-bottom: 1px solid #eee;
    }
    .dropdown-content a:last-child { border-bottom: none; }
    .dropdown-content a:hover { background-color: #f9f9f9; color: #2980b9; }
    .dropdown:hover .dropdown-content { display: block; }
    /* Flat text tabs, same design as the ATEM / OKR menu (atem/css/style.css .atem-nav) */
    .tpl-nav-box { padding: 5px 12px !important; border: none !important; border-radius: 8px !important; box-shadow: 0 2px 10px rgba(19, 92, 170, .1) !important; }
    .tpl-nav .alpro-btn {
        height: 30px; padding: 0 10px; font-size: 13px; font-weight: 500; line-height: 1;
        display: inline-flex; align-items: center; gap: 4px; box-sizing: border-box; border-radius: 6px;
        background: transparent; color: #6c757d; border: none; box-shadow: none; transform: none;
        font-family: "Inter", "Segoe UI", sans-serif; transition: all .2s ease;
    }
    .tpl-nav .alpro-btn:hover { color: #0d6efd; background: rgba(13, 110, 253, .1); box-shadow: none; transform: none; }
    .tpl-nav .alpro-btn.alpro-btn-blue { color: #0d6efd; background: rgba(13, 110, 253, .1); font-weight: 600; }
    .tpl-nav-title { margin-right: 6px; color: #2c3e50; font-size: 14px; }
    /* Smaller gap under the blue page header (page.css gives it 18px) — this module only */
    body .header { margin-bottom: 8px !important; }

    /* Compact table rows on every page (myTable = most lists, wh-tbl = Warehouse Returns) */
    body table.myTable td, body table.wh-tbl td { padding: 2px 8px !important; line-height: 1.25 !important; }
    body table.myTable th, body table.wh-tbl th { padding: 5px 8px !important; }

    /* Compact filter bars — one rule for every page's filter section
       (each page keeps its own layout; this only shrinks spacing, fields and buttons) */
    body .prc-filter-bar, body .tpl-filter-bar, body .detail-filter, body .filter-bar,
    body .arc-filter-bar, body .oex-filter-bar, body .tpl-adm-search {
        padding: 8px 12px !important; margin-bottom: 10px !important; gap: 6px !important;
    }
    body .prc-filter-label, body .tpl-filter-label, body .detail-filter-label, body .filter-bar label,
    body .arc-filter-label, body .oex-filter-label, body .tpl-adm-search label {
        font-size: 10.5px !important; margin-bottom: 2px !important;
    }
    body .prc-filter-bar input[type=text], body .prc-filter-bar select,
    body .tpl-filter-bar input[type=text], body .tpl-filter-bar select,
    body .detail-filter input[type=text],  body .detail-filter select,
    body .filter-bar input[type=text],     body .filter-bar select,
    body .arc-filter-bar input[type=text], body .arc-filter-bar select,
    body .oex-filter-bar input[type=text], body .oex-filter-bar select,
    body .tpl-adm-search input[type=text], body .tpl-adm-search select {
        height: 28px !important; padding: 2px 8px !important; font-size: 12px !important;
    }
    body .prc-filter-bar .alpro-btn, body .prc-filter-bar input[type=submit], body .prc-filter-bar button,
    body .tpl-filter-bar .alpro-btn, body .tpl-filter-bar input[type=submit], body .tpl-filter-bar button,
    body .detail-filter .alpro-btn,  body .detail-filter input[type=submit],  body .detail-filter button,
    body .filter-bar .alpro-btn,     body .filter-bar input[type=submit],     body .filter-bar button,
    body .arc-filter-bar .alpro-btn, body .arc-filter-bar input[type=submit], body .arc-filter-bar button,
    body .oex-filter-bar .alpro-btn, body .oex-filter-bar input[type=submit], body .oex-filter-bar button,
    body .tpl-adm-search .alpro-btn, body .tpl-adm-search input[type=submit] {
        height: 28px !important; padding: 0 12px !important; font-size: 12px !important;
        line-height: 28px !important; box-sizing: border-box !important; border-radius: 5px !important;
    }
</style>

<div class="alpro-box tpl-nav-box" style="margin-bottom:10px; background:#fff; overflow:visible;">
    <div class="alpro-actions tpl-nav" style="margin-bottom:0; display:flex; align-items:center; flex-wrap:wrap; gap:4px; overflow:visible;">

        <strong class="tpl-nav-title">Transfer Pick List</strong>

        <?php if ($_tpl_is_proc): ?>
        <a href="index.php"
           class="alpro-btn <?php echo ($current_page === 'index.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Dashboard
        </a>
        <?php endif; ?>

        <?php if ($_tpl_has_access): ?>
        <a href="transferpicklist_outlet.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_outlet.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            My Outlet Tasks
        </a>
        <?php endif; ?>

        <?php if ($_tpl_is_proc || $_tpl_is_admin2): ?>
        <?php
        // Activity Log link: when on Warehouse Returns, open it filtered to the pick list being viewed
        $_wh_log_url = 'transferpicklist_activity_log.php';
        if ($current_page === 'transferpicklist_warehouse.php' && !empty($filterPicklist)) {
            $_wh_log_url .= '?picklist_no=' . urlencode($filterPicklist);
        }
        ?>
        <div class="dropdown">
            <button class="alpro-btn <?php echo in_array($current_page, array('transferpicklist_warehouse.php', 'transferpicklist_warehouse_detail.php', 'transferpicklist_activity_log.php')) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;"
                    onclick="window.location.href='transferpicklist_warehouse.php';">
                Warehouse Returns &#9662;
            </button>
            <div class="dropdown-content">
                <a href="transferpicklist_warehouse.php">Warehouse Returns</a>
                <?php if ($_tpl_is_admin): ?>
                <a href="<?php echo htmlspecialchars($_wh_log_url); ?>">&#128220; Activity Log</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($_tpl_is_proc || $_tpl_is_admin2): ?>
        <div class="dropdown">
            <button class="alpro-btn <?php echo in_array($current_page, array('transferpicklist_procurement.php', 'transferpicklist_past3months_list.php', 'transferpicklist_itemgrade_list.php')) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;">
                Procurement List &#9662;
            </button>
            <div class="dropdown-content">
                <a href="transferpicklist_procurement.php?list=nonmoving">Non-Moving List</a>
                <a href="transferpicklist_procurement.php?list=slowmoving">Slow Moving List</a>
                <a href="transferpicklist_past3months_list.php">Past 3 Months Data</a>
                <a href="transferpicklist_itemgrade_list.php">Item Grade Data</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($_tpl_is_super_admin): ?>
        <div class="dropdown">
            <button class="alpro-btn <?php echo (strpos($current_page, 'sync') !== false || $current_page === 'transferpicklist_csv_archive.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;">
                Sync &#9662;
            </button>
            <div class="dropdown-content">
                <a href="transferpicklist_sync_status.php">Sync Status</a>
                <a href="transferpicklist_csv_archive.php">Tableau CSV Archive</a>
            </div>
        </div>
        <?php elseif ($_tpl_is_admin): ?>
        <a href="transferpicklist_sync_status.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_sync_status.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Sync Status
        </a>
        <a href="transferpicklist_csv_archive.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_csv_archive.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Tableau CSV Archive
        </a>
        <?php elseif ($_tpl_is_proc): ?>
        <!-- Procurement (dept 18, not admin): no Sync access, but can view the CSV archive -->
        <a href="transferpicklist_csv_archive.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_csv_archive.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Tableau CSV Archive
        </a>
        <?php endif; ?>

        <?php if ($_tpl_is_super_admin): ?>
        <a href="transferpicklist_generate.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_generate.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
        Generate Pick List
        </a>
        <?php endif; ?>

        <?php if ($_tpl_is_super_admin || $_tpl_is_admin2): ?>
        <div class="dropdown">
            <?php $_excl_pages = array('transferpicklist_outlet_exclusion.php','transferpicklist_excluded.php','transferpicklist_excluded_items.php','transferpicklist_campaign.php','transferpicklist_delist.php'); ?>
            <button class="alpro-btn <?php echo in_array($current_page, $_excl_pages) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;">
                Exclusions &#9662;
            </button>
            <div class="dropdown-content">
                <?php if ($_tpl_is_super_admin): ?>
                <a href="transferpicklist_outlet_exclusion.php">Outlet Exclusion</a>
                <a href="transferpicklist_excluded_items.php">Excluded Items (Global)</a>
                <a href="transferpicklist_excluded.php">Excluded Items (Per Outlet)</a>
                <a href="transferpicklist_delist.php">Delist / Discontinue (MinMax)</a>
                <?php endif; ?>
                <a href="transferpicklist_campaign.php">Campaign (MinMax)</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($_tpl_is_super_admin): ?>
        <a href="transferpicklist_admin.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_admin.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Admin Settings
        </a>
        <?php endif; ?>

    </div>
</div>

<?php include(__DIR__ . '/transferpicklist_autofilter.php'); // auto-apply filter forms on every page ?>
