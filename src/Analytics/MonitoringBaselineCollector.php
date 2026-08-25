<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

use DateTimeImmutable;
use DBmysql;
use GlpiPlugin\Marifex\Metric\MetricRegistry;
use RuntimeException;

final class MonitoringBaselineCollector
{
    public function __construct(
        private readonly MonitoringBaselineRepository $repository = new MonitoringBaselineRepository(),
        private readonly MetricRegistry $metrics = new MetricRegistry(),
    ) {
    }

    public function capture(DateTimeImmutable $observationDate): int
    {
        global $DB;
        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }

        $rows = iterator_to_array($DB->request([
            'SELECT' => ['entities_id', 'metric_key', 'dimension_key', 'dimension_value', 'metric_value', 'sample_count'],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'WHERE' => ['rollup_date' => $observationDate->format('Y-m-d')],
            'ORDER' => ['entities_id ASC', 'metric_key ASC', 'dimension_key ASC', 'dimension_value ASC'],
        ]));
        $completionRows = iterator_to_array($DB->request([
            'SELECT' => ['entities_id', 'groups_id', 'metric_key'],
            'FROM' => 'glpi_plugin_marifex_daily_metric_observations',
            'WHERE' => ['observation_date' => $observationDate->format('Y-m-d'), 'provenance' => Provenance::OBSERVED->value],
            'ORDER' => ['entities_id ASC', 'groups_id ASC', 'metric_key ASC'],
        ]));
        $rows = $this->withCertifiedZeroMarkers($rows, $completionRows);
        if ($rows === []) {
            return 0;
        }

