<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GlpiPlugin\Marifex\Analytics\ActivationEvaluator;
use GlpiPlugin\Marifex\Analytics\ActivationState;
use GlpiPlugin\Marifex\Analytics\MonitoringBaselineRepository;
use GlpiPlugin\Marifex\Analytics\MonitoringScope;
use GlpiPlugin\Marifex\Analytics\Provenance;
use GlpiPlugin\Marifex\Analytics\ProvenanceEvidence;
use GlpiPlugin\Marifex\Install\Schema;
use GlpiPlugin\Marifex\Insight\InsightCalculator;
use GlpiPlugin\Marifex\Insight\InsightDomainRegistry;
use GlpiPlugin\Marifex\Insight\InsightRuleRegistry;
use GlpiPlugin\Marifex\Metric\MetricRegistry;
use GlpiPlugin\Marifex\Report\CsvReportRenderer;
use GlpiPlugin\Marifex\Report\HtmlReportRenderer;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$definitions = (new MetricRegistry())->all();
$assert(array_keys(InsightRuleRegistry::rules()) === array_keys(InsightRuleRegistry::formulas()), 'Every controlled analytical rule must resolve exactly one server-owned certified formula under the active formula version.');
$assert(array_keys(InsightRuleRegistry::rules()) === array_keys(InsightRuleRegistry::sources()), 'Every controlled analytical rule must declare its certified source lineage for activation, provenance and export evidence.');
$assert(count($definitions) === 62, 'The semantic layer must expose every controlled dashboard metric through Phase 5B.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->source === 'live')) === 4, 'Only the four approved current-state products may query the operational source live.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->source === 'data_mart')) === 58, 'Historical and governed dimensional metrics must use certified Data Mart products.');
$assert(count(array_filter($definitions, static fn ($definition): bool => $definition->provenance === Provenance::OBSERVED)) === count($definitions), 'Production metric inputs must default to OBSERVED provenance while historical bootstrap remains unapproved.');

$observed = ProvenanceEvidence::observed();
$bootstrap = ProvenanceEvidence::certifiedBootstrap();
$derivedObserved = ProvenanceEvidence::derived($observed, $observed);
$derivedMixed = ProvenanceEvidence::derived($observed, $bootstrap);
$assert($derivedObserved->provenance === Provenance::DERIVED && $derivedObserved->effectiveProvenance === Provenance::OBSERVED, 'A derived result from observed inputs must retain DERIVED structure and OBSERVED effective provenance.');
$assert($derivedMixed->provenance === Provenance::DERIVED && $derivedMixed->effectiveProvenance === Provenance::CERTIFIED_BOOTSTRAP, 'A derived result must inherit the weakest certified provenance recursively.');
$assert(InsightDomainRegistry::forWidgets([['metric' => 'asset_inventory_total'], ['metric' => 'software_license_compliance_rate']]) === ['asset', 'licence'], 'Asset and licence report insights must use the same governed domain context as the screen.');
$assert(InsightDomainRegistry::forWidgets([['metric' => 'daily_change_volume']]) === ['change'], 'Change report insights must use the same governed domain context as the screen.');
$assert(InsightDomainRegistry::forWidgets([['metric' => 'historical_open_backlog'], ['metric' => 'asset_inventory_total']]) === [], 'A ticket-bearing dashboard must retain the controlled Executive insight context across outputs.');
$uncertifiedRejected = false;
try {
    ProvenanceEvidence::derived($observed, ProvenanceEvidence::uncertifiedReconstruction());
} catch (DomainException) {
    $uncertifiedRejected = true;
}
$assert($uncertifiedRejected, 'UNCERTIFIED_RECONSTRUCTION must be rejected by the calculation layer.');

