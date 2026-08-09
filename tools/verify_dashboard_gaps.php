<?php

declare(strict_types=1);

use Glpi\Kernel\Kernel;
use GlpiPlugin\Marifex\Metric\MetricQueryService;
use GlpiPlugin\Marifex\Security\EntityScope;

$glpiRoot = $argv[1] ?? '';
if ($glpiRoot === '' || !is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tools/verify_dashboard_gaps.php <glpi-root>" . PHP_EOL);
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';
(new Kernel())->boot();

global $DB;
$entityIds = [];
foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities']) as $entity) {
    $entityIds[] = (int) $entity['id'];
}
$service = new MetricQueryService(new EntityScope($entityIds, $entityIds[0] ?? 0));
$checks = [
    'latest_solution_refused_tickets' => 'rows',
    'open_incidents_by_assignment_group' => 'series',
    'open_tickets_priority_category_matrix' => 'matrix',
    'active_sla_exceptions' => 'rows',
    'operational_attention' => 'rows',
];

foreach ($checks as $metric => $field) {
    $payload = $service->query($metric, new DateTimeImmutable('-30 days'), new DateTimeImmutable('today'));
    if (($payload['metric'] ?? null) !== $metric || !isset($payload[$field]) || !is_array($payload[$field])) {
        throw new RuntimeException(sprintf('Metric %s did not return its governed %s payload.', $metric, $field));
    }
    printf("%s: %d %s%s", $metric, count($payload[$field]), $field, PHP_EOL);
}

fwrite(STDOUT, 'Dashboard gap integration checks passed.' . PHP_EOL);