        $roots = [0];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'ORDER' => ['id ASC']]) as $entity) {
            $roots[] = (int) $entity['id'];
        }
        $roots = array_values(array_unique($roots));
        $created = 0;
        foreach ($roots as $root) {
            foreach ([false, true] as $recursive) {
                $entityIds = [$root];
                if ($recursive) {
                    $entityIds = array_values(array_unique(array_merge($entityIds, array_map('intval', getSonsOf('glpi_entities', $root)))));
                }
                $scopeRows = array_values(array_filter($rows, static fn (array $row): bool => in_array((int) $row['entities_id'], $entityIds, true)));
                if ($scopeRows === []) {
                    continue;
                }
                foreach ($this->byMetric($scopeRows) as $metricKey => $metricRows) {
                    if (!$this->metrics->has($metricKey)) {
                        continue;
                    }
                    $grain = $this->grain($metricKey);
                    $scope = new MonitoringScope($root, $recursive, $entityIds, null, $metricKey, $grain);
                    $created += $this->repository->establishIfAbsent($scope, $observationDate, $this->evidence($metricKey, $metricRows), ProvenanceEvidence::observed()) ? 1 : 0;
                }

                foreach ($this->groupFilteredBacklogs($scopeRows) as $groupId => $metricRows) {
                    $scope = new MonitoringScope($root, $recursive, $entityIds, $groupId, 'historical_open_backlog', 'scalar');
                    $created += $this->repository->establishIfAbsent($scope, $observationDate, $this->evidence('historical_open_backlog', $metricRows), ProvenanceEvidence::observed()) ? 1 : 0;
                }
            }
        }

        return $created;
    }

    public function captureEarliestCertifiedObservations(): int
    {
        global $DB;
        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }
        $dates = [];
        foreach ($DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('MIN(`rollup_date`) AS first_date')],
            'FROM' => 'glpi_plugin_marifex_daily_rollups',
            'GROUPBY' => ['entities_id', 'metric_key', 'dimension_key', 'dimension_value'],
            'ORDER' => ['first_date ASC'],
        ]) as $row) {
            if (!empty($row['first_date'])) {
                $dates[(string) $row['first_date']] = true;
            }
        }

        $created = 0;
        foreach (array_keys($dates) as $date) {
            $observedAt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($observedAt !== false) {
                $created += $this->capture($observedAt);
            }
        }
        return $created;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, list<array<string, mixed>>> */
    private function byMetric(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['metric_key']][] = $row;
        }
        return $grouped;
    }

    /** @param list<array<string, mixed>> $rows @return array<int, list<array<string, mixed>>> */
    private function groupFilteredBacklogs(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if ((string) $row['metric_key'] !== 'historical_group_backlog' || (string) $row['dimension_key'] !== 'group') {
                continue;
            }
            $copy = $row;
            $copy['metric_key'] = 'historical_open_backlog';
            $copy['dimension_key'] = '';
            $copy['dimension_value'] = '';
            $grouped[(int) $row['dimension_value']][] = $copy;
        }
        return $grouped;
    }

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $completions @return list<array<string, mixed>> */
    private function withCertifiedZeroMarkers(array $rows, array $completions): array
    {
        $present = [];
        $allEntityIds = array_values(array_unique(array_map(
            static fn(array $completion): int => (int) $completion['entities_id'],
            array_filter($completions, static fn(array $completion): bool => (int) $completion['groups_id'] === 0),
        )));
        foreach ($rows as $row) {
            $present[(int) $row['entities_id'] . ':' . (string) $row['metric_key'] . ':' . (string) $row['dimension_value']] = true;
            $present[(int) $row['entities_id'] . ':' . (string) $row['metric_key'] . ':*'] = true;
        }
        foreach ($completions as $completion) {
            $entityId = (int) $completion['entities_id'];
            $groupId = (int) $completion['groups_id'];
            $metricKey = (string) $completion['metric_key'];
            if ($groupId > 0) {
                foreach ($allEntityIds as $scopeEntityId) {
                    $identity = $scopeEntityId . ':historical_group_backlog:' . $groupId;
                    if (!isset($present[$identity])) {
                        $rows[] = ['entities_id' => $scopeEntityId, 'metric_key' => 'historical_group_backlog', 'dimension_key' => 'group', 'dimension_value' => (string) $groupId, 'metric_value' => 0, 'sample_count' => 0, 'is_completion_marker' => true];
                        $present[$identity] = true;
                    }
                }
                continue;
            }
            $identity = $entityId . ':' . $metricKey . ':*';
            if (!isset($present[$identity])) {
                $rows[] = ['entities_id' => $entityId, 'metric_key' => $metricKey, 'dimension_key' => '', 'dimension_value' => '', 'metric_value' => 0, 'sample_count' => 0, 'is_completion_marker' => true];
                $present[$identity] = true;
            }
        }
        return $rows;
    }

    private function grain(string $metricKey): string
    {
        $format = $this->metrics->get($metricKey)->format;
        return in_array($format, ['dimension_series', 'matrix'], true) ? 'dimension' : 'scalar';
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function evidence(string $metricKey, array $rows): array
    {
        $definition = $this->metrics->get($metricKey);
        if ($this->grain($metricKey) === 'dimension') {
            $dimensions = [];
            foreach ($rows as $row) {
                if (($row['is_completion_marker'] ?? false) === true && (string) $row['dimension_value'] === '') {
                    continue;
                }
                $key = (string) $row['dimension_value'];
                $dimensions[$key] = ($dimensions[$key] ?? 0.0) + (float) $row['metric_value'];
            }
            ksort($dimensions, SORT_NATURAL);
            return ['format' => $definition->format, 'dimensions' => $dimensions];
        }

        $samples = array_sum(array_map(static fn (array $row): int => (int) $row['sample_count'], $rows));
        $weighted = in_array($definition->format, ['duration_series', 'percentage_series', 'decimal_series'], true);
        $value = $weighted && $samples > 0
            ? array_sum(array_map(static fn (array $row): float => (float) $row['metric_value'] * (int) $row['sample_count'], $rows)) / $samples
            : array_sum(array_map(static fn (array $row): float => (float) $row['metric_value'], $rows));

        return ['format' => $definition->format, 'value' => $value, 'sample_count' => $samples];
    }
}