$activationEvaluator = new ActivationEvaluator();
$activationCutoff = new DateTimeImmutable('2026-03-31');
$sixtyDates = array_map(static fn (int $offset): string => $activationCutoff->modify('-' . $offset . ' days')->format('Y-m-d'), range(0, 59));
$thirtyDates = array_slice($sixtyDates, 0, 30);
$certifiedComparison = $activationEvaluator->evaluate($activationCutoff, 30, $sixtyDates, 'current', true, $observed, new DateTimeImmutable('2026-02-01'), true);
$comparableWindow = $activationEvaluator->evaluate($activationCutoff, 30, $thirtyDates, 'current', true, $observed, new DateTimeImmutable('2026-02-01'), true);
$observedMovement = $activationEvaluator->evaluate($activationCutoff, 30, [$activationCutoff->format('Y-m-d')], 'current', true, $observed, new DateTimeImmutable('2026-03-01'), true);
$currentState = $activationEvaluator->evaluate($activationCutoff, 30, [$activationCutoff->format('Y-m-d')], 'current', true, $observed);
$boundaryLimited = $activationEvaluator->evaluate($activationCutoff, 30, $sixtyDates, 'current', true, $observed, new DateTimeImmutable('2026-02-01'), true, false);
$ineligible = $activationEvaluator->evaluate($activationCutoff, 30, $sixtyDates, 'current', true, ProvenanceEvidence::uncertifiedReconstruction());
$staleActivation = $activationEvaluator->evaluate($activationCutoff, 30, $sixtyDates, 'stale', true, $observed);
$assert($certifiedComparison->state === ActivationState::CERTIFIED_PERIOD_COMPARISON && $certifiedComparison->comparisonBasis === 'vs prior 30 days', 'Two consecutive horizons must activate the certified period comparison.');
$assert($comparableWindow->state === ActivationState::COMPARABLE_WINDOW && $comparableWindow->comparisonBasis === 'Current 30-day window', 'One consecutive horizon must activate only the comparable current window.');
$assert($observedMovement->state === ActivationState::OBSERVED_MOVEMENT && $observedMovement->comparisonBasis === 'Since monitoring began', 'A preserved baseline and later observation must activate factual observed movement.');
$assert($currentState->state === ActivationState::CURRENT_STATE, 'Current certified evidence without historical gates must remain current-state analytics.');
$assert($boundaryLimited->state === ActivationState::COMPARABLE_WINDOW, 'Missing endpoint boundary evidence must prevent only certified period comparison promotion.');
$assert($ineligible->state === null && $ineligible->suppressionCode === 'UNAVAILABLE_SOURCE', 'Uncertified evidence must not activate any certified analytical state and must use a governed suppression code.');
$assert($staleActivation->state === null && $staleActivation->suppressionCode === 'STALE_SOURCE', 'Activation failures must preserve the governed source-state suppression vocabulary.');
foreach ([7, 30, 90, 180, 365] as $supportedHorizon) {
    $dates = array_map(static fn (int $offset): string => $activationCutoff->modify('-' . $offset . ' days')->format('Y-m-d'), range(0, 2 * $supportedHorizon - 1));
    $decision = $activationEvaluator->evaluate($activationCutoff, $supportedHorizon, $dates, 'current', true, $observed);
    $assert($decision->state === ActivationState::CERTIFIED_PERIOD_COMPARISON && $decision->requiredDays === 2 * $supportedHorizon, sprintf('%d-day readiness must require exactly two consecutive selected horizons.', $supportedHorizon));
}
$switchedHorizon = $activationEvaluator->evaluate($activationCutoff, 90, $sixtyDates, 'current', true, $observed);
$retentionWithoutEvidence = $activationEvaluator->evaluate($activationCutoff, 30, [$activationCutoff->format('Y-m-d')], 'current', true, $observed, new DateTimeImmutable('2026-03-01'), false);
$assert($switchedHorizon->state === ActivationState::CURRENT_STATE, 'Switching to an unready horizon must immediately remove the prior horizon comparison.');
$assert($retentionWithoutEvidence->state === ActivationState::CURRENT_STATE && $retentionWithoutEvidence->suppressionCode === 'INSUFFICIENT_HISTORY', 'A preserved baseline identity without its certified evidence must suppress observed movement as INSUFFICIENT_HISTORY rather than moving the baseline.');
$certifiedZeroPayload = (new InsightCalculator())->calculate([
    'historical_open_backlog' => [
        'completed_dates' => [$activationCutoff->format('Y-m-d')],
        'current_observation_complete' => true,
        'series' => [],
    ],
], [
    'historical_open_backlog' => ['state' => 'current', 'provenance' => 'OBSERVED'],
], $activationCutoff, 30);
$certifiedZeroReadiness = array_column($certifiedZeroPayload['readiness']['metrics'], null, 'metric');
$assert(($certifiedZeroReadiness['historical_open_backlog']['activation_state'] ?? null) === ActivationState::CURRENT_STATE->value, 'A completed zero observation must activate current-state analytics even when no rollup row exists.');
$scopeA = new MonitoringScope(3, true, [5, 3, 4], 12, 'historical_open_backlog', 'scalar');
$scopeB = new MonitoringScope(3, true, [4, 5, 3], 12, 'historical_open_backlog', 'scalar');
$scopeOtherFilter = new MonitoringScope(3, true, [3, 4, 5], 13, 'historical_open_backlog', 'scalar');
$scopeNonRecursive = new MonitoringScope(3, false, [3], 12, 'historical_open_backlog', 'scalar');
$scopeOtherRoot = new MonitoringScope(4, true, [4, 5], 12, 'historical_open_backlog', 'scalar');
$assert($scopeA->fingerprint() === $scopeB->fingerprint(), 'Monitoring-baseline identity must be stable regardless of entity-list ordering.');
$assert($scopeA->fingerprint() !== $scopeOtherFilter->fingerprint(), 'Monitoring baselines must never be reused across supported group filters.');
$assert($scopeA->fingerprint() !== $scopeNonRecursive->fingerprint() && $scopeA->fingerprint() !== $scopeOtherRoot->fingerprint(), 'Monitoring baselines must remain isolated by root entity, recursive setting and exact authorized entity set.');
$schema = Schema::tables();
$baselineSchema = $schema['glpi_plugin_marifex_monitoring_baselines'] ?? '';
$assert(str_contains($baselineSchema, '`scope_fingerprint`') && str_contains($baselineSchema, '`monitoring_baseline_at`') && str_contains($baselineSchema, '`evidence_hash`'), 'The stable monitoring baseline must preserve immutable scope identity, date and certified evidence integrity independently of rollup retention.');
$snapshotBuilder = file_get_contents(dirname(__DIR__) . '/src/Etl/SnapshotBuilder.php');
$insightServiceSource = file_get_contents(dirname(__DIR__) . '/src/Insight/InsightService.php');
$baselineRepositorySource = file_get_contents(dirname(__DIR__) . '/src/Analytics/MonitoringBaselineRepository.php');
$assert(str_contains($snapshotBuilder, 'MonitoringBaselineCollector') && !str_contains($insightServiceSource, 'establishIfAbsent'), 'Monitoring baselines must be established by collection, never by dashboard query time.');
$assert(str_contains($baselineRepositorySource, 'canonicalEvidenceJson') && str_contains($baselineRepositorySource, 'integrityValid'), 'Baseline integrity must use deterministic semantic JSON hashing that survives database JSON key normalization.');
$canonicalFloatHash = MonitoringBaselineRepository::evidenceHash(['format' => 'duration_series', 'value' => 200758.12499062505, 'sample_count' => 32]);
$assert($canonicalFloatHash === MonitoringBaselineRepository::evidenceHash(['sample_count' => 32, 'value' => 200758.12499062504, 'format' => 'duration_series']), 'Baseline evidence hashing must normalize key order and sub-micro-unit floating-point noise.');
$assert(!str_contains($snapshotBuilder, "delete('glpi_plugin_marifex_monitoring_baselines'") && str_contains(file_get_contents(dirname(__DIR__) . '/src/Install/Installer.php'), 'monitoring_baselines_established') && str_contains($snapshotBuilder, 'monitoring_baselines_established'), 'Daily retention/reruns must not advance stable baselines and both upgrade and newly collected baseline establishment must leave audit evidence.');
$observedMovementPayload = (new InsightCalculator())->calculate([
    'historical_open_backlog' => [
        'label' => 'Historical open backlog',
        'completed_dates' => ['2026-03-01', '2026-03-31'],
        'series' => [['date' => '2026-03-01', 'value' => 100], ['date' => '2026-03-31', 'value' => 112]],
        'monitoring_baseline' => ['monitoring_baseline_at' => '2026-03-01', 'evidence' => ['value' => 100]],
    ],
], [
    'historical_open_backlog' => ['state' => 'current', 'provenance' => 'OBSERVED', 'effective_provenance' => 'OBSERVED'],
], new DateTimeImmutable('2026-03-31'), 30);
$observedItem = $observedMovementPayload['observed_movements'][0] ?? [];
$assert(($observedItem['absolute_change'] ?? null) === 12.0 && ($observedItem['comparison_basis'] ?? '') === 'Since monitoring began', 'Observed movement must be an absolute delta from the stable monitoring baseline with an explicit comparison basis.');
$assert(($observedItem['materiality_eligible'] ?? true) === false && ($observedItem['executive_insight_eligible'] ?? true) === false && $observedMovementPayload['insights'] === [], 'Observed movement must never enter materiality or the Executive insight brief.');
$uncertifiedPayload = (new InsightCalculator())->calculate([
    'historical_open_backlog' => ['completed_dates' => $sixtyDates, 'series' => array_map(static fn(string $date): array => ['date' => $date, 'value' => 100], $sixtyDates)],
], [
    'historical_open_backlog' => ['state' => 'current', 'provenance' => 'UNCERTIFIED_RECONSTRUCTION'],
], $activationCutoff, 30);
$uncertifiedSuppression = array_column($uncertifiedPayload['suppressed'], null, 'key');
$assert(($uncertifiedSuppression['backlog_growth_rate']['code'] ?? '') === 'UNAVAILABLE_SOURCE' && $uncertifiedPayload['insights'] === [], 'Uncertified reconstruction must be rejected before materiality and insight generation using the governed source-unavailable suppression.');
$assert(($uncertifiedSuppression['backlog_growth_rate']['effective_provenance'] ?? '') === 'UNCERTIFIED_RECONSTRUCTION' && ($uncertifiedSuppression['backlog_growth_rate']['materiality_outcome'] ?? '') === 'suppressed:UNAVAILABLE_SOURCE', 'Suppressed calculations must retain their activation, provenance, formula and materiality outcome as governed evidence.');
$metricQuery = file_get_contents(dirname(__DIR__) . '/src/Metric/MetricQueryService.php');
$assert(str_contains($metricQuery, "['breached_count']") && str_contains($metricQuery, "['approaching_count']"), 'Operational attention must use complete SLA counts rather than the truncated detail rows.');

