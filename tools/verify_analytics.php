<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
    'assignment_events' => "SELECT event_type, COUNT(*) AS value FROM glpi_plugin_marifex_ticket_events WHERE event_type IN ('ticket_technician_assignment_changed', 'ticket_group_assignment_changed') GROUP BY event_type ORDER BY event_type",
    'assignment_intervals' => "SELECT state_type, COUNT(*) AS value FROM glpi_plugin_marifex_state_intervals WHERE state_type IN ('technician', 'group') GROUP BY state_type ORDER BY state_type",
    'unredacted_assignment_values' => "SELECT COUNT(*) AS value FROM glpi_plugin_marifex_ticket_events WHERE event_type IN ('ticket_technician_assignment_changed', 'ticket_group_assignment_changed') AND ((old_value <> '' AND old_value REGEXP '[^0-9]') OR (new_value <> '' AND new_value REGEXP '[^0-9]'))",
    'overlapping_status_intervals' => "SELECT COUNT(*) AS value FROM (SELECT started_at, LAG(COALESCE(ended_at, '9999-12-31')) OVER (PARTITION BY tickets_id ORDER BY started_at, id) AS previous_end FROM glpi_plugin_marifex_state_intervals WHERE state_type = 'status') ordered_intervals WHERE started_at < previous_end",
    'overlapping_assignment_intervals' => "SELECT COUNT(*) AS value FROM (SELECT started_at, LAG(COALESCE(ended_at, '9999-12-31')) OVER (PARTITION BY tickets_id, state_type, state_value ORDER BY started_at, id) AS previous_end FROM glpi_plugin_marifex_state_intervals WHERE state_type IN ('technician', 'group')) ordered_intervals WHERE started_at < previous_end",
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
$valid = (int) ($report['overlapping_status_intervals'][0]['value'] ?? 1) === 0
    && (int) ($report['overlapping_assignment_intervals'][0]['value'] ?? 1) === 0
    && (int) ($report['unredacted_assignment_values'][0]['value'] ?? 1) === 0;
exit($valid ? 0 : 1);
