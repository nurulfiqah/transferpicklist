<?php
// "Transfer Route" filter (formerly "Priority") — shared by transferpicklist_view.php
// and transferpicklist_outlet.php so the dropdown and its SQL stay identical.
//
// Values are transferpicklist_item.priority_type as set by transferpicklist_generate.php
// (same_area / peninsular / sabah / sarawak / warehouse / maintain), plus
// warehouse_<region> = returns to that region's warehouse only. Warehouse acmm_ids
// match transferpicklist_warehouse.php's $regionAcmm (main + alternate per region).

function tplRouteWarehouseAcmm() {
    return array(
        'peninsular' => '1001,1004',
        'sabah'      => '1016,1013',
        'sarawak'    => '1005,1002',
    );
}

// SQL condition for the selected route (table alias `i` = transferpicklist_item)
function tplRouteWhere($conn, $route) {
    $whAcmm = tplRouteWarehouseAcmm();
    if (preg_match('/^warehouse_(peninsular|sabah|sarawak)$/', $route, $m)) {
        return "i.priority_type = 'warehouse' AND i.to_outlet_id IN (SELECT id FROM outlet WHERE acmm_id IN (" . $whAcmm[$m[1]] . "))";
    }
    return "i.priority_type = '" . $conn->real_escape_string($route) . "'";
}

// Which half of a Generate run a pick list is: transferpicklist_generate.php creates
// "<base>-outlet" (same_area / region / maintain rows) and "<base>-warehouse"
// (warehouse rows only). '' = unknown / several pick lists → show every route.
function tplPicklistKind($picklistNo) {
    if (substr($picklistNo, -10) === '-warehouse') { return 'warehouse'; }
    if (substr($picklistNo, -7)  === '-outlet')    { return 'outlet'; }
    return '';
}

// True if $route can exist in a pick list of this kind (e.g. a warehouse route
// chosen earlier no longer applies after switching to an "-outlet" pick list).
function tplRouteFitsKind($route, $kind) {
    if ($route === '' || $kind === '') { return true; }
    $isWarehouse = ($route === 'warehouse' || strpos($route, 'warehouse_') === 0);
    return $kind === 'warehouse' ? $isWarehouse : !$isWarehouse;
}

// <option>s for the dropdown, grouped so outlet transfers and warehouse returns can't be
// confused. $kind limits it to the routes that can exist in that kind of pick list.
function tplRouteOptions($selected, $kind = '', $showMaintain = true) {
    $showOutlet    = ($kind !== 'warehouse');
    $showWarehouse = ($kind !== 'outlet');
    $showMaintain  = $showMaintain && $showOutlet;
    $groups = array();
    if ($showOutlet) {
        $groups['Transfer to another outlet'] = array(
            'same_area'  => 'Same Area Manager',
            'peninsular' => 'Same Region – Peninsular',
            'sabah'      => 'Same Region – Sabah',
            'sarawak'    => 'Same Region – Sarawak',
        );
    }
    if ($showWarehouse) {
        $groups['Return to warehouse'] = array(
            'warehouse'            => 'All Warehouses',
            'warehouse_peninsular' => 'Peninsular Warehouse',
            'warehouse_sabah'      => 'Sabah Warehouse',
            'warehouse_sarawak'    => 'Sarawak Warehouse',
        );
    }
    if ($showMaintain) {
        $groups['No transfer'] = array(
            'maintain' => 'Keep at Outlet (Maintain)',
        );
    }
    echo '<option value="">-- All Routes --</option>';
    foreach ($groups as $label => $opts) {
        echo '<optgroup label="' . htmlspecialchars($label) . '">';
        foreach ($opts as $val => $text) {
            echo '<option value="' . $val . '"' . ($selected === $val ? ' selected' : '') . '>' . htmlspecialchars($text) . '</option>';
        }
        echo '</optgroup>';
    }
}
