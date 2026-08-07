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
$assert(count($definitions) === 2, 'Phase 0 must expose exactly two approved metrics.');
$assert(array_column($definitions, 'source') === ['live', 'data_mart'], 'Metrics must prove both live and Data Mart paths.');

$tables = Schema::tables();
$assert(count($tables) === 6, 'Phase 0 schema must contain six plugin-owned tables.');
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

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure" . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'All Phase 0 structural tests passed.' . PHP_EOL);
