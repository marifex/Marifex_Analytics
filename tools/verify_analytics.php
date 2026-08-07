<?php

declare(strict_types=1);

use Glpi\Kernel\Kernel;

$glpiRoot = $argv[1] ?? '';
if ($glpiRoot === '' || !is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tools/verify_analytics.php <glpi-root>" . PHP_EOL);
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';
(new Kernel())->boot();
global $DB;

$queries = [
    'status_events' => "SELECT COUNT(*) AS value FROM glpi_plugin_marifex_ticket_events WHERE event_type = 'ticket_status_changed'",
    'status_intervals' => "SELECT COUNT(*) AS value FROM glpi_plugin_marifex_state_intervals WHERE state_type = 'status'",
    'overlapping_intervals' => "SELECT COUNT(*) AS value FROM glpi_plugin_marifex_state_intervals a JOIN glpi_plugin_marifex_state_intervals b ON a.tickets_id = b.tickets_id AND a.state_type = b.state_type AND a.id < b.id AND a.started_at < COALESCE(b.ended_at, '9999-12-31') AND b.started_at < COALESCE(a.ended_at, '9999-12-31')",
    'snapshot_days' => 'SELECT COUNT(DISTINCT snapshot_date) AS value FROM glpi_plugin_marifex_daily_snapshots',
    'snapshot_rows' => 'SELECT COUNT(*) AS value FROM glpi_plugin_marifex_daily_snapshots',
    'rollups' => 'SELECT metric_key, COUNT(*) AS value FROM glpi_plugin_marifex_daily_rollups GROUP BY metric_key ORDER BY metric_key',
];

$report = [];
foreach ($queries as $name => $sql) {
    $result = $DB->doQuery($sql);
    $report[$name] = [];
    while ($row = $result->fetch_assoc()) {
        $report[$name][] = $row;
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(((int) ($report['overlapping_intervals'][0]['value'] ?? 1)) === 0 ? 0 : 1);
