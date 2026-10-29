<?php
// Links to the Tableau dashboards each Procurement List page is synced from
// (same workbooks/views the hyper/*_sync.py scripts download). Shown under the stats box.
if (defined('TPL_TABLEAU_LINKS_LOADED')) { return; }
define('TPL_TABLEAU_LINKS_LOADED', true);

function tplTableauLinks($page) {
    $base   = 'https://tableaualpro.qragoracloud.com/#/views/';
    $nmWb   = 'OM-InterbranchTransferPharmacyOutletNonMovingandSlowMovingDataSupply_CheeMeng_1_4_2026/';
    $smWb   = 'OM-InterbranchTransferPharmacyOutletSlowMovingDataSupply_CheeMeng_1_4_2026/';
    $p3mWb  = 'OM-PharmacyOutletInterbranchTransferDataSupply_CheeMeng_1_4_2026/';
    $map = array(
        'nonmoving' => array(
            'Non Moving'           => $nmWb . 'Outlet6MNonMovingStockQtyExcludedNewitemfilter_1',
            'Non Moving (Ethical)' => $nmWb . 'Outlet6MNonMovingStockQtyEthicalCategory',
        ),
        'slowmoving' => array(
            'Slow Moving (Flat & Down)'          => $smWb . 'OutletSlowMovingQty2_5FlatDown',
            'Slow Moving (Up)'                   => $smWb . 'OutletSlowMovingQty2_5Up',
            'Slow Moving Ethical (Flat & Down)'  => $smWb . 'OutletSlowMovingQty2_5FlatDownEthicalCategory_1',
            'Slow Moving Ethical (Up)'           => $smWb . 'OutletSlowMovingQty2_5UpEthicalCategory',
        ),
        'past3months' => array(
            'Past 3 Months SKU Sold Qty' => $p3mWb . 'Sheet1',
        ),
        'itemgrade' => array(
            'Outlet Item Grade' => $smWb . 'OutletItemgrade',
        ),
    );
    if (!isset($map[$page])) { return; }
    // Super Admin (transferpicklist = 1) only — Procurement does not see these links
    global $transferpicklist_autho;
    if ((int)$transferpicklist_autho !== 1) { return; }
    echo '<div style="margin-top:10px; padding-top:8px; border-top:1px solid #eee; font-size:12px; display:flex; flex-wrap:wrap; align-items:center; gap:6px;">';
    echo '<span class="alpro-muted">Tableau dashboard:</span>';
    foreach ($map[$page] as $label => $path) {
        echo '<a href="' . htmlspecialchars($base . $path) . '" target="_blank" rel="noopener" '
           . 'style="display:inline-block; padding:3px 10px; border:1px solid #1976d2; border-radius:12px; color:#1976d2; text-decoration:none; background:#f5f9ff;">'
           . htmlspecialchars($label) . ' &#8599;</a>';
    }
    echo '</div>';
}