$insightSeries = ['created_vs_resolved_tickets' => ['series' => []], 'historical_open_backlog' => ['series' => []], 'historical_group_backlog' => ['series' => []]];
$fixtureStart = new DateTimeImmutable('2026-01-01');
for ($day = 0; $day < 15; $day++) {
    $date = $fixtureStart->modify('+' . $day . ' days')->format('Y-m-d');
    $currentPeriod = $day >= 8;
    $insightSeries['created_vs_resolved_tickets']['series'][] = ['date' => $date, 'dimension_id' => 1, 'dimension' => 'Created', 'value' => $currentPeriod ? 20 : 10];
    $insightSeries['created_vs_resolved_tickets']['series'][] = ['date' => $date, 'dimension_id' => 2, 'dimension' => 'Resolved', 'value' => $currentPeriod ? 10 : 9];
    $backlog = $day === 14 ? 140 : ($day >= 7 ? 110 : 100);
    $insightSeries['historical_open_backlog']['series'][] = ['date' => $date, 'value' => $backlog];
    foreach (($day >= 8 ? [1 => 60, 2 => 20, 3 => 20] : [1 => 40, 2 => 30, 3 => 30]) as $id => $value) {
        $insightSeries['historical_group_backlog']['series'][] = ['date' => $date, 'dimension_id' => $id, 'dimension' => 'Group ' . $id, 'value' => $value];
    }
}
$calculatedInsights = (new InsightCalculator())->calculate($insightSeries, [
    'created_vs_resolved_tickets' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
    'historical_open_backlog' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
    'historical_group_backlog' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
], new DateTimeImmutable('2026-01-15'), 7);
$insightsByKey = array_column($calculatedInsights['insights'], null, 'key');
$assert($calculatedInsights['formula_version'] === 'phase5a-1+phase5b-1' && $calculatedInsights['formula_versions'] === ['phase5a-1', 'phase5b-1'], 'Combined output must identify both approved formula sets without relabelling Phase 5A calculations as Phase 5B.');
$assert(InsightRuleRegistry::formulaVersion('net_ticket_flow') === 'phase5a-1' && InsightRuleRegistry::formulaVersion('ticket_reopen_count_movement') === 'phase5b-1', 'Each calculation must retain the formula-set identifier of its owning approved phase.');
$assert(($insightsByKey['net_ticket_flow']['calculation']['formula_version'] ?? null) === 'phase5a-1', 'A rendered or exported Phase 5A finding must retain phase5a-1 calculation evidence.');
$assert(($insightsByKey['net_ticket_flow']['current'] ?? null) === 70.0 && ($insightsByKey['net_ticket_flow']['previous'] ?? null) === 7.0, 'Net ticket flow must compare equal seven-day periods.');
$assert(($insightsByKey['resolution_coverage']['current'] ?? null) === 50.0 && ($insightsByKey['resolution_coverage']['previous'] ?? null) === 90.0, 'Resolution coverage must use resolved divided by created for each equal period.');
$assert(abs((float) ($insightsByKey['backlog_growth_rate']['current'] ?? 0) - 27.3) < 0.01, 'Backlog growth must use certified period boundary snapshots.');
$assert(count($calculatedInsights['insights']) <= 5, 'The Executive brief must never expose more than five findings.');
$assert(($calculatedInsights['indicators'][0]['label'] ?? '') === 'Majority concentration', 'The fixed informational majority rule must identify a greater-than-50-percent share across at least three groups.');
$completedFixtureDates = array_map(static fn(int $day): string => $fixtureStart->modify('+' . $day . ' days')->format('Y-m-d'), range(1, 14));
$lowVolumeInsights = (new InsightCalculator())->calculate([
    'unassigned_open_tickets' => ['completed_dates' => $completedFixtureDates, 'series' => [['date' => '2026-01-08', 'value' => 1], ['date' => '2026-01-15', 'value' => 2]]],
    'historical_open_backlog' => ['completed_dates' => $completedFixtureDates, 'series' => [['date' => '2026-01-01', 'value' => 4], ['date' => '2026-01-08', 'value' => 4], ['date' => '2026-01-15', 'value' => 4]]],
], [
    'unassigned_open_tickets' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
    'historical_open_backlog' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
], new DateTimeImmutable('2026-01-15'), 7);
$suppressionByKey = array_column($lowVolumeInsights['suppressed'], null, 'key');
$assert(($suppressionByKey['unassigned_rate']['code'] ?? '') === 'DENOMINATOR_BELOW_MINIMUM', 'A below-floor ratio must be suppressed and must never be rendered as zero.');

$phase5bDates = array_map(static fn(int $day): string => (new DateTimeImmutable('2026-01-01'))->modify('+' . $day . ' days')->format('Y-m-d'), range(1, 14));
$reopenSeries = ['completed_dates' => $phase5bDates, 'series' => []];
$resolutionSeries = ['completed_dates' => $phase5bDates, 'series' => []];
foreach ($phase5bDates as $index => $date) {
    $reopenSeries['series'][] = ['date' => $date, 'value' => $index >= 7 ? 2 : 1];
    $resolutionSeries['series'][] = ['date' => $date, 'value' => 10];
}
$phase5bReopen = (new InsightCalculator())->calculate([
    'ticket_reopen_events' => $reopenSeries,
    'ticket_resolution_events' => $resolutionSeries,
], [
    'ticket_reopen_events' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
    'ticket_resolution_events' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00'],
], new DateTimeImmutable('2026-01-15'), 7);
$phase5bReopenByKey = array_column($phase5bReopen['insights'], null, 'key');
$assert(($phase5bReopenByKey['ticket_reopen_rate_movement']['current'] ?? null) === 20.0 && ($phase5bReopenByKey['ticket_reopen_rate_movement']['previous'] ?? null) === 10.0, 'Reopen event rate must divide reopen events by resolution events without clamping.');

$fixedThirtyDayDates = array_map(static fn(int $offset): string => (new DateTimeImmutable('2026-01-15'))->modify('-' . $offset . ' days')->format('Y-m-d'), range(0, 59));
$phase5bPercentile = (new InsightCalculator())->calculate([
    'first_response_p90_seconds' => ['completed_dates' => $fixedThirtyDayDates, 'series' => [
        ['date' => '2025-12-16', 'value' => 3600, 'sample_count' => 25],
        ['date' => '2026-01-15', 'value' => 7200, 'sample_count' => 25],
    ]],
], ['first_response_p90_seconds' => ['state' => 'current', 'completed_at' => '2026-01-16 01:00:00']], new DateTimeImmutable('2026-01-15'), 7);
$phase5bPercentileByKey = array_column($phase5bPercentile['insights'], null, 'key');
$assert(($phase5bPercentileByKey['first_response_p90_movement']['current'] ?? null) === 7200.0, 'First-response percentiles must compare fixed 30-day cutoff populations with their observation counts.');

$tables = Schema::tables();
$assert(count($tables) === 18, 'Analytics schema must contain all eighteen plugin-owned tables including stable baselines and certified observation completion evidence.');
$assert(isset($tables['glpi_plugin_marifex_daily_matrix_rollups']), 'The approved priority and category matrix must use a bounded plugin-owned rollup.');
$assert(isset($tables['glpi_plugin_marifex_dashboard_provisions']), 'Dashboard release provisioning must be tracked per user and entity.');
$assert(isset($tables['glpi_plugin_marifex_report_schedules']), 'Phase 5 must persist governed report schedules.');
$assert(isset($tables['glpi_plugin_marifex_report_runs']), 'Phase 5 must retain immutable report execution history.');
$assert(isset($tables['glpi_plugin_marifex_analytical_audit']), 'Phase 5A must audit controlled configuration and formula-scope changes.');
$assert(isset($tables['glpi_plugin_marifex_daily_response_observations']), 'Phase 5B percentiles must retain entity-scoped raw response observations.');
$assert(isset($tables['glpi_plugin_marifex_daily_licence_title_observations']), 'Phase 5B licence ratios must retain title facts so recursive scope remains distinct.');
foreach ($tables as $name => $sql) {
    $assert(str_starts_with($name, 'glpi_plugin_marifex_'), "Unexpected table name: $name");
    $assert(!str_contains($sql, 'glpi_tickets` ('), 'Schema must not modify the operational ticket table.');
    $assert(!str_contains(strtoupper($sql), 'DATETIME'), 'GLPI 11 plugin tables must use TIMESTAMP instead of DATETIME.');
}

