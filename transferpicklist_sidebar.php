<?php
$current_page   = basename($_SERVER['PHP_SELF']);
$_tpl_level        = isset($transferpicklist_autho) ? (int)$transferpicklist_autho : 0;
$_tpl_dept         = isset($department) ? (int)$department : 0;
$_tpl_is_super_admin = $_tpl_level >= 3;
$_tpl_is_admin     = $_tpl_level >= 1;
$_tpl_is_proc      = $_tpl_is_admin || ($_tpl_dept === 18);
$_tpl_has_access   = $_tpl_is_admin || (isset($status_semasa) && ($status_semasa === 'Area Manager' || $status_semasa === 'Outlet Manager'));
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
</style>

<div class="alpro-box" style="margin-bottom:20px; background:#fff; overflow:visible;">
    <div class="alpro-actions" style="margin-bottom:0; display:flex; align-items:center; flex-wrap:wrap; gap:5px; overflow:visible;">

        <strong style="margin-right:15px; color:#2c3e50; font-size:16px;">Transfer Pick List</strong>

        <?php if ($_tpl_is_proc): ?>
        <a href="index.php"
           class="alpro-btn <?php echo ($current_page === 'index.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Dashboard
        </a>
        <?php endif; ?>

        <?php if ($_tpl_has_access): ?>
        <a href="transferpicklist_outlet.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_outlet.php') ? 'alpro-btn-blue' : 'alpro-btn-green'; ?>">
            My Outlet Tasks
        </a>
        <?php endif; ?>

        <?php if ($_tpl_is_proc): ?>
        <a href="transferpicklist_warehouse.php"
           class="alpro-btn <?php echo ($current_page === 'transferpicklist_warehouse.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>">
            Warehouse Returns
        </a>
        <?php endif; ?>

        <?php if ($_tpl_is_proc): ?>
        <div class="dropdown">
            <button class="alpro-btn <?php echo ($current_page === 'transferpicklist_procurement.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;">
                Procurement List &#9662;
            </button>
            <div class="dropdown-content">
                <a href="transferpicklist_procurement.php?list=nonmoving">Non-Moving List</a>
                <a href="transferpicklist_procurement.php?list=slowmoving">Slow Moving List</a>
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

        <?php if ($_tpl_is_super_admin): ?>
        <div class="dropdown">
            <?php $_excl_pages = array('transferpicklist_outlet_exclusion.php','transferpicklist_excluded.php','transferpicklist_excluded_items.php'); ?>
            <button class="alpro-btn <?php echo in_array($current_page, $_excl_pages) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;">
                Exclusions &#9662;
            </button>
            <div class="dropdown-content">
                <a href="transferpicklist_outlet_exclusion.php">Outlet Exclusion</a>
                <a href="transferpicklist_excluded_items.php">Excluded Items (Global)</a>
                <a href="transferpicklist_excluded.php">Excluded Items (Per Outlet)</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($_tpl_is_admin): ?>
        <div class="dropdown">
            <?php $_tools_pages = array('transferpicklist_warehouse_import.php','transferpicklist_activity_log.php'); ?>
            <button class="alpro-btn <?php echo in_array($current_page, $_tools_pages) ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>"
                    style="cursor:pointer; display:inline-flex; align-items:center; gap:5px; border:none; outline:none;">
                Tools &#9662;
            </button>
            <div class="dropdown-content">
                <?php if ($_tpl_is_super_admin): ?>
                <a href="transferpicklist_warehouse_import.php">WH Import</a>
                <?php endif; ?>
                <a href="transferpicklist_activity_log.php">Activity Log</a>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
