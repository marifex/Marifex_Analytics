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
$assert(count($definitions) === 19, 'The semantic layer must expose the four ticket metrics and fifteen Phase 4 metrics.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->source === 'live')) === 1, 'Only current open tickets may query the operational source live.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->source === 'data_mart')) === 18, 'Historical and Phase 4 metrics must use governed Data Mart rollups.');

$tables = Schema::tables();
$assert(count($tables) === 9, 'Analytics schema must contain nine plugin-owned tables.');
$assert(isset($tables['glpi_plugin_marifex_dashboard_provisions']), 'Dashboard release provisioning must be tracked per user and entity.');
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
$assert(str_contains($settings, 'Phase4StatusService'), 'Settings must expose Phase 4 metric health.');
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
$assert(str_contains($snapshot, 'new DomainSnapshotBuilder()'), 'Daily snapshots must extend into Phase 4 domains.');

$domainSnapshot = file_get_contents(dirname(__DIR__) . '/src/Etl/DomainSnapshotBuilder.php');
$assert(str_contains($domainSnapshot, "'FROM' => 'glpi_computers'"), 'Phase 4 ETL must snapshot GLPI computers.');
$assert(str_contains($domainSnapshot, "'FROM' => 'glpi_softwarelicenses'"), 'Phase 4 ETL must snapshot software licence entitlements.');
$assert(str_contains($domainSnapshot, "'FROM' => 'glpi_items_softwarelicenses'"), 'Licence compliance must use explicit GLPI licence allocations.');
$assert(str_contains($domainSnapshot, "'glpi_changes', 'change'"), 'Phase 4 ETL must snapshot changes.');
$assert(str_contains($domainSnapshot, "'glpi_problems', 'problem'"), 'Phase 4 ETL must snapshot problems.');
$assert(str_contains($domainSnapshot, "'metric_key' => self::METRICS"), 'Phase 4 daily reruns must replace only the governed Phase 4 metric keys.');

$metricQueries = file_get_contents(dirname(__DIR__) . '/src/Metric/MetricQueryService.php');
$assert(str_contains($metricQueries, "'FROM' => 'glpi_entities'"), 'Duplicate group names must be disambiguated with their GLPI entity path.');
$assert(str_contains($metricQueries, "sprintf('%s — %s'"), 'Duplicate group labels must preserve distinct group IDs and show entity context.');
$assert(str_contains($metricQueries, "sprintf('%s · Group #%d'"), 'Same-name groups in the same entity must include their GLPI group ID.');

$settingsTemplate = file_get_contents(dirname(__DIR__) . '/templates/settings/index.html.twig');
$assert(str_contains($settingsTemplate, 'Phase 4 domain analytics'), 'Settings must display certified Phase 4 metric health.');
$assert(
    str_contains($settingsTemplate, 'layout/page_without_tabs.html.twig'),
    'Settings must extend a layout provided by GLPI 11.'
);

$dashboardDefinition = file_get_contents(dirname(__DIR__) . '/src/Dashboard/DashboardDefinitionService.php');
$assert(str_contains($dashboardDefinition, 'count($widgets) > 24'), 'Saved dashboards must enforce a bounded widget count.');
$assert(str_contains($dashboardDefinition, 'MAX_DASHBOARDS = 20'), 'Dashboard builder must bound personal dashboards per entity.');
$assert(str_contains($dashboardDefinition, 'self::METRICS[$metric]'), 'Saved widgets must use the certified metric and type allowlist.');
$assert(!str_contains($dashboardDefinition, 'SELECT '), 'Dashboard definitions must not accept or build SQL.');
$assert(str_contains($dashboardDefinition, "'createFromTemplate'" ) || str_contains($dashboardDefinition, 'function createFromTemplate'), 'Dashboard builder must support curated templates.');
$assert(str_contains($dashboardDefinition, 'function duplicate'), 'Dashboard builder must support dashboard duplication.');
$assert(str_contains($dashboardDefinition, 'ownershipWhere()'), 'Dashboard mutations must be restricted by user and active entity.');
$assert(str_contains($dashboardDefinition, "'refreshMinutes'"), 'Dashboard definitions must persist bounded auto-refresh settings.');
$assert(str_contains($dashboardDefinition, "'filters' => ['groupId'"), 'Dashboard definitions must persist the base group filter.');
$assert(str_contains($dashboardDefinition, "'asset-governance'"), 'Dashboard builder must provide the Phase 4 asset and licence template.');
$assert(str_contains($dashboardDefinition, "'change-control'"), 'Dashboard builder must provide the Phase 4 change template.');
$assert(str_contains($dashboardDefinition, "'problem-control'"), 'Dashboard builder must provide the Phase 4 problem template.');
$assert(str_contains($dashboardDefinition, 'provisionPhase4Dashboards()'), 'Phase 4 dashboards must be provisioned into Home once per user and entity.');
$assert(str_contains($dashboardDefinition, 'provisionPhase4Executive()'), 'The existing Executive dashboard must receive a cross-domain Phase 4 summary once per user and entity.');
$assert(str_contains($dashboardDefinition, "'executive-change-open'"), 'The Executive dashboard must include Change analytics.');
$assert(str_contains($dashboardDefinition, "'executive-problem-open'"), 'The Executive dashboard must include Problem analytics.');
$assert(str_contains($dashboardDefinition, "'executive-asset-total'"), 'The Executive dashboard must include Asset analytics.');
$assert(str_contains($dashboardDefinition, "['is_active'] = 0"), 'Phase 4 provisioning must preserve the currently active dashboard.');
$assert(str_contains($dashboardDefinition, "'software_license_compliance_rate' => ['kpi', 'line']"), 'Phase 4 widgets must remain behind the certified metric/type allowlist.');

