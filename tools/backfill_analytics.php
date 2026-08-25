<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

use Glpi\Kernel\Kernel;
use GlpiPlugin\Marifex\Etl\IncrementalLogEtl;
use GlpiPlugin\Marifex\Etl\IncrementalTicketEtl;
use GlpiPlugin\Marifex\Etl\SnapshotBuilder;

$glpiRoot = $argv[1] ?? '';
$days = max(1, min(366, (int) ($argv[2] ?? 30)));
if ($glpiRoot === '' || !is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tools/backfill_analytics.php <glpi-root> [days]" . PHP_EOL);
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';
(new Kernel())->boot();

$runUntilEmpty = static function (object $pipeline, string $label): int {
    $total = 0;
    for ($iteration = 1; $iteration <= 100; ++$iteration) {
        $processed = $pipeline->run();
        $total += $processed;
        if ($processed === 0) {
            printf("%s complete: %d records processed.%s", $label, $total, PHP_EOL);
            return $total;
        }
    }
    throw new RuntimeException($label . ' did not finish within 100 batches.');
};

$runUntilEmpty(new IncrementalTicketEtl(), 'Ticket ETL');
$runUntilEmpty(new IncrementalLogEtl(), 'Ticket status ETL');

$snapshotBuilder = new SnapshotBuilder();
$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
$snapshotCount = 0;
for ($offset = $days; $offset >= 1; --$offset) {
    $snapshotCount += $snapshotBuilder->run($today->modify(sprintf('-%d days', $offset)));
}

printf("Snapshots complete: %d ticket-day rows across %d days.%s", $snapshotCount, $days, PHP_EOL);