$controller = file_get_contents(dirname(__DIR__) . '/src/Controller/MetricController.php');
$assert(!str_contains($controller, 'SELECT '), 'Metric controller must not accept or build SQL.');
$assert(str_contains($controller, 'Profile::canView()'), 'Metric controller must enforce the plugin profile right.');
$insightController = file_get_contents(dirname(__DIR__) . '/src/Controller/InsightController.php');
$assert(str_contains($insightController, "Route('/api/insights'") && str_contains($insightController, 'Profile::canView()'), 'Phase 5A insights must use a permission-checked bounded API.');
$assert(str_contains($insightServiceSource, 'assertAuthorizedGroup') && str_contains($insightServiceSource, "'entities_id' => \$this->entityScope->activeEntityIds()"), 'Insight group filters must be rejected unless the selected group belongs to the active authorized entity scope.');
$assert(str_contains($insightController, "query->get('domains'") && str_contains($insightController, 'InsightService())->build($horizon'), 'Module dashboards must pass the requested analytical domains through the permission-checked API.');

$settings = file_get_contents(dirname(__DIR__) . '/src/Controller/SettingsController.php');
$assert(str_contains($settings, 'Profile::canAdminister()'), 'Settings controller must enforce the plugin admin right.');
$assert(str_contains($settings, 'Phase4StatusService'), 'Settings must expose Phase 4 metric health.');
$assert(str_contains($settings, 'HeadlessPdfRenderer'), 'Settings must expose Phase 5 PDF engine readiness.');
$assert(str_contains($settings, 'InsightService'), 'Settings must expose factual Phase 5A comparison readiness.');
$assert(str_contains($settings, 'AnalyticalAuditService'), 'Settings changes must create a bounded Phase 5A audit record.');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/setup.php'), "Hooks::CONFIG_PAGE]['marifex'] = 'Settings'"), 'Plugin must expose its native configuration action.');
$settingsTemplate = file_get_contents(dirname(__DIR__) . '/templates/settings/index.html.twig');
$assert(str_contains($settingsTemplate, 'Phase 5 analytical readiness') && !str_contains($settingsTemplate, 'Phase 5A analytical readiness'), 'Settings must label the combined Phase 5A/5B readiness surface consistently.');

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
$assert(str_contains($dashboardDefinition, 'provisionPremiumSectionOrder()'), 'Legacy auto-layout Executive dashboards must receive the approved section order once.');
$assert(str_contains($dashboardDefinition, 'hasExplicitCanvasPosition') && str_contains($dashboardDefinition, "isset(\$widget['x'], \$widget['y'])"), 'Section-order provisioning must preserve dashboards with explicit free-canvas coordinates.');
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
$releasePackager = file_get_contents(dirname(__DIR__) . '/tools/package_release.ps1');
$assert(str_contains($releasePackager, ".Replace('\\', '/')"), 'Release archives must normalize Windows paths to POSIX ZIP entry separators.');
$assert(str_contains($releasePackager, "\$_ -match '\\\\'"), 'Release packaging must reject any backslash entry before publishing the ZIP.');
$assert(str_contains($releasePackager, "'LICENSE'") && str_contains($releasePackager, 'marifex/LICENSE'), 'Every release archive must include the canonical GPLv3 license file.');
$assert(str_contains($releasePackager, "'Adminsetup.md'") && str_contains($releasePackager, "'Screenshots'"), 'Every release archive must include the public administrator guide and README screenshots.');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/setup.php'), "'license' => 'GPL-3.0-only'"), 'GLPI plugin metadata must declare the approved GPLv3 license.');
$assert(str_contains($releasePackager, 'https://www.marifextech.com') && str_contains($releasePackager, 'mohammed@marifextech.com'), 'Release packaging must reject incorrect public website or support contact metadata.');
$assert(str_contains($dashboardFrontend, "'X-Requested-With': 'XMLHttpRequest'"), 'Dashboard writes must opt into GLPI AJAX CSRF validation.');
$assert(str_contains($dashboardFrontend, "'X-Glpi-Csrf-Token': props.csrfToken"), 'Dashboard writes must send the GLPI CSRF header.');
$assert(str_contains($dashboardFrontend, 'Create from template'), 'Builder must expose dashboard templates.');
$assert(str_contains($dashboardFrontend, 'duplicateDashboard'), 'Builder must expose dashboard duplication.');
$assert(str_contains($dashboardFrontend, 'cancelEditing'), 'Builder must preserve draft/cancel behavior.');
$assert(str_contains($dashboardFrontend, 'GridStack.init') && str_contains($dashboardFrontend, "resizable: { handles: 'all' }") && str_contains($dashboardFrontend, "draggable: { handle: '.marifex-widget__header'"), 'Builder must use the researched GridStack interaction model with header drag and edge/corner resize.');
$assert(str_contains($dashboardFrontend, "float: false") && str_contains($dashboardFrontend, "grid?.compact('compact', true)"), 'Grid collisions and released space must move and compact neighbouring widgets automatically.');
$assert(str_contains($dashboardFrontend, 'widget.x = Math.max(0, node.x') && str_contains($dashboardFrontend, 'widget.y = Math.max(0, node.y'), 'Grid changes must persist final compacted X/Y coordinates.');
$assert(str_contains($dashboardDefinition, "\$validatedWidget['x']") && str_contains($dashboardDefinition, "\$validatedWidget['y']"), 'Saved dashboard validation must preserve bounded widget canvas coordinates.');
$assert(str_contains($dashboardFrontend, ':gs-x="widget.x"') && str_contains($dashboardFrontend, ':gs-y="widget.y"') && str_contains($dashboardFrontend, 'class="grid-stack-item"'), 'GridStack items must render saved geometry while retaining automatic placement for legacy definitions.');
$assert(str_contains($widgetFrontend, 'settingsOpen') && str_contains($widgetFrontend, 'marifex-widget--settings-open'), 'Widget settings must open as a non-sizing edit overlay.');
$assert(str_contains($widgetFrontend, 'v-model="draftTitle"') && str_contains($widgetFrontend, "emit('rename', props.widget.id, title)"), 'Widget title edits must remain staged until Apply and then update the dashboard definition before Save.');
$assert(str_contains($dashboardFrontend, "'executive-sla-list': 'Service health'") && str_contains($dashboardFrontend, "'executive-change-open': 'Change and problem control'"), 'Executive widgets must expose all controlled below-fold section labels.');
$assert(str_contains($widgetFrontend, 'marifex-widget__section-label') && str_contains($dashboardCss, '.marifex-widget__section-label'), 'Section labels must render inside widget geometry so saved free-canvas positions remain stable.');
$assert(!str_contains($dashboardCss, 'grid-column: span 6 !important') && str_contains($dashboardFrontend, "breakpoints: [{ w: 768, c: 1, layout: 'list' }]") && str_contains($dashboardFrontend, 'clientWidth ?? 768) < 768'), 'Desktop view must preserve saved geometry while mobile stacking remains presentation-only.');
$assert(str_contains($dashboardFrontend, "metric: 'asset_inventory_total'"), 'Widget catalog must expose certified Phase 4 asset metrics.');
$assert(str_contains($dashboardFrontend, "metric: 'open_changes'"), 'Widget catalog must expose certified Phase 4 change metrics.');
$assert(str_contains($dashboardFrontend, "metric: 'open_problems'"), 'Widget catalog must expose certified Phase 4 problem metrics.');
$phase5bCatalogMetrics = [
    'created_tickets_by_request_source', 'ticket_reopen_events', 'ticket_resolution_events',
    'first_response_p50_seconds', 'first_response_p75_seconds', 'first_response_p90_seconds',
    'survey_responses_total', 'dissatisfied_responses_total', 'customer_dissatisfaction_rate',
    'solution_proposed_tickets', 'solution_refused_tickets', 'refused_solution_rate',
    'incident_linked_computers', 'repeat_incident_computers_90d', 'repeat_incident_asset_rate',
    'licence_covered_titles', 'licence_installed_titles', 'licence_utilization_rate', 'licence_coverage_gap_rate',
];
foreach ($phase5bCatalogMetrics as $metric) {
    $assert(str_contains($dashboardFrontend, "metric: '$metric'"), "Phase 5B widget catalog is missing $metric.");
}
$assert(str_contains($dashboardFrontend, 'v-if="hasGroupFilter"'), 'Ticket group filters must not appear on unrelated Phase 4 dashboards.');
$assert(str_contains($dashboardFrontend, '@change="persistFilters"'), 'Dashboard horizon and global filters must persist to the active saved dashboard.');
$assert(str_contains($dashboardFrontend, "exportUrl('pdf')"), 'Home dashboards must expose governed PDF export.');
$assert(str_contains($dashboardFrontend, "exportUrl('csv')"), 'Home dashboards must expose governed CSV export.');
$assert(str_contains($dashboardFrontend, 'InsightStrip') && str_contains($dashboardFrontend, 'loadInsights'), 'Home dashboards must load the governed Phase 5A insight strip.');
$assert(str_contains($dashboardFrontend, "params.set('domains', domains.join(','))") && str_contains($dashboardFrontend, "domains.size >= 3 || domains.has('ticket')"), 'Module dashboards must request domain-relevant insights while Executive remains cross-domain.');
$assert(str_contains($dashboardFrontend, 'Schedule dashboard report'), 'Home dashboards must expose scheduled delivery configuration.');
$assert(!str_contains($widgetFrontend, '>W {{ widget.w }}</button>') && !str_contains($widgetFrontend, '>H {{ widget.h }}</button>'), 'Builder must not expose developer-style W/H resize buttons.');
$assert(str_contains($widgetFrontend, 'ResizeObserver'), 'Charts must observe and adapt to widget size changes.');
$assert(str_contains($widgetFrontend, 'resizeTimer') && str_contains($widgetFrontend, '150'), 'Chart reflow must be debounced during pointer resizing.');
$assert(str_contains($widgetFrontend, 'donutGroups') && str_contains($widgetFrontend, "dimension: 'Other'"), 'Donuts must cap categories and aggregate the remainder as Other.');
$assert(str_contains($widgetFrontend, "widget.type === 'attention'") && str_contains($widgetFrontend, "widget.type === 'matrix'"), 'Controlled attention and matrix presentations must be rendered.');
$assert(str_contains($widgetFrontend, 'Widget surface theme') && str_contains($widgetFrontend, 'Chart series palette') && str_contains($widgetFrontend, "emit('palette'"), 'Every widget must expose independent surface and chart palette selectors in edit mode.');
$assert(str_contains($widgetFrontend, 'Apply &amp; close') && str_contains($widgetFrontend, 'cancelSettings') && !str_contains($widgetFrontend, 'marifex-widget__settings-close'), 'Widget settings must use explicit staged Cancel and Apply-and-close actions instead of an unreliable close icon.');
$assert(str_contains($widgetFrontend, '<Teleport to="body">') && str_contains($widgetFrontend, 'marifex-widget__settings--drawer'), 'Widget settings must render at document level so GridStack sibling stacking contexts cannot cover the drawer.');
$assert(str_contains($dashboardCss, '.marifex-widget__settings--drawer') && !str_contains($dashboardCss, '.marifex-widget--settings-open { z-index'), 'The settings drawer must own a document-level stacking layer instead of attempting to raise a nested widget card.');
$assert(str_contains($widgetFrontend, 'v-if="widget.requiredColorSlots > 0"') && str_contains($widgetFrontend, 'This widget has no plotted chart series'), 'Only plotted chart widgets may expose a chart-series palette selector.');
$assert(!str_contains($widgetFrontend, 'vs previous period'), 'KPI cards must not compare only the last two samples as if they were the selected horizon.');
$assert(str_contains($dashboardFrontend, '@palette="recolorWidget"'), 'Per-widget palette changes must update the saved dashboard definition.');
$paletteFrontend = file_get_contents(dirname(__DIR__) . '/frontend/palettes.ts');
$assert(str_contains($paletteFrontend, "defaultWidgetPalette: WidgetPaletteKey = 'cream_gold'") && str_contains($paletteFrontend, "type: 'Gradient'"), 'Cream Gold must remain the default widget gradient palette.');
$assert(substr_count($paletteFrontend, "type: 'Gradient'") >= 13 && str_contains($paletteFrontend, "key: 'classic_blue'") && str_contains($paletteFrontend, "key: 'slate_gray'"), 'The complete approved gradient palette collection must be available to widgets.');
$assert(str_contains($dashboardCss, '.grid-stack-placeholder') && str_contains($dashboardCss, '.ui-resizable-handle'), 'The edit canvas must visibly preview snap-grid placement and resize affordances.');
$assert(str_contains($dashboardCss, '.marifex-widget--palette-cream_gold') && str_contains($dashboardCss, '--mx-widget-bg-end'), 'Widget palettes must control non-white card backgrounds.');
$assert(str_contains($dashboardCss, 'container-type: size'), 'Widget content must scale against its own width and height.');
$assert(str_contains($dashboardCss, 'min(12cqw, 28cqh)'), 'KPI typography must respond to both widget width and height.');
$assert(str_contains($dashboardCss, 'box-shadow: 0 1px 2px') && str_contains($dashboardFrontend, 'cellHeight: 48') && str_contains($dashboardFrontend, 'margin: 8'), 'The premium GridStack canvas must retain restrained card edges and controlled size units.');
$assert(str_contains($dashboardCss, 'align-content: start') && str_contains($dashboardCss, 'inset-block: 9rem 16px'), 'Widget settings must remain top-aligned and below GLPI sticky navigation.');
$dashboardBootstrap = file_get_contents(dirname(__DIR__) . '/frontend/main.ts');
$assert(str_contains($dashboardBootstrap, "meta[property=\"glpi:csrf_token\"]"), 'Dashboard bootstrap must fall back to GLPI core CSRF metadata.');
$assert(str_contains($dashboardBootstrap, 'MutationObserver'), 'Dashboard app must mount when GLPI loads the Home tab asynchronously.');

