<?php

declare(strict_types=1);

// Adds the offer validity setting and the hook that exposes the "Order offer" link to the order history.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0(Internautenb2boffer $module): bool
{
    if (Configuration::get(Internautenb2boffer::OFFER_VALIDITY_DAYS_CONFIG_KEY) === false) {
        Configuration::updateValue(
            Internautenb2boffer::OFFER_VALIDITY_DAYS_CONFIG_KEY,
            Internautenb2boffer::OFFER_VALIDITY_DAYS_DEFAULT
        );
    }

    return $module->registerHook('actionPresentOrder');
}
