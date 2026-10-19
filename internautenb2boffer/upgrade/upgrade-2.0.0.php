<?php

declare(strict_types=1);

// Adds the "Offer ordered" state and cancels already rejected offers so their reserved stock is released.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0(Internautenb2boffer $module): bool
{
    if (!$module->installOrderStates() || !$module->registerHook('actionOrderHistoryAddAfter')) {
        return false;
    }

    $rejectedId = (int) Configuration::get('INTERNAUTENB2BOFFER_OS_OFFER_REJECTED');
    if ($rejectedId <= 0) {
        return true;
    }

    $orderIds = Db::getInstance()->executeS(
        'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`'
        . ' WHERE `module` = \'' . pSQL($module->name) . '\' AND `current_state` = ' . $rejectedId
    );

    foreach ($orderIds ?: [] as $row) {
        $order = new Order((int) $row['id_order']);
        if (Validate::isLoadedObject($order)) {
            $module->cancelOrderSilently($order);
        }
    }

    return true;
}
