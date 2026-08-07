<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GlpiPlugin\Marifex\Install\Schema;
use GlpiPlugin\Marifex\Metric\MetricRegistry;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$definitions = (new MetricRegistry())->all();
$assert(count($definitions) === 3, 'The semantic layer must expose exactly three approved metrics.');
$assert(array_column($definitions, 'source') === ['live', 'data_mart', 'data_mart'], 'Metrics must keep live and Data Mart sources explicit.');

$tables = Schema::tables();
$assert(count($tables) === 8, 'Analytics schema must contain eight plugin-owned tables.');
foreach ($tables as $name => $sql) {
    $assert(str_starts_with($name, 'glpi_plugin_marifex_'), "Unexpected table name: $name");
    $assert(!str_contains($sql, 'glpi_tickets` ('), 'Schema must not modify the operational ticket table.');
    $assert(!str_contains(strtoupper($sql), 'DATETIME'), 'GLPI 11 plugin tables must use TIMESTAMP instead of DATETIME.');
}

$controller = file_get_contents(dirname(__DIR__) . '/src/Controller/MetricController.php');
$assert(!str_contains($controller, 'SELECT '), 'Metric controller must not accept or build SQL.');
$assert(str_contains($controller, 'Profile::canView()'), 'Metric controller must enforce the plugin profile right.');

$settings = file_get_contents(dirname(__DIR__) . '/src/Controller/SettingsController.php');
$assert(str_contains($settings, 'Profile::canAdminister()'), 'Settings controller must enforce the plugin admin right.');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/setup.php'), "Hooks::CONFIG_PAGE]['marifex'] = 'Settings'"), 'Plugin must expose its native configuration action.');

$profile = file_get_contents(dirname(__DIR__) . '/src/Profile.php');
$assert(
    substr_count($profile, '(bool) Session::haveRight(') >= 2,
    'Profile permission helpers must normalize GLPI integer rights to bool.'
);

$dashboardTemplate = file_get_contents(dirname(__DIR__) . '/templates/dashboard/index.html.twig');
$assert(
    str_contains($dashboardTemplate, 'layout/page_without_tabs.html.twig'),
    'Dashboard must extend a layout provided by GLPI 11.'
);

$logEtl = file_get_contents(dirname(__DIR__) . '/src/Etl/IncrementalLogEtl.php');
$assert(
    str_contains($logEtl, 'EventMappingRegistry::TICKET_STATUS_CHANGED'),
    'Log ETL must use the verified semantic mapping registry.'
);
$assert(
    !str_contains($logEtl, "'id_search_option' => 12"),
    'Log ETL must not hardcode the ticket status search option.'
);
$assert(str_contains($logEtl, 'StateIntervalProjector())->rebuildMany'), 'Imported status events must rebuild deterministic ticket intervals.');

$projector = file_get_contents(dirname(__DIR__) . '/src/Etl/StateIntervalProjector.php');
$assert(str_contains($projector, "'occurred_at ASC', 'id ASC'"), 'Status events must be projected in deterministic order.');
$assert(str_contains($projector, "'source_event_end_id'"), 'Intervals must retain event lineage.');
$assert(str_contains($projector, "event['occurred_at'] === \$startedAt"), 'A status change at ticket creation time must not create duplicate interval identities.');
$assert(str_contains($projector, "ticket['date_creation']"), 'Tickets without a business date must use a stable GLPI timestamp fallback.');

$snapshot = file_get_contents(dirname(__DIR__) . '/src/Etl/SnapshotBuilder.php');
$assert(str_contains($snapshot, "new DateTimeImmutable('yesterday'"), 'The scheduled snapshot must default to the last completed day.');
$assert(str_contains($snapshot, "'average_open_ticket_age'"), 'Daily rollups must include average open ticket age.');

$settingsTemplate = file_get_contents(dirname(__DIR__) . '/templates/settings/index.html.twig');
$assert(
    str_contains($settingsTemplate, 'layout/page_without_tabs.html.twig'),
    'Settings must extend a layout provided by GLPI 11.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure" . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'All analytics structural tests passed.' . PHP_EOL);
