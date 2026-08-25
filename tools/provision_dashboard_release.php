<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

use Glpi\Kernel\Kernel;
use GlpiPlugin\Marifex\Dashboard\DashboardDefinitionService;
use GlpiPlugin\Marifex\Security\EntityScope;

$glpiRoot = $argv[1] ?? '';
$userId = (int) ($argv[2] ?? 0);
$entityId = (int) ($argv[3] ?? -1);
if ($glpiRoot === '' || !is_file($glpiRoot . '/vendor/autoload.php') || $userId < 1 || $entityId < 0) {
    fwrite(STDERR, "Usage: php tools/provision_dashboard_release.php <glpi-root> <user-id> <entity-id>" . PHP_EOL);
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';
(new Kernel())->boot();

$_SESSION['glpiID'] = $userId;
$_SESSION['glpiactive_entity'] = $entityId;
$_SESSION['glpiactiveentities'] = [$entityId];
$_SESSION['glpiactive_entity_recursive'] = false;

$workspace = (new DashboardDefinitionService(new EntityScope([$entityId], $entityId)))->workspace();
printf(
    "Provisioned %s for user %d, entity %d with %d widgets.%s",
    $workspace['dashboard']['name'],
    $userId,
    $entityId,
    count($workspace['dashboard']['definition']['widgets']),
    PHP_EOL
);