$homeDashboard = file_get_contents(dirname(__DIR__) . '/src/HomeDashboardTab.php');
$assert(str_contains($homeDashboard, 'instanceof Central'), 'Analytics must be registered as an additional GLPI Home tab.');
$assert(str_contains($homeDashboard, "@marifex/dashboard/embed.html.twig"), 'Home tab must render the scoped dashboard application.');
$setup = file_get_contents(dirname(__DIR__) . '/setup.php');
$assert(str_contains($setup, "['addtabon' => \\Central::class]"), 'Plugin must register its Analytics tab on GLPI Home.');
$changelog = file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$assert(str_contains($changelog, '[0.14.1-dev]') && str_contains($changelog, 'document body'), 'The release changelog must record the document-level widget-settings drawer repair.');
$dashboardController = file_get_contents(dirname(__DIR__) . '/src/Controller/DashboardController.php');
$assert(str_contains($dashboardController, '/front/central.php?forcetab='), 'Legacy dashboard route must redirect to the Home Analytics tab.');
$mobileEmbed = file_get_contents(dirname(__DIR__) . '/templates/dashboard/mobile_embed.html.twig');
$assert(str_contains($dashboardController, "#[Route('/Dashboard/Mobile'") && str_contains($dashboardController, 'Profile::canView()'), 'The chrome-free mobile route must retain the existing dashboard access right.');
$assert(str_contains($dashboardController, "['path' => 'lib/gridstack.css']") && str_contains($dashboardController, "['path' => 'lib/gridstack.js']"), 'The mobile route must load the same GLPI GridStack layout dependency used by the Home dashboard.');
$assert(strpos($mobileEmbed, "@marifex/dashboard/embed.html.twig") < strpos($mobileEmbed, 'dashboard_js_files'), 'The mobile dashboard scripts must load after the dashboard mount element exists.');
$assert(str_contains($mobileEmbed, 'js_path(js_file.path') && !str_contains($mobileEmbed, 'layout/parts/page_footer.html.twig'), 'The mobile route must load only its governed footer scripts without importing the full GLPI page chrome.');

