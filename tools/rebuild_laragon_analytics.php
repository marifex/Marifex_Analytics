<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['confirm:']);
if (($options['confirm'] ?? '') !== 'REBUILD_LARAGON_ANALYTICS') {
    fwrite(STDERR, "Refused. Pass --confirm=REBUILD_LARAGON_ANALYTICS.\n");
    exit(2);
}

$workspaceRoot = dirname(__DIR__);
$glpiRoot = 'C:/laragon-clean/www/glpi';
spl_autoload_register(static function (string $class) use ($workspaceRoot): void {
    $prefix = 'GlpiPlugin\\Marifex\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $workspaceRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);
require_once $glpiRoot . '/vendor/autoload.php';
foreach ([
    'src/Install/Schema.php',
    'src/Install/Installer.php',
    'src/Etl/CheckpointStore.php',
    'src/Etl/EventMappingRegistry.php',
    'src/Etl/StateIntervalProjector.php',
    'src/Etl/AssignmentIntervalProjector.php',
    'src/Etl/IncrementalTicketEtl.php',
    'src/Etl/IncrementalLogEtl.php',
    'src/Etl/TicketOperationsSnapshotBuilder.php',
    'src/Etl/DomainSnapshotBuilder.php',
    'src/Etl/SnapshotBuilder.php',
] as $workspaceClass) {
    require_once $workspaceRoot . '/' . $workspaceClass;
}

$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();

$user = new User();
if (!$user->getFromDB(2)) {
    throw new RuntimeException('GLPI super-admin account #2 is unavailable.');
}
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);

global $DB;
(new GlpiPlugin\Marifex\Install\Installer())->install();
$originalConfiguration = Config::getConfigurationValues('plugin:marifex');
$originalBatchSize = (int) ($originalConfiguration['etl_batch_size'] ?? 500);
Config::setConfigurationValues('plugin:marifex', array_merge($originalConfiguration, ['etl_batch_size' => 5000]));
$restoreBatchSize = true;
register_shutdown_function(static function () use (&$restoreBatchSize, $originalBatchSize): void {
    if (!$restoreBatchSize) {
        return;
    }
    $configuration = Config::getConfigurationValues('plugin:marifex');
    Config::setConfigurationValues('plugin:marifex', array_merge($configuration, ['etl_batch_size' => $originalBatchSize]));
});
$derivedTables = [
    'glpi_plugin_marifex_daily_response_observations',
    'glpi_plugin_marifex_daily_licence_title_observations',
    'glpi_plugin_marifex_daily_matrix_rollups',
    'glpi_plugin_marifex_daily_rollups',
    'glpi_plugin_marifex_daily_snapshots',
    'glpi_plugin_marifex_state_intervals',
    'glpi_plugin_marifex_ticket_events',
    'glpi_plugin_marifex_etl_checkpoints',
];
foreach ($derivedTables as $table) {
    if (!$DB->tableExists($table)) {
        throw new RuntimeException("Required MarifeX table is missing: {$table}");
    }
}

foreach ($derivedTables as $table) {
    $DB->delete($table, [['id' => ['>=', 0]]]);
}
fwrite(STDOUT, "Cleared derived MarifeX ETL data; dashboard definitions and operational records were preserved.\n");

$ticketRuns = 0;
$ticketRows = 0;
do {
    $processed = (new GlpiPlugin\Marifex\Etl\IncrementalTicketEtl())->run();
    $ticketRows += $processed;
    ++$ticketRuns;
    fwrite(STDOUT, "Ticket ETL batch {$ticketRuns}: {$processed}\n");
} while ($processed > 0);

$logRuns = 0;
$logRows = 0;
do {
    $processed = (new GlpiPlugin\Marifex\Etl\IncrementalLogEtl())->run();
    $logRows += $processed;
    ++$logRuns;
    if ($logRuns === 1 || $logRuns % 10 === 0 || $processed === 0) {
        fwrite(STDOUT, "Log ETL batch {$logRuns}: {$processed}; cumulative {$logRows}\n");
    }
} while ($processed > 0);

$end = new DateTimeImmutable('yesterday', new DateTimeZone('UTC'));
$start = $end->sub(new DateInterval('P729D'));
$snapshots = 0;
$ticketObservations = 0;
for ($day = $start; $day <= $end; $day = $day->add(new DateInterval('P1D'))) {
    $ticketObservations += (new GlpiPlugin\Marifex\Etl\SnapshotBuilder())->run($day);
    ++$snapshots;
    if ($snapshots === 1 || $snapshots % 30 === 0 || $day == $end) {
        fwrite(STDOUT, sprintf(
            "Snapshots: %d/730 through %s; open-ticket observations: %d\n",
            $snapshots,
            $day->format('Y-m-d'),
            $ticketObservations,
        ));
    }
}

echo json_encode([
    'ticket_etl_rows' => $ticketRows,
    'log_etl_rows' => $logRows,
    'snapshots' => $snapshots,
    'snapshot_ticket_observations' => $ticketObservations,
    'from' => $start->format('Y-m-d'),
    'to' => $end->format('Y-m-d'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
$configuration = Config::getConfigurationValues('plugin:marifex');
Config::setConfigurationValues('plugin:marifex', array_merge($configuration, ['etl_batch_size' => $originalBatchSize]));
$restoreBatchSize = false;
