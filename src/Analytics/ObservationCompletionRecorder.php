<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

use DateTimeImmutable;
use DBmysql;
use GlpiPlugin\Marifex\Metric\MetricRegistry;
use RuntimeException;

final class ObservationCompletionRecorder
{
    public function __construct(private readonly MetricRegistry $metrics = new MetricRegistry())
    {
    }

    public function record(DateTimeImmutable $observationDate): int
    {
        global $DB;
        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }
        $date = $observationDate->format('Y-m-d');
        $DB->delete('glpi_plugin_marifex_daily_metric_observations', ['observation_date' => $date]);
        $entityIds = [0];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'ORDER' => ['id ASC']]) as $entity) {
            $entityIds[] = (int) $entity['id'];
        }
        $metricKeys = array_map(
            static fn($definition): string => $definition->key,
            array_filter($this->metrics->all(), static fn($definition): bool => $definition->source === 'data_mart'),
        );
        $written = 0;
        foreach (array_values(array_unique($entityIds)) as $entityId) {
            foreach ($metricKeys as $metricKey) {
                $this->insert($date, $entityId, $metricKey, 0);
                ++$written;
            }
        }
        foreach ($DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_groups', 'ORDER' => ['id ASC']]) as $group) {
            $this->insert($date, (int) $group['entities_id'], 'historical_open_backlog', (int) $group['id']);
            ++$written;
        }
        return $written;
    }

    private function insert(string $date, int $entityId, string $metricKey, int $groupId): void
    {
        global $DB;
        $DB->insert('glpi_plugin_marifex_daily_metric_observations', [
            'observation_date' => $date,
            'entities_id' => $entityId,
            'groups_id' => $groupId,
            'metric_key' => $metricKey,
            'provenance' => Provenance::OBSERVED->value,
        ]);
    }
}
