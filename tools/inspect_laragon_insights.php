<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
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
foreach ([
    'src/Analytics/MonitoringBaselineRepository.php',
    'src/Metric/MetricDefinition.php',
    'src/Metric/MetricRegistry.php',
    'src/Security/EntityScope.php',
    'src/Metric/MetricQueryService.php',
    'src/Insight/InsightRuleRegistry.php',
    'src/Insight/InsightCalculator.php',
    'src/Insight/InsightService.php',
] as $workspaceClass) {
    require_once $workspaceRoot . '/' . $workspaceClass;
}
require_once $glpiRoot . '/vendor/autoload.php';

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

$service = new GlpiPlugin\Marifex\Insight\InsightService();
$result = [];
$contexts = [
    'executive' => [],
    'asset_licence' => ['asset', 'licence'],
    'change' => ['change'],
    'problem' => ['problem'],
];
$quick = in_array('--quick', $argv, true);
if ($quick) {
    $contexts = ['executive' => []];
}
foreach ($contexts as $context => $domains) {
    foreach ($quick ? [30] : [7, 30, 90, 180, 365] as $horizon) {
        $payload = $service->build($horizon, null, null, $domains);
        $provenance = array_values(array_unique(array_map(
            static fn (array $item): string => (string) ($item['effective_provenance'] ?? $item['provenance'] ?? ''),
            $payload['readiness']['metrics'] ?? [],
        )));
        $result[$context][(string) $horizon] = [
            'domains' => $payload['domains'] ?? [],
            'cutoff' => $payload['cutoff'],
            'ready' => $payload['readiness']['ready_metrics'],
            'total' => $payload['readiness']['total_metrics'],
            'activation_counts' => $payload['readiness']['activation_counts'] ?? [],
            'observed_metrics' => array_values(array_map(
                static fn (array $item): string => (string) $item['metric'],
                array_filter($payload['readiness']['metrics'] ?? [], static fn (array $item): bool => ($item['activation_state'] ?? null) === 'OBSERVED_MOVEMENT'),
            )),
            'provenance' => $provenance,
            'insight_count' => count($payload['insights']),
            'observed_movement_count' => count($payload['observed_movements'] ?? []),
            'suppressed_count' => count($payload['suppressed']),
        ];
    }
}

try {
    $service->build(30, PHP_INT_MAX);
    $result['security']['unauthorized_group_rejected'] = false;
} catch (RuntimeException $error) {
    $result['security']['unauthorized_group_rejected'] = str_contains($error->getMessage(), 'active entity scope');
}
$result['security']['unknown_schedule_owner_rejected'] = !(new GlpiPlugin\Marifex\Report\ReportAuthorizationService())->canExecute(PHP_INT_MAX, (int) Session::getActiveEntity());

$baselineCount = 0;
$invalidBaselineHashes = 0;
$invalidBaselineIds = [];
foreach ($DB->request(['SELECT' => ['id', 'evidence', 'evidence_hash'], 'FROM' => 'glpi_plugin_marifex_monitoring_baselines']) as $baseline) {
    ++$baselineCount;
    $storedEvidence = (string) $baseline['evidence'];
    try {
        $decoded = json_decode($storedEvidence, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !GlpiPlugin\Marifex\Analytics\MonitoringBaselineRepository::integrityValid($decoded, (string) $baseline['evidence_hash'])) {
            ++$invalidBaselineHashes;
            $invalidBaselineIds[] = (int) $baseline['id'];
        }
    } catch (JsonException) {
        ++$invalidBaselineHashes;
        $invalidBaselineIds[] = (int) $baseline['id'];
    }
}
$result['audit_evidence'] = [
    'monitoring_baselines' => $baselineCount,
    'invalid_baseline_hashes' => $invalidBaselineHashes,
    'invalid_baseline_ids' => $invalidBaselineIds,
    'certified_observation_records' => countElementsInTable('glpi_plugin_marifex_daily_metric_observations'),
    'baseline_establishment_events' => countElementsInTable('glpi_plugin_marifex_analytical_audit', ['event_type' => 'monitoring_baselines_established']),
];

$reportDashboard = [
    'name' => 'Analytics parity inspection',
    'definition' => [
        'dateRangeDays' => 30,
        'filters' => ['groupId' => null],
        'widgets' => [
            ['id' => 'asset-total', 'metric' => 'asset_inventory_total', 'type' => 'kpi', 'title' => 'Managed computers', 'palette' => 'cream_gold', 'chartPalette' => 'chart_cream_gold'],
            ['id' => 'licence-compliance', 'metric' => 'software_license_compliance_rate', 'type' => 'kpi', 'title' => 'Licence compliance', 'palette' => 'cream_gold', 'chartPalette' => 'chart_cream_gold'],
        ],
    ],
];
$report = (new GlpiPlugin\Marifex\Report\ReportDataBuilder())->build(
    $reportDashboard,
    array_map('intval', Session::getActiveEntities()),
    (int) Session::getActiveEntity(),
    'UTC',
    Session::getIsActiveEntityRecursive(),
);
$csvPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'marifex-runtime-parity.csv';
(new GlpiPlugin\Marifex\Report\CsvReportRenderer())->render($report, $csvPath);
$csv = (string) file_get_contents($csvPath);
@unlink($csvPath);
$html = (new GlpiPlugin\Marifex\Report\HtmlReportRenderer())->render($report);
$result['output_parity'] = [
    'domains' => $report['insights']['domains'] ?? [],
    'csv_has_activation' => str_contains($csv, 'activation_state'),
    'csv_has_provenance' => str_contains($csv, 'effective_provenance'),
    'csv_has_scope' => str_contains($csv, 'entity_scope'),
    'pdf_html_has_activation_summary' => str_contains($html, 'current values'),
    'history_payload_has_suppression_evidence' => isset($report['insights']['suppressed'][0]['formula_version'], $report['insights']['suppressed'][0]['materiality_outcome'], $report['insights']['suppressed'][0]['scope']),
    'formula_sets' => $report['insights']['formula_versions'] ?? [],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
