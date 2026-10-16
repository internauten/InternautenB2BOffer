<?php

declare(strict_types=1);

// Restores soft-deleted order states and runs the email hook before mail transport modules.
// Copyright (c) 2026 die.internauten.ch GmbH
// License: MIT

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_8(Internautenb2boffer $module): bool
{
    return $module->installOrderStates()
        && $module->moveEmailHookToFirstPosition();
}
