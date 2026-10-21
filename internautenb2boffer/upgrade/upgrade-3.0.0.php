<?php

declare(strict_types=1);

// Adds the table linking offers to checkout carts/orders and the hook that detects orders placed from an offer.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_3_0_0(Internautenb2boffer $module): bool
{
    return $module->installCheckoutTable()
        && $module->registerHook('actionValidateOrder');
}
