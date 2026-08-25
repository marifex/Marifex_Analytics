<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use Glpi\DBAL\QueryExpression;
use Throwable;

final class AnalyticsReconciler
{
    public function run(): int
    {
        global $DB;

        $startedAt = gmdate('Y-m-d H:i:s');
        $DB->insert('glpi_plugin_marifex_reconciliation_runs', [
            'scope' => 'ticket_created_events',
            'started_at' => $startedAt,
            'status' => 'running',
        ]);
        $runId = (int) $DB->insertId();

        try {
            $sourceCount = $this->count('glpi_tickets');
            $analyticsCount = $this->count('glpi_plugin_marifex_ticket_events', [
                'event_type' => 'ticket_created',
            ], 'DISTINCT `tickets_id`');

            $watermark = (new CheckpointStore())->watermark('tickets_backfill_v1', 'glpi_tickets');
            $expectedImported = $this->count('glpi_tickets', ['id' => ['<=', $watermark['id']]]);
            $missing = max(0, $expectedImported - $analyticsCount);
            $orphanCount = $this->countMissingSources();
            $status = $missing === 0 ? ($orphanCount === 0 ? 'passed' : 'warning') : 'warning';

            $DB->update('glpi_plugin_marifex_reconciliation_runs', [
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'source_count' => $sourceCount,
                'analytics_count' => $analyticsCount,
                'missing_count' => $missing,
                'orphan_count' => $orphanCount,
                'status' => $status,
                'details' => json_encode([
                    'backfill_watermark_id' => $watermark['id'],
                    'expected_imported_count' => $expectedImported,
                    'orphan_policy' => 'preserve_non_sensitive_history',
                ], JSON_THROW_ON_ERROR),
            ], ['id' => $runId]);

            return $missing + $orphanCount;
        } catch (Throwable $exception) {
            $DB->update('glpi_plugin_marifex_reconciliation_runs', [
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'status' => 'failed',
                'details' => json_encode(['error' => mb_substr($exception->getMessage(), 0, 1000)], JSON_THROW_ON_ERROR),
            ], ['id' => $runId]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $where */
    private function count(string $table, array $where = [], string $expression = '*'): int
    {
        global $DB;
        $query = [
            'SELECT' => [new QueryExpression(sprintf('COUNT(%s) AS value', $expression))],
            'FROM' => $table,
        ];
        if ($where !== []) {
            $query['WHERE'] = $where;
        }
        $row = $DB->request($query)->current();
        return (int) ($row['value'] ?? 0);
    }

    private function countMissingSources(): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT `glpi_plugin_marifex_ticket_events`.`tickets_id`) AS value')],
            'FROM' => 'glpi_plugin_marifex_ticket_events',
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'FKEY' => [
                        'glpi_plugin_marifex_ticket_events' => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_marifex_ticket_events.event_type' => 'ticket_created',
                'glpi_tickets.id' => null,
            ],
        ])->current();
        return (int) ($row['value'] ?? 0);
    }
}
