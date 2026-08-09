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
$assert(count($definitions) === 43, 'The semantic layer must expose every controlled dashboard metric.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->source === 'live')) === 4, 'Only the four approved current-state products may query the operational source live.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->source === 'data_mart')) === 39, 'Historical and governed dimensional metrics must use Data Mart rollups.');

$tables = Schema::tables();
$assert(count($tables) === 12, 'Analytics schema must contain twelve plugin-owned tables.');
$assert(isset($tables['glpi_plugin_marifex_daily_matrix_rollups']), 'The approved priority and category matrix must use a bounded plugin-owned rollup.');
$assert(isset($tables['glpi_plugin_marifex_dashboard_provisions']), 'Dashboard release provisioning must be tracked per user and entity.');
$assert(isset($tables['glpi_plugin_marifex_report_schedules']), 'Phase 5 must persist governed report schedules.');
$assert(isset($tables['glpi_plugin_marifex_report_runs']), 'Phase 5 must retain immutable report execution history.');
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
$assert(str_contains($settings, 'HeadlessPdfRenderer'), 'Settings must expose Phase 5 PDF engine readiness.');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/setup.php'), "Hooks::CONFIG_PAGE]['marifex'] = 'Settings'"), 'Plugin must expose its native configuration action.');

$phase4Status = file_get_contents(dirname(__DIR__) . '/src/Metric/Phase4StatusService.php');
$assert(str_contains($phase4Status, '$domainLatest'), 'Zero-result dimension metrics must inherit the completed domain snapshot date instead of appearing missing.');

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
$assert(str_contains($snapshot, 'new TicketOperationsSnapshotBuilder()'), 'Daily snapshots must build the approved service-desk metrics.');
$assert(str_contains($snapshot, 'new DomainSnapshotBuilder()'), 'Daily snapshots must extend into Phase 4 domains.');

$ticketOperations = file_get_contents(dirname(__DIR__) . '/src/Etl/TicketOperationsSnapshotBuilder.php');
$assert(str_contains($ticketOperations, "'open_tickets_by_priority'"), 'Service-desk ETL must snapshot open tickets by priority.');
$assert(str_contains($ticketOperations, "'average_unassigned_time'"), 'Service-desk ETL must snapshot unassigned age.');
$assert(str_contains($ticketOperations, "'created_vs_resolved_tickets'"), 'Service-desk ETL must snapshot created versus resolved flow.');
$assert(str_contains($ticketOperations, "'assignment_changes_per_ticket'"), 'Service-desk ETL must use verified assignment events for reassignment frequency.');
$assert(str_contains($ticketOperations, "'resolution_time_age_bands'"), 'Service-desk ETL must snapshot resolution-time age bands.');
$assert(str_contains($ticketOperations, "'open_incidents_by_assignment_group'"), 'Service-desk ETL must snapshot current incident ownership.');
$assert(str_contains($ticketOperations, "'open_tickets_priority_category_matrix'"), 'Service-desk ETL must write the approved priority and category matrix.');

$domainSnapshot = file_get_contents(dirname(__DIR__) . '/src/Etl/DomainSnapshotBuilder.php');
$assert(str_contains($domainSnapshot, "'FROM' => 'glpi_computers'"), 'Phase 4 ETL must snapshot GLPI computers.');
$assert(str_contains($domainSnapshot, "'FROM' => 'glpi_softwarelicenses'"), 'Phase 4 ETL must snapshot software licence entitlements.');
$assert(str_contains($domainSnapshot, "'FROM' => 'glpi_items_softwarelicenses'"), 'Licence compliance must use explicit GLPI licence allocations.');
$assert(str_contains($domainSnapshot, "'glpi_changes', 'change'"), 'Phase 4 ETL must snapshot changes.');
$assert(str_contains($domainSnapshot, "'glpi_problems', 'problem'"), 'Phase 4 ETL must snapshot problems.');
$assert(str_contains($domainSnapshot, "'low_disk_capacity_computers'"), 'Phase 4 ETL must snapshot low-capacity computers.');
$assert(str_contains($domainSnapshot, "'prohibited_software_installations'"), 'Phase 4 ETL must snapshot explicitly invalid software installations.');
$assert(str_contains($domainSnapshot, "'unlicensed_software_installations'"), 'Phase 4 ETL must snapshot installations above recorded entitlement.');
$assert(str_contains($domainSnapshot, "'repeat_incident_computers'"), 'Phase 4 ETL must snapshot repeat-incident computers.');
$assert(str_contains($domainSnapshot, "'metric_key' => self::METRICS"), 'Phase 4 daily reruns must replace only the governed Phase 4 metric keys.');

