<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

use Glpi\Kernel\Kernel;
use Glpi\Search\SearchOption;

$glpiRoot = $argv[1] ?? '';
if ($glpiRoot === '' || !is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tools/inspect_glpi.php <glpi-root>" . PHP_EOL);
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';
(new Kernel())->boot();

$matches = [];
foreach (SearchOption::getOptionsForItemtype('Ticket') as $id => $option) {
    if (($option['table'] ?? '') !== 'glpi_tickets' || ($option['field'] ?? '') !== 'status') {
        continue;
    }
    $matches[] = [
        'id' => (int) $id,
        'name' => (string) ($option['name'] ?? ''),
        'table' => (string) $option['table'],
        'field' => (string) $option['field'],
    ];
}

echo json_encode([
    'glpi_version' => GLPI_VERSION,
    'ticket_status_options' => $matches,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
