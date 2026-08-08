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
$assert(count($definitions) === 4, 'The semantic layer must expose exactly four approved metrics.');
$assert(array_column($definitions, 'source') === ['live', 'data_mart', 'data_mart', 'data_mart'], 'Metrics must keep live and Data Mart sources explicit.');

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
    str_contains($logEtl, 'mappings->refreshAll()'),
    'Log ETL must refresh every verified semantic mapping.'
);
$assert(
    !str_contains($logEtl, "'id_search_option' => 12"),
    'Log ETL must not hardcode the ticket status search option.'
);
$assert(str_contains($logEtl, 'StateIntervalProjector())->rebuildMany'), 'Imported status events must rebuild deterministic ticket intervals.');
$assert(str_contains($logEtl, 'AssignmentIntervalProjector())->rebuildMany'), 'Imported assignment events must rebuild membership intervals.');
$assert(str_contains($logEtl, "'source_labels_redacted'"), 'Assignment labels must be redacted from analytics events.');

$mappingRegistry = file_get_contents(dirname(__DIR__) . '/src/Etl/EventMappingRegistry.php');
$assert(str_contains($mappingRegistry, "['NEWTABLE.type'] ?? null"), 'Assignment mappings must verify the GLPI role discriminator.');

$assignmentProjector = file_get_contents(dirname(__DIR__) . '/src/Etl/AssignmentIntervalProjector.php');
$assert(str_contains($assignmentProjector, "'technician'"), 'Technician membership intervals must be projected.');
$assert(str_contains($assignmentProjector, "'group'"), 'Group membership intervals must be projected.');

$projector = file_get_contents(dirname(__DIR__) . '/src/Etl/StateIntervalProjector.php');
$assert(str_contains($projector, "'occurred_at ASC', 'id ASC'"), 'Status events must be projected in deterministic order.');
$assert(str_contains($projector, "'source_event_end_id'"), 'Intervals must retain event lineage.');
$assert(str_contains($projector, "event['occurred_at'] === \$startedAt"), 'A status change at ticket creation time must not create duplicate interval identities.');
$assert(str_contains($projector, "ticket['date_creation']"), 'Tickets without a business date must use a stable GLPI timestamp fallback.');

$snapshot = file_get_contents(dirname(__DIR__) . '/src/Etl/SnapshotBuilder.php');
$assert(str_contains($snapshot, "new DateTimeImmutable('yesterday'"), 'The scheduled snapshot must default to the last completed day.');
$assert(str_contains($snapshot, "'average_open_ticket_age'"), 'Daily rollups must include average open ticket age.');
$assert(str_contains($snapshot, "'historical_group_backlog'"), 'Daily rollups must include assigned group backlog.');

$settingsTemplate = file_get_contents(dirname(__DIR__) . '/templates/settings/index.html.twig');
$assert(
    str_contains($settingsTemplate, 'layout/page_without_tabs.html.twig'),
    'Settings must extend a layout provided by GLPI 11.'
);

$dashboardDefinition = file_get_contents(dirname(__DIR__) . '/src/Dashboard/DashboardDefinitionService.php');
$assert(str_contains($dashboardDefinition, 'count($widgets) > 24'), 'Saved dashboards must enforce a bounded widget count.');
$assert(str_contains($dashboardDefinition, 'self::METRICS[$metric]'), 'Saved widgets must use the certified metric and type allowlist.');
$assert(!str_contains($dashboardDefinition, 'SELECT '), 'Dashboard definitions must not accept or build SQL.');

$definitionController = file_get_contents(dirname(__DIR__) . '/src/Controller/DashboardDefinitionController.php');
$assert(str_contains($definitionController, 'Profile::canView()'), 'Dashboard definition API must enforce the plugin profile right.');
$assert(str_contains($definitionController, 'Session::checkCSRF'), 'Dashboard definition writes must enforce GLPI CSRF protection.');
$assert(str_contains($definitionController, "methods: ['GET', 'PUT']"), 'Dashboard definition API must expose only read and bounded update methods.');

$dashboardFrontend = file_get_contents(dirname(__DIR__) . '/frontend/Dashboard.vue');
$assert(str_contains($dashboardFrontend, "'X-Requested-With': 'XMLHttpRequest'"), 'Dashboard writes must opt into GLPI AJAX CSRF validation.');
$assert(str_contains($dashboardFrontend, "'X-Glpi-Csrf-Token': props.csrfToken"), 'Dashboard writes must send the GLPI CSRF header.');
$dashboardBootstrap = file_get_contents(dirname(__DIR__) . '/frontend/main.ts');
$assert(str_contains($dashboardBootstrap, "meta[property=\"glpi:csrf_token\"]"), 'Dashboard bootstrap must fall back to GLPI core CSRF metadata.');

$entityScope = file_get_contents(dirname(__DIR__) . '/src/Security/EntityScope.php');
$assert(str_contains($entityScope, 'Session::getActiveEntities()'), 'Entity scope must use the supported GLPI Session adapter.');
$assert(!str_contains($entityScope, "\$_SESSION["), 'Entity scope must not depend directly on GLPI session-array internals.');

$drilldownController = file_get_contents(dirname(__DIR__) . '/src/Controller/TicketDrilldownController.php');
$assert(str_contains($drilldownController, 'Profile::canView()'), 'Ticket drilldown must enforce the dashboard right.');
$assert(str_contains($drilldownController, 'activeEntityIds()'), 'Ticket drilldown must recheck entity scope.');

$dashboardTemplate = file_get_contents(dirname(__DIR__) . '/templates/dashboard/index.html.twig');
$assert(str_contains($dashboardTemplate, 'data-definition-endpoint'), 'Dashboard shell must expose the saved definition endpoint to the scoped app.');
$assert(str_contains($dashboardTemplate, 'data-ticket-search-url'), 'Dashboard shell must expose the GLPI-owned drilldown target.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure" . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'All analytics structural tests passed.' . PHP_EOL);