$metricQueries = file_get_contents(dirname(__DIR__) . '/src/Metric/MetricQueryService.php');
$assert(str_contains($metricQueries, "'FROM' => 'glpi_entities'"), 'Duplicate group names must be disambiguated with their GLPI entity path.');
$assert(str_contains($metricQueries, "sprintf('%s — %s'"), 'Duplicate group labels must preserve distinct group IDs and show entity context.');
$assert(str_contains($metricQueries, "sprintf('%s · Group #%d'"), 'Same-name groups in the same entity must include their GLPI group ID.');

$settingsTemplate = file_get_contents(dirname(__DIR__) . '/templates/settings/index.html.twig');
$assert(str_contains($settingsTemplate, 'Certified analytics health'), 'Settings must display all certified metric health.');
$assert(
    str_contains($settingsTemplate, 'layout/page_without_tabs.html.twig'),
    'Settings must extend a layout provided by GLPI 11.'
);

$dashboardDefinition = file_get_contents(dirname(__DIR__) . '/src/Dashboard/DashboardDefinitionService.php');
$assert(str_contains($dashboardDefinition, 'MAX_WIDGETS = 40'), 'Saved dashboards must enforce the expanded but bounded widget count.');
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
$assert(str_contains($dashboardDefinition, 'provisionAlignedMetrics()'), 'Existing Executive dashboards must receive the approved aligned metric release once per user and entity.');
$assert(str_contains($dashboardDefinition, "'executive-priority'") && str_contains($dashboardDefinition, "'executive-low-disk'"), 'The Executive dashboard must include the newly approved service-desk and asset metrics.');
$assert(str_contains($dashboardDefinition, "['is_active'] = 0"), 'Phase 4 provisioning must preserve the currently active dashboard.');
$assert(str_contains($dashboardDefinition, "'software_license_compliance_rate' => ['kpi', 'line']"), 'Phase 4 widgets must remain behind the certified metric/type allowlist.');
$assert(str_contains($dashboardDefinition, 'premiumExecutiveWidgets()'), 'The Executive dashboard must use the controlled premium first-screen composition.');
$assert(str_contains($dashboardDefinition, "'operational_attention' => ['attention']"), 'The composite attention list must remain a certified presentation type.');
$assert(str_contains($dashboardDefinition, "'w' => 2, 'h' => 2"), 'The default Executive KPI strip must fit six compact KPIs in one desktop row.');
$premiumDefinition = substr($dashboardDefinition, (int) strpos($dashboardDefinition, 'private function premiumExecutiveWidgets'));
$assert(
    strpos($premiumDefinition, "'executive-sla-list'") < strpos($premiumDefinition, "'executive-group-incidents'")
    && strpos($premiumDefinition, "'executive-group-incidents'") < strpos($premiumDefinition, "'executive-unsatisfied'")
    && strpos($premiumDefinition, "'executive-unsatisfied'") < strpos($premiumDefinition, "'executive-asset-stale'")
    && strpos($premiumDefinition, "'executive-asset-stale'") < strpos($premiumDefinition, "'executive-prohibited-software'")
    && strpos($premiumDefinition, "'executive-prohibited-software'") < strpos($premiumDefinition, "'executive-change-open'"),
    'New Executive dashboards must preserve the approved below-fold section order.'
);

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
$assert(!str_contains($dashboardFrontend, 'layoutPositions'), 'Responsive widget placement must not combine fixed row coordinates with breakpoint width overrides.');
$assert(str_contains($dashboardFrontend, 'widget.x = x') && str_contains($dashboardFrontend, 'widget.y = y'), 'Header drag must record explicit 12-column canvas coordinates instead of only reordering the widget array.');
$assert(str_contains($dashboardDefinition, "\$validatedWidget['x']") && str_contains($dashboardDefinition, "\$validatedWidget['y']"), 'Saved dashboard validation must preserve bounded widget canvas coordinates.');
$assert(str_contains($widgetFrontend, 'widget.x === undefined') && str_contains($widgetFrontend, 'widget.y === undefined'), 'Widgets must render saved X/Y positions while retaining automatic placement for legacy definitions.');
$assert(str_contains($widgetFrontend, 'settingsOpen') && str_contains($widgetFrontend, 'marifex-widget--settings-open'), 'Widget settings must open as a non-sizing edit overlay.');
$assert(str_contains($dashboardFrontend, "'executive-sla-list': 'Service health'") && str_contains($dashboardFrontend, "'executive-change-open': 'Change and problem control'"), 'Executive widgets must expose all controlled below-fold section labels.');
$assert(str_contains($widgetFrontend, 'marifex-widget__section-label') && str_contains($dashboardCss, '.marifex-widget__section-label'), 'Section labels must render inside widget geometry so saved free-canvas positions remain stable.');
$assert(str_contains($dashboardFrontend, "metric: 'asset_inventory_total'"), 'Widget catalog must expose certified Phase 4 asset metrics.');
$assert(str_contains($dashboardFrontend, "metric: 'open_changes'"), 'Widget catalog must expose certified Phase 4 change metrics.');
$assert(str_contains($dashboardFrontend, "metric: 'open_problems'"), 'Widget catalog must expose certified Phase 4 problem metrics.');
$assert(str_contains($dashboardFrontend, 'v-if="hasGroupFilter"'), 'Ticket group filters must not appear on unrelated Phase 4 dashboards.');
$assert(str_contains($dashboardFrontend, '@change="persistFilters"'), 'Dashboard horizon and global filters must persist to the active saved dashboard.');
$assert(str_contains($dashboardFrontend, "exportUrl('pdf')"), 'Home dashboards must expose governed PDF export.');
$assert(str_contains($dashboardFrontend, "exportUrl('csv')"), 'Home dashboards must expose governed CSV export.');
$assert(str_contains($dashboardFrontend, 'Schedule dashboard report'), 'Home dashboards must expose scheduled delivery configuration.');
$assert(!str_contains($widgetFrontend, '>W {{ widget.w }}</button>') && !str_contains($widgetFrontend, '>H {{ widget.h }}</button>'), 'Builder must not expose developer-style W/H resize buttons.');
$assert(str_contains($widgetFrontend, 'ResizeObserver'), 'Charts must observe and adapt to widget size changes.');
$assert(str_contains($widgetFrontend, 'resizeTimer') && str_contains($widgetFrontend, '150'), 'Chart reflow must be debounced during pointer resizing.');
$assert(str_contains($widgetFrontend, 'donutGroups') && str_contains($widgetFrontend, "dimension: 'Other'"), 'Donuts must cap categories and aggregate the remainder as Other.');
$assert(str_contains($widgetFrontend, "widget.type === 'attention'") && str_contains($widgetFrontend, "widget.type === 'matrix'"), 'Controlled attention and matrix presentations must be rendered.');
$assert(str_contains($widgetFrontend, 'Color palette') && str_contains($widgetFrontend, "emit('palette'"), 'Every widget must expose a palette selector in edit mode.');
$assert(str_contains($dashboardFrontend, '@palette="recolorWidget"'), 'Per-widget palette changes must update the saved dashboard definition.');
$paletteFrontend = file_get_contents(dirname(__DIR__) . '/frontend/palettes.ts');
$assert(str_contains($paletteFrontend, "defaultWidgetPalette: WidgetPaletteKey = 'cream_gold'") && str_contains($paletteFrontend, "type: 'Gradient'"), 'Cream Gold must remain the default widget gradient palette.');
$assert(substr_count($paletteFrontend, "type: 'Gradient'") >= 13 && str_contains($paletteFrontend, "key: 'classic_blue'") && str_contains($paletteFrontend, "key: 'slate_gray'"), 'The complete approved gradient palette collection must be available to widgets.');
$assert(str_contains($dashboardCss, 'grid-auto-flow: row'), 'Dashboard rows must remain aligned instead of backfilling widgets into uneven masonry gaps.');
$assert(str_contains($dashboardCss, '.marifex-widget--palette-cream_gold') && str_contains($dashboardCss, '--mx-widget-bg-end'), 'Widget palettes must control non-white card backgrounds.');
$assert(str_contains($dashboardCss, 'container-type: size'), 'Widget content must scale against its own width and height.');
$assert(str_contains($dashboardCss, 'min(12cqw, 28cqh)'), 'KPI typography must respond to both widget width and height.');
$assert(str_contains($dashboardCss, 'box-shadow: none') && str_contains($dashboardCss, 'grid-auto-rows: 32px'), 'The premium canvas must remove normal shadows and use compact row units.');
$assert(str_contains($dashboardCss, 'align-content: start') && str_contains($dashboardCss, 'inset-block: 9rem 16px'), 'Widget settings must remain top-aligned and below GLPI sticky navigation.');
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
$assert(!str_contains($widgetCard, 'Analytics Data Mart'), 'Home widget headings must not expose the Analytics Data Mart implementation label.');
$definitionService = file_get_contents(dirname(__DIR__) . '/src/Dashboard/DashboardDefinitionService.php');
$assert(str_contains($definitionService, 'WIDGET_PALETTES') && str_contains($definitionService, "'palette' => \$palette"), 'The server must allowlist and persist widget palettes.');
$assert(str_contains($definitionService, "'classic_blue'") && str_contains($definitionService, "'slate_gray'"), 'The server palette allowlist must include the approved gradient collection.');

