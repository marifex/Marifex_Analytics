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
foreach ($contexts as $context => $domains) {
    foreach ([7, 30, 90, 180, 365] as $horizon) {
        $payload = $service->build($horizon, null, null, $domains);
        $result[$context][(string) $horizon] = [
            'domains' => $payload['domains'] ?? [],
            'cutoff' => $payload['cutoff'],
            'ready' => $payload['readiness']['ready_metrics'],
            'total' => $payload['readiness']['total_metrics'],
            'insights' => array_map(static fn(array $item): array => [
                'key' => $item['key'] ?? '',
                'direction' => $item['direction'] ?? '',
                'evidence_target' => $item['evidence_target'] ?? '',
                'narrative' => $item['narrative'] ?? '',
            ], $payload['insights']),
            'suppressed_count' => count($payload['suppressed']),
        ];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