$definitionController = file_get_contents(dirname(__DIR__) . '/src/Controller/DashboardDefinitionController.php');
$assert(str_contains($definitionController, 'Profile::canView()'), 'Dashboard definition API must enforce the plugin profile right.');
$assert(str_contains($definitionController, 'Session::checkCSRF'), 'Dashboard definition writes must enforce GLPI CSRF protection.');
$assert(str_contains($definitionController, "methods: ['GET', 'POST', 'PUT', 'DELETE']"), 'Dashboard definition API must expose bounded builder operations.');
$assert(str_contains($definitionController, "'activate' =>"), 'Dashboard API must expose explicit active-dashboard selection.');
$assert(str_contains($definitionController, "'duplicate' =>"), 'Dashboard API must expose dashboard duplication.');

$dashboardFrontend = file_get_contents(dirname(__DIR__) . '/frontend/Dashboard.vue');
$widgetFrontend = file_get_contents(dirname(__DIR__) . '/frontend/WidgetCard.vue');
$dashboardCss = file_get_contents(dirname(__DIR__) . '/frontend/dashboard.css');
$assert(str_contains($dashboardFrontend, "'X-Requested-With': 'XMLHttpRequest'"), 'Dashboard writes must opt into GLPI AJAX CSRF validation.');
$assert(str_contains($dashboardFrontend, "'X-Glpi-Csrf-Token': props.csrfToken"), 'Dashboard writes must send the GLPI CSRF header.');
$assert(str_contains($dashboardFrontend, 'Create from template'), 'Builder must expose dashboard templates.');
$assert(str_contains($dashboardFrontend, 'duplicateDashboard'), 'Builder must expose dashboard duplication.');
$assert(str_contains($dashboardFrontend, 'cancelEditing'), 'Builder must preserve draft/cancel behavior.');
$assert(str_contains($dashboardFrontend, "mode: 'drag' | 'resize'"), 'Builder must provide pointer-driven drag and resize interactions.');
$assert(str_contains($dashboardFrontend, 'layoutPositions'), 'Dashboard canvas must place widgets in deterministic aligned rows.');
$assert(str_contains($dashboardFrontend, "metric: 'asset_inventory_total'"), 'Widget catalog must expose certified Phase 4 asset metrics.');
$assert(str_contains($dashboardFrontend, "metric: 'open_changes'"), 'Widget catalog must expose certified Phase 4 change metrics.');
$assert(str_contains($dashboardFrontend, "metric: 'open_problems'"), 'Widget catalog must expose certified Phase 4 problem metrics.');
$assert(str_contains($dashboardFrontend, 'v-if="hasGroupFilter"'), 'Ticket group filters must not appear on unrelated Phase 4 dashboards.');
$assert(str_contains($dashboardFrontend, '@change="persistFilters"'), 'Dashboard horizon and global filters must persist to the active saved dashboard.');
$assert(!str_contains($widgetFrontend, '>W {{ widget.w }}</button>') && !str_contains($widgetFrontend, '>H {{ widget.h }}</button>'), 'Builder must not expose developer-style W/H resize buttons.');
$assert(str_contains($widgetFrontend, 'ResizeObserver'), 'Charts must observe and adapt to widget size changes.');
$assert(str_contains($dashboardCss, 'grid-auto-flow: row'), 'Dashboard rows must remain aligned instead of backfilling widgets into uneven masonry gaps.');
$assert(str_contains($dashboardCss, 'container-type: size'), 'Widget content must scale against its own width and height.');
$assert(str_contains($dashboardCss, 'min(12cqw, 28cqh)'), 'KPI typography must respond to both widget width and height.');
$dashboardBootstrap = file_get_contents(dirname(__DIR__) . '/frontend/main.ts');
$assert(str_contains($dashboardBootstrap, "meta[property=\"glpi:csrf_token\"]"), 'Dashboard bootstrap must fall back to GLPI core CSRF metadata.');
$assert(str_contains($dashboardBootstrap, 'MutationObserver'), 'Dashboard app must mount when GLPI loads the Home tab asynchronously.');