$widgetCard = file_get_contents(dirname(__DIR__) . '/frontend/WidgetCard.vue');
$assert(!str_contains($widgetCard, "type: 'scroll'"), 'Dashboard legends must never use paginated scrolling.');
$assert(str_contains($widgetCard, 'marifex-donut-layout'), 'Donut widgets must use a fixed chart-left and legend-right layout.');
$assert(str_contains($widgetCard, "software_license_compliance_rate"), 'Licence compliance KPIs must render as percentages.');
$assert(str_contains($widgetCard, "latestPoint.value?.sample_count === 0") && str_contains($widgetCard, "return 'N/A'"), 'Population-rate KPIs must show N/A when no measurable denominator exists.');
$assert(str_contains($widgetCard, 'props.assetSearchUrl'), 'Asset widgets must drill down to native GLPI computer lists.');
$assert(str_contains($widgetCard, 'props.changeSearchUrl'), 'Change widgets must drill down to native GLPI change lists.');
$assert(str_contains($widgetCard, 'props.problemSearchUrl'), 'Problem widgets must drill down to native GLPI problem lists.');
$assert(!str_contains($widgetCard, 'Analytics Data Mart'), 'Home widget headings must not expose the Analytics Data Mart implementation label.');
$definitionService = file_get_contents(dirname(__DIR__) . '/src/Dashboard/DashboardDefinitionService.php');
$assert(str_contains($definitionService, 'WIDGET_PALETTES') && str_contains($definitionService, "'palette' => \$palette"), 'The server must allowlist and persist widget palettes.');
$paletteRegistry = file_get_contents(__DIR__ . '/../src/Palette/PaletteRegistry.php');
$paletteValidator = file_get_contents(__DIR__ . '/../src/Palette/PaletteValidator.php');
$paletteService = file_get_contents(__DIR__ . '/../src/Palette/PaletteService.php');
$paletteController = file_get_contents(__DIR__ . '/../src/Controller/PaletteController.php');
$chartPaletteFrontend = file_get_contents(__DIR__ . '/../frontend/chartPalettes.ts');
$scope = file_get_contents(__DIR__ . '/../docs/DASHBOARD_DESIGN_SCOPE.md');
$assert(str_contains($paletteRegistry, 'SURFACE_TO_CHART') && substr_count($paletteRegistry, "'chart_") >= 16, 'Phase 5C must exhaustively map every built-in surface theme to a chart palette.');
$assert(str_contains($paletteRegistry, "'charcoal_gold' => ['Charcoal Gold', ['#F2BD31','#263247','#FFE08A','#58657A'") && str_contains($paletteRegistry, "\$key === 'charcoal_gold' ? 2 : 1"), 'Charcoal Gold chart palette must contain both charcoal and gold series colours under revision 2.');
$builtInPalettes = \GlpiPlugin\Marifex\Palette\PaletteRegistry::builtIns();
$assert(count($builtInPalettes) === 16 && count(\GlpiPlugin\Marifex\Palette\PaletteRegistry::SURFACE_TO_CHART) === 16, 'All sixteen built-in surface and chart palettes must be registered together.');
foreach ($builtInPalettes as $paletteId => $paletteDefinition) {
    $colors = $paletteDefinition['colors'] ?? [];
    $assert(count($colors) >= 6 && count($colors) <= 12 && count(array_unique($colors)) === count($colors), sprintf('%s must provide 6 to 12 distinct chart colours.', $paletteId));
    $assert(count(array_filter($colors, static fn(string $color): bool => preg_match('/^#[0-9A-F]{6}$/', $color) === 1)) === count($colors), sprintf('%s must use normalized hexadecimal chart colours.', $paletteId));
}
$reportRendererSource = file_get_contents(__DIR__ . '/../src/Report/HtmlReportRenderer.php');
$assert(str_contains($reportRendererSource, 'PaletteRegistry::builtIns()') && str_contains($reportRendererSource, 'PaletteRegistry::SURFACE_TO_CHART'), 'Screen and report chart palettes must resolve from the same governed built-in registry.');
$paletteFrontend = file_get_contents(dirname(__DIR__) . '/frontend/palettes.ts');
$frontendPaletteColors = [];
preg_match_all("/\{ key: '([^']+)'.*?colors: \[([^\]]+)\] \}/", $paletteFrontend, $frontendPaletteMatches, PREG_SET_ORDER);
foreach ($frontendPaletteMatches as $frontendPaletteMatch) {
    preg_match_all("/'(#[0-9A-Fa-f]{6})'/", $frontendPaletteMatch[2], $frontendColorMatches);
    $frontendPaletteColors[$frontendPaletteMatch[1]] = array_map('strtoupper', $frontendColorMatches[1]);
}
$assert(count($frontendPaletteColors) === 16, 'Frontend must expose all sixteen governed surface palettes.');
foreach (\GlpiPlugin\Marifex\Palette\PaletteRegistry::SURFACE_TO_CHART as $surfaceKey => $chartKey) {
    $assert(str_contains($paletteFrontend, "key: '$surfaceKey'"), sprintf('Frontend surface fallback is missing %s.', $surfaceKey));
    $assert(($frontendPaletteColors[$surfaceKey] ?? []) === $builtInPalettes[$chartKey]['colors'], sprintf('Frontend, server and report colours must match for %s.', $surfaceKey));
}
$assert(str_contains($paletteValidator, '51200') && str_contains($paletteValidator, 'duplicate keys') && str_contains($paletteValidator, "['categorical','monochrome','gradient']"), 'Phase 5C imports must enforce the controlled schema, size and duplicate-key rules.');
$validator = new \GlpiPlugin\Marifex\Palette\PaletteValidator();
$validPaletteJson = '{"schemaVersion":1,"name":"Regression Palette","type":"categorical","colors":["#1D4ED8","#10B981","#8B5CF6","#F59E0B","#EF4444","#64748B"],"areaOpacity":0.25,"isRecursive":false}';
$assert($validator->assertImport($validPaletteJson)['name'] === 'Regression Palette', 'A valid strict Phase 5C palette JSON import must parse successfully.');
$duplicateRejected = false;
try { $validator->assertImport('{"schemaVersion":1,"name":"One","name":"Two","type":"categorical","colors":["#1D4ED8","#10B981","#8B5CF6","#F59E0B","#EF4444","#64748B"],"areaOpacity":0.25,"isRecursive":false}'); } catch (InvalidArgumentException) { $duplicateRejected = true; }
$assert($duplicateRejected, 'Duplicate JSON keys must be rejected before decoding.');
$assert(str_contains($paletteService, 'confirmationRequired') && str_contains($paletteService, 'chart_palette_updated') && str_contains($paletteService, 'childEntities'), 'Palette revision writes must expose impacts and retain audit evidence.');
$assert(str_contains($paletteController, "'/api/palettes'") && str_contains($paletteController, 'Profile::canAdminister'), 'Palette writes must use the governed endpoint and administrator right.');
$paletteManager = file_get_contents(__DIR__ . '/../public/js/palette-manager.js');
$assert(str_contains($paletteManager, "'X-Requested-With':'XMLHttpRequest'") && str_contains($paletteManager, "'X-Glpi-Csrf-Token'"), 'Palette writes must satisfy GLPI 11 AJAX CSRF handling.');
$assert(!str_contains($paletteManager, 'prompt(') && !str_contains($paletteManager, 'confirm(') && str_contains($paletteManager, 'Confirm delete & replace'), 'Palette impact confirmation must use governed inline controls rather than native browser dialogs.');
$assert(str_contains($chartPaletteFrontend, 'protanopia') && str_contains($chartPaletteFrontend, 'deuteranopia') && str_contains($chartPaletteFrontend, 'contrastRatio'), 'Phase 5C must provide deterministic visual-accessibility preview primitives.');
$assert(str_contains($widgetFrontend, "renderMode: 'richText'") && str_contains($widgetFrontend, 'navigateChart') && str_contains($widgetFrontend, 'aria-live="polite"'), 'Charts must provide confined native tooltips and keyboard point inspection.');
$assert(str_contains($widgetFrontend, 'fontWeight: 600') && str_contains($widgetFrontend, 'chartFontFamily'), 'Chart axes and legends must use the readable governed dashboard typography.');
$assert(str_contains($dashboardCss, '@media (hover: hover) and (pointer: fine)') && str_contains($dashboardCss, ':focus-within') && str_contains($dashboardCss, ':not(.marifex-widget--editing):hover'), 'Widget cards must expose governed pointer and keyboard hover feedback without affecting edit mode.');
$assert(!preg_match('/\.marifex-widget:hover\s*\{[^}]*transform:/', $dashboardCss), 'Edit-mode widget cards must never become a containing block for the fixed settings dialog.');
$assert(str_contains($widgetFrontend, 'Trend pending') && str_contains($widgetFrontend, 'Current value'), 'KPI context must distinguish incomplete trend history from current-only values.');
$assert(str_contains($widgetFrontend, 'observedMovementText') && str_contains($widgetFrontend, 'comparison_basis.toLowerCase()'), 'KPI cards must expose factual monitoring movement without period-comparison semantics.');
$insightStripFrontend = file_get_contents(dirname(__DIR__) . '/frontend/InsightStrip.vue');
$assert(str_contains($insightStripFrontend, 'trend analysis is preparing') && str_contains($insightStripFrontend, 'Measures awaiting update'), 'Baseline readiness must use compact user-facing language and friendly affected-measure details.');
$assert(!str_contains($insightStripFrontend, ' snapshots ·') && !str_contains($insightStripFrontend, 'certified snapshot') && !str_contains($insightStripFrontend, 'Latest snapshot is stale'), 'Executive readiness language must not expose data-engineering terminology.');
$assert(str_contains($dashboardCss, '.marifex-insight-readiness__sources') && str_contains($dashboardCss, 'grid-template-columns: repeat(2,minmax(0,1fr))'), 'Expanded baseline readiness must use the available width without tiny technical text.');
$assert(str_contains($dashboardCss, '.marifex-insight-readiness__sources small { color: #111827;'), 'Affected-measure availability text must use the approved near-black readable colour.');
$assert(str_contains($insightStripFrontend, 'effective_provenance_label') && str_contains($insightStripFrontend, 'activation_state') && str_contains($insightStripFrontend, 'calculation.scope'), 'Calculation inspection must expose provenance, activation, coverage, refresh and governed scope evidence.');
$assert(str_contains($insightStripFrontend, "item.key === 'top_group_workload_share'") && str_contains($insightStripFrontend, 'group_id'), 'Material concentration findings must navigate to the deepest authorized shipped group evidence.');
$assert(substr_count($dashboardCss, 'font-size: 13px') >= 5 && str_contains($dashboardCss, '.marifex-filterbar .form-select') && str_contains($dashboardCss, '.marifex-widget__settings .form-control'), 'Dashboard and widget-settings dropdowns must retain readable governed typography.');
$assert(str_contains($scope, 'Delta E 00 < 10') && str_contains($scope, 'Phase 5D: reserved, not approved for implementation'), 'The controlled scope must retain Phase 5C thresholds and keep Phase 5D unapproved.');
$assert(str_contains($definitionService, "'classic_blue'") && str_contains($definitionService, "'slate_gray'"), 'The server palette allowlist must include the approved gradient collection.');
$executiveWidgets = substr($definitionService, (int) strpos($definitionService, 'private function premiumExecutiveWidgets'));
$assert(!str_contains($executiveWidgets, "'created_tickets_by_request_source'") && !str_contains($executiveWidgets, "'ticket_reopen_events'"), 'Phase 5B must not silently add cards to the default Executive layout.');

