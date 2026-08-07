<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use DateTimeImmutable;

final class StateIntervalProjector
{
    public function project(
        int $ticketId,
        int $entityId,
        string $stateType,
        string $stateValue,
        string $observedAt,
    ): void {
        global $DB;
        $open = $DB->request([
            'FROM' => 'glpi_plugin_marifex_state_intervals',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'state_type' => $stateType,
                'ended_at' => null,
            ],
            'ORDER' => ['started_at DESC'],
            'LIMIT' => 1,
        ])->current();

        if ($open && (string) $open['state_value'] === $stateValue) {
            return;
        }

        if ($open) {
            $start = new DateTimeImmutable((string) $open['started_at']);
            $end = new DateTimeImmutable($observedAt);
            if ($end >= $start) {
                $DB->update('glpi_plugin_marifex_state_intervals', [
                    'ended_at' => $observedAt,
                    'duration_seconds' => $end->getTimestamp() - $start->getTimestamp(),
                ], ['id' => (int) $open['id'], 'ended_at' => null]);
            }
        }

        $DB->updateOrInsert('glpi_plugin_marifex_state_intervals', [
            'entities_id' => $entityId,
            'state_value' => $stateValue,
            'ended_at' => null,
            'duration_seconds' => null,
        ], [
            'tickets_id' => $ticketId,
            'state_type' => $stateType,
            'started_at' => $observedAt,
        ]);
    }
}