$reportExport = file_get_contents(dirname(__DIR__) . '/src/Report/ReportExporter.php');
$reportAuthorization = file_get_contents(dirname(__DIR__) . '/src/Report/ReportAuthorizationService.php');
$reportSchedule = file_get_contents(dirname(__DIR__) . '/src/Report/ReportScheduleService.php');
$reportRunner = file_get_contents(dirname(__DIR__) . '/src/Report/ScheduledReportRunner.php');
$csvRenderer = file_get_contents(dirname(__DIR__) . '/src/Report/CsvReportRenderer.php');
$pdfRenderer = file_get_contents(dirname(__DIR__) . '/src/Report/HeadlessPdfRenderer.php');
$htmlRenderer = file_get_contents(dirname(__DIR__) . '/src/Report/HtmlReportRenderer.php');
$assert(str_contains($reportExport, 'GLPI_PLUGIN_DOC_DIR') || str_contains(file_get_contents(dirname(__DIR__) . '/src/Report/ReportFileStore.php'), 'GLPI_PLUGIN_DOC_DIR'), 'Report files must remain in GLPI protected plugin storage.');
$assert(str_contains($reportAuthorization, 'RIGHT_EXPORT') && str_contains($reportAuthorization, 'RIGHT_SCHEDULE'), 'Scheduled execution must revalidate Phase 5 profile rights.');
$assert(str_contains($reportAuthorization, 'getSonsOf'), 'Scheduled report authorization must respect recursive entity access.');
$assert(str_contains($reportSchedule, 'Session::checkCSRF') || str_contains(file_get_contents(dirname(__DIR__) . '/src/Controller/ReportScheduleController.php'), 'Session::checkCSRF'), 'Report schedule writes must enforce GLPI CSRF validation.');
$assert(str_contains($reportRunner, 'validateRecipients'), 'Every scheduled delivery must revalidate recipients.');
$assert(str_contains($csvRenderer, "preg_match('/^[=+\\-@\\t\\r]/'"), 'CSV exports must neutralize spreadsheet formula injection.');
$assert(str_contains($pdfRenderer, '--headless=new') && str_contains($pdfRenderer, '--print-to-pdf='), 'PDF export must use the scoped headless-browser architecture.');
$assert(str_contains($pdfRenderer, '--no-pdf-header-footer'), 'Generated PDFs must not expose temporary renderer paths in browser headers or footers.');
$assert(str_contains($htmlRenderer, 'palette-cream_gold') && str_contains($htmlRenderer, 'PALETTES'), 'Static PDF reports must preserve per-widget palettes.');
$assert(str_contains($htmlRenderer, 'palette-classic_blue') && str_contains($htmlRenderer, 'palette-slate_gray'), 'Static PDF reports must render the approved gradient collection.');
$assert(str_contains($reportSchedule, 'new DateTimeZone($timezone)') && !str_contains($reportSchedule, '!in_array($timezone, DateTimeZone::listIdentifiers(), true)'), 'Schedules must accept valid IANA aliases reported by browsers.');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/hook.php'), "'scheduledReports'"), 'Phase 5 must register the scheduled report GLPI automatic action.');

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