$homeDashboard = file_get_contents(dirname(__DIR__) . '/src/HomeDashboardTab.php');
$assert(str_contains($homeDashboard, 'instanceof Central'), 'Analytics must be registered as an additional GLPI Home tab.');
$assert(str_contains($homeDashboard, "@marifex/dashboard/embed.html.twig"), 'Home tab must render the scoped dashboard application.');
$setup = file_get_contents(dirname(__DIR__) . '/setup.php');
$assert(str_contains($setup, "['addtabon' => \\Central::class]"), 'Plugin must register its Analytics tab on GLPI Home.');
$dashboardController = file_get_contents(dirname(__DIR__) . '/src/Controller/DashboardController.php');
$assert(str_contains($dashboardController, '/front/central.php?forcetab='), 'Legacy dashboard route must redirect to the Home Analytics tab.');

$widgetCard = file_get_contents(dirname(__DIR__) . '/frontend/WidgetCard.vue');
$assert(!str_contains($widgetCard, "type: 'scroll'"), 'Dashboard legends must never use paginated scrolling.');
$assert(str_contains($widgetCard, 'marifex-donut-layout'), 'Donut widgets must use a fixed chart-left and legend-right layout.');
$assert(str_contains($widgetCard, "software_license_compliance_rate"), 'Licence compliance KPIs must render as percentages.');
$assert(str_contains($widgetCard, 'props.assetSearchUrl'), 'Asset widgets must drill down to native GLPI computer lists.');
$assert(str_contains($widgetCard, 'props.changeSearchUrl'), 'Change widgets must drill down to native GLPI change lists.');
$assert(str_contains($widgetCard, 'props.problemSearchUrl'), 'Problem widgets must drill down to native GLPI problem lists.');

$entityScope = file_get_contents(dirname(__DIR__) . '/src/Security/EntityScope.php');
$assert(str_contains($entityScope, 'Session::getActiveEntities()'), 'Entity scope must use the supported GLPI Session adapter.');
$assert(!str_contains($entityScope, "\$_SESSION["), 'Entity scope must not depend directly on GLPI session-array internals.');

$drilldownController = file_get_contents(dirname(__DIR__) . '/src/Controller/TicketDrilldownController.php');
$assert(str_contains($drilldownController, 'Profile::canView()'), 'Ticket drilldown must enforce the dashboard right.');
$assert(str_contains($drilldownController, 'activeEntityIds()'), 'Ticket drilldown must recheck entity scope.');

$dashboardEmbed = file_get_contents(dirname(__DIR__) . '/templates/dashboard/embed.html.twig');
$assert(str_contains($dashboardEmbed, 'data-definition-endpoint'), 'Dashboard shell must expose the saved definition endpoint to the scoped app.');
$assert(str_contains($dashboardEmbed, 'data-ticket-search-url'), 'Dashboard shell must expose the GLPI-owned drilldown target.');
$assert(str_contains($dashboardEmbed, 'data-asset-search-url'), 'Dashboard shell must expose the native asset drilldown target.');
$assert(str_contains($dashboardEmbed, 'data-licence-search-url'), 'Dashboard shell must expose the native licence drilldown target.');
$assert(str_contains($dashboardEmbed, 'data-change-search-url'), 'Dashboard shell must expose the native change drilldown target.');
$assert(str_contains($dashboardEmbed, 'data-problem-search-url'), 'Dashboard shell must expose the native problem drilldown target.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure" . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'All analytics structural tests passed.' . PHP_EOL);