$phase5bScope = file_get_contents(dirname(__DIR__) . '/docs/DASHBOARD_DESIGN_SCOPE.md');
$assert(str_contains($phase5bScope, 'Formula version: `phase5b-1`') && str_contains($phase5bScope, '`sum(ticket_reopen_events) / sum(ticket_resolution_events) * 100`'), 'The approved Phase 5B formulas must remain in the controlled scope document.');
$ticketSnapshot = file_get_contents(dirname(__DIR__) . '/src/Etl/TicketOperationsSnapshotBuilder.php');
$assert(str_contains($ticketSnapshot, "'date_answered', 'satisfaction_scaled_to_5'") && str_contains($ticketSnapshot, 'nearestRank'), 'Phase 5B response and satisfaction collectors must retain their certified inputs.');
$metricQueryService = file_get_contents(dirname(__DIR__) . '/src/Metric/MetricQueryService.php');
$assert(str_contains($metricQueryService, 'daily_response_observations') && str_contains($metricQueryService, 'licenceTitleSeries'), 'Percentile and distinct-title queries must aggregate authorized observations before calculating results.');
$assert(str_contains($metricQueryService, 'Live breached SLA exceptions'), 'Operational attention must distinguish the live SLA exception count from the certified SLA snapshot metric.');
$insightCalculator = file_get_contents(dirname(__DIR__) . '/src/Insight/InsightCalculator.php');
$assert(!preg_match('/caused by|because of|due to/i', $insightCalculator), 'Governed insight narratives must not contain causal wording.');
$assert(str_contains($insightCalculator, "'asset' => ['stale_computer_inventory', 'asset_inventory_total'") && str_contains($insightCalculator, "'change' => ['daily_change_volume'"), 'Insight readiness must use the approved per-domain evidence cores.');
$assert(str_contains($insightCalculator, "array_intersect(array_unique(\$domains), ['ticket', 'asset', 'licence', 'change', 'problem'])"), 'Insight domain requests must be restricted to the controlled allowlist.');

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
$assert(str_contains($csvRenderer, "'record_type'") && str_contains($csvRenderer, "'formula_version'"), 'Phase 5A CSV output must distinguish records and preserve formula evidence.');
$assert(str_contains($csvRenderer, '$calculation[\'formula_version\']') && str_contains($insightStripFrontend, 'item.calculation.formula_version'), 'Insight CSV and screen evidence must use the owning per-calculation formula version rather than a global phase label.');
$assert(str_contains($csvRenderer, "'activation_state'") && str_contains($csvRenderer, "'effective_provenance'") && str_contains($csvRenderer, "'entity_scope'"), 'CSV output must preserve activation, provenance, materiality, coverage and scoped evidence parity.');
$assert(str_contains($reportExport, "'insight_evidence'") && str_contains($reportExport, "'formula_version'"), 'Report history must retain scoped Phase 5A calculation evidence.');
$assert(str_contains($csvRenderer, "preg_match('/^[=+\\-@\\t\\r]/'"), 'CSV exports must neutralize spreadsheet formula injection.');
$assert(str_contains($pdfRenderer, '--headless=new') && str_contains($pdfRenderer, '--print-to-pdf='), 'PDF export must use the scoped headless-browser architecture.');
$assert(str_contains($pdfRenderer, "is_file('/.dockerenv')") && str_contains($pdfRenderer, "['--no-sandbox']"), 'Containerized PDF export must use the explicit Chromium sandbox compatibility flag.');
$assert(str_contains($pdfRenderer, '--no-pdf-header-footer'), 'Generated PDFs must not expose temporary renderer paths in browser headers or footers.');
$assert(str_contains($htmlRenderer, 'palette-cream_gold') && str_contains($htmlRenderer, 'PaletteRegistry::builtIns()'), 'Static PDF reports must preserve per-widget palettes through the governed registry.');
$assert(str_contains($htmlRenderer, 'palette-classic_blue') && str_contains($htmlRenderer, 'palette-slate_gray'), 'Static PDF reports must render the approved gradient collection.');
$assert(!str_contains($htmlRenderer, 'page-number') && !str_contains($htmlRenderer, 'Page <b'), 'Static PDF reports must not emit the unsupported Chrome page counter.');
$assert(str_contains($htmlRenderer, 'sourceContext') && str_contains($htmlRenderer, 'tickets represented'), 'Static PDF reports must disclose live versus certified-snapshot timing and distribution coverage.');
$assert(str_contains($htmlRenderer, 'monitoring-context') && str_contains($htmlRenderer, "observed_movements"), 'PDF output must preserve Progressive Analytical Activation and observational movement parity with screen output.');
$reportFixture = [
    'dashboard' => ['name' => 'PDF fixture'], 'from' => '2026-01-01', 'to' => '2026-01-31',
    'generated_at' => '2026-01-31T00:00:00+00:00', 'entities_id' => 0,
    'entity_label' => 'MarifeX', 'scope_label' => 'MarifeX enterprise-wide', 'horizon_days' => 30,
    'widgets' => [
        ['definition' => ['title' => 'Insight', 'type' => 'insight', 'metric' => 'historical_group_backlog'], 'data' => ['series' => [['date' => '2026-01-31', 'dimension' => 'Service Desk', 'value' => 12]]]],
        ['definition' => ['title' => 'Attention', 'type' => 'attention', 'metric' => 'operational_attention'], 'data' => ['rows' => [['finding' => 'Open SLA breaches', 'count' => 3, 'severity' => 'critical']]]],
        ['definition' => ['title' => 'Details', 'type' => 'detail_table', 'metric' => 'active_sla_exceptions'], 'data' => ['rows' => [['id' => 7, 'title' => 'Ticket', 'state' => 'Breached', 'group' => 'L1', 'timing' => '2h overdue']]]],
        ['definition' => ['title' => 'Matrix', 'type' => 'matrix', 'metric' => 'open_tickets_priority_category_matrix'], 'data' => ['matrix' => [['row_id' => 3, 'row' => 'Medium', 'column_id' => 2, 'column' => 'Hardware', 'value' => 5]]]],
        ['definition' => ['title' => 'Current open tickets', 'type' => 'kpi', 'metric' => 'current_open_tickets'], 'data' => ['value' => 10, 'source' => 'live', 'as_of' => '2026-01-31T09:30:00+00:00']],
        ['definition' => ['title' => 'Open tickets by priority', 'type' => 'donut', 'metric' => 'open_tickets_by_priority'], 'data' => ['source' => 'data_mart', 'series' => [['date' => '2026-01-30', 'dimension' => 'High', 'value' => 4], ['date' => '2026-01-30', 'dimension' => 'Low', 'value' => 6]]]],
        ['definition' => ['title' => 'Software marked invalid', 'type' => 'table', 'metric' => 'prohibited_software_installations'], 'data' => ['source' => 'data_mart', 'series' => []]],
    ],
];
$renderedFixture = (new HtmlReportRenderer())->render($reportFixture);
$assert(str_contains($renderedFixture, 'Service Desk') && str_contains($renderedFixture, '12 current records'), 'Static PDF insight widgets must include their leading value.');
$assert(str_contains($renderedFixture, 'Open SLA breaches') && str_contains($renderedFixture, 'severity-critical'), 'Static PDF attention widgets must include severity and counts.');
$assert(str_contains($renderedFixture, '#7 Ticket') && str_contains($renderedFixture, '2h overdue'), 'Static PDF detail tables must include record values.');
$assert(str_contains($renderedFixture, 'Medium') && str_contains($renderedFixture, 'Hardware'), 'Static PDF matrices must include row and column values.');
$assert(str_contains($renderedFixture, 'Live value · as of 2026-01-31 09:30 UTC') && str_contains($renderedFixture, 'Certified snapshot distribution · as of 2026-01-30 · 10 tickets represented'), 'Static PDF values that can differ must disclose source timing and represented distribution totals.');
$assert(str_contains($renderedFixture, 'No reportable records') && str_contains($renderedFixture, 'No software is marked invalid in the selected scope.'), 'Empty report widgets must explain the business meaning instead of rendering a blank card.');
$reportFixture['insights'] = $calculatedInsights;
$renderedInsightFixture = (new HtmlReportRenderer())->render($reportFixture);
$assert(str_contains($renderedInsightFixture, 'Executive insight brief') && str_contains($renderedInsightFixture, 'Quality and demand measures, version 1'), 'PDF page one and report notes must present the governed insight brief and calculation standards in client-facing language.');
$assert(str_contains($renderedInsightFixture, 'Executive performance report') && str_contains($renderedInsightFixture, 'Performance summary'), 'PDF page one must use the approved executive-report hierarchy.');
$assert(str_contains($renderedInsightFixture, 'Data coverage and calculation notes') && str_contains($renderedInsightFixture, 'MarifeX enterprise-wide') && !str_contains($renderedInsightFixture, 'Entity #0'), 'PDF must provide readable report notes and human organisation scope.');
$assert(!preg_match('/phase5[ab]-1|Activation profile|Provenance|REPORT APPENDIX|>Dimension</i', $renderedInsightFixture), 'Client-facing PDF output must not expose internal phase, activation, provenance or generic dimension terminology.');
$assert(str_contains($renderedInsightFixture, 'Organisation coverage') && str_contains($renderedInsightFixture, 'Comparison availability') && str_contains($renderedInsightFixture, 'Measure availability and data origin'), 'PDF report notes must use professional business terminology.');
$assert(str_contains($htmlRenderer, 'paginateSectionCards') && str_contains($htmlRenderer, 'break-inside:avoid-page'), 'PDF sections and cards must use deterministic pagination that prevents clipped headings and split widgets.');
$assert(!str_contains($renderedInsightFixture, '<div class="running-header"') && !str_contains($renderedInsightFixture, '<footer'), 'PDF output must not render fixed page furniture that can overlap report content.');
$csvPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'marifex-phase5b-' . bin2hex(random_bytes(4)) . '.csv';
(new CsvReportRenderer())->render($reportFixture, $csvPath);
$csvFixture = file_get_contents($csvPath);
@unlink($csvPath);
$assert(str_contains((string) $csvFixture, 'record_type') && str_contains((string) $csvFixture, 'phase5b-1'), 'CSV export must include governed insight rows in the single report file.');
$assert(str_contains((string) $csvFixture, 'activation_state') && str_contains((string) $csvFixture, 'effective_provenance') && str_contains((string) $csvFixture, 'entity_scope'), 'Rendered CSV evidence must preserve the same activation, provenance and scope fields as screen/report history.');
$csvText = (string) $csvFixture;
$assert(str_starts_with(ltrim($csvText, "\xEF\xBB\xBF"), 'record_type,section,metric,current,previous,movement,direction,interpretation,period,data_status,evidence'), 'CSV must open with business-readable report columns before governed technical evidence.');
$assert(str_contains($csvText, 'Executive insight brief') && str_contains($csvText, 'Evidence detail'), 'CSV must preserve a readable summary-first hierarchy with supporting evidence detail in the same file.');
$assert(strpos($csvText, 'Executive insight brief') < strpos($csvText, 'metric_detail'), 'CSV insight rows must precede raw evidence detail rows.');
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
$assert(str_contains($dashboardEmbed, 'data-insight-endpoint'), 'Dashboard shell must expose the governed Phase 5A endpoint.');
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
