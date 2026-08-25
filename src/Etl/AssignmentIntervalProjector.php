<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use DateTimeImmutable;

final class AssignmentIntervalProjector
{
    /** @param list<int> $ticketIds */
    public function rebuildMany(array $ticketIds): int
    {
        $rebuilt = 0;
        foreach (array_values(array_unique(array_filter($ticketIds))) as $ticketId) {
            if ($this->rebuildTicket((int) $ticketId)) {
                ++$rebuilt;
            }
        }
        return $rebuilt;
    }

    public function rebuildTicket(int $ticketId): bool
    {
        global $DB;
        $ticket = $DB->request([
            'SELECT' => ['id', 'entities_id', 'date', 'date_creation', 'date_mod'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['id' => $ticketId],
            'LIMIT' => 1,
        ])->current();
        if (!$ticket) {
            return false;
        }
        $ticketStart = (string) ($ticket['date'] ?: $ticket['date_creation'] ?: $ticket['date_mod']);

        $types = [
            EventMappingRegistry::TICKET_TECHNICIAN_ASSIGNMENT_CHANGED => 'technician',
            EventMappingRegistry::TICKET_GROUP_ASSIGNMENT_CHANGED => 'group',
        ];
        $DB->delete('glpi_plugin_marifex_state_intervals', [
            'tickets_id' => $ticketId,
            'state_type' => array_values($types),
        ]);

        foreach ($types as $eventType => $stateType) {
            $events = $DB->request([
                'SELECT' => ['id', 'occurred_at', 'old_value', 'new_value'],
                'FROM' => 'glpi_plugin_marifex_ticket_events',
                'WHERE' => ['tickets_id' => $ticketId, 'event_type' => $eventType],
                'ORDER' => ['occurred_at ASC', 'id ASC'],
            ]);
            $open = [];
            foreach ($events as $event) {
                $at = (string) $event['occurred_at'];
                $old = $this->id((string) $event['old_value']);
                $new = $this->id((string) $event['new_value']);
                if ($old !== null) {
                    $start = $open[$old] ?? ['at' => $ticketStart, 'event_id' => null];
                    if ($at > $start['at']) {
                        $this->insert($ticketId, (int) $ticket['entities_id'], $stateType, $old, $start['at'], $at, $start['event_id'], (int) $event['id']);
                    }
                    unset($open[$old]);
                }
                if ($new !== null) {
                    $open[$new] = ['at' => $at, 'event_id' => (int) $event['id']];
                }
            }
            foreach ($open as $value => $start) {
                $this->insert($ticketId, (int) $ticket['entities_id'], $stateType, (string) $value, $start['at'], null, $start['event_id'], null);
            }
        }
        return true;
    }

    private function insert(int $ticketId, int $entityId, string $type, string $value, string $start, ?string $end, ?int $sourceStart, ?int $sourceEnd): void
    {
        global $DB;
        $duration = $end === null ? null : max(0, (new DateTimeImmutable($end))->getTimestamp() - (new DateTimeImmutable($start))->getTimestamp());
        $DB->insert('glpi_plugin_marifex_state_intervals', [
            'tickets_id' => $ticketId, 'entities_id' => $entityId, 'state_type' => $type,
            'state_value' => $value, 'started_at' => $start, 'ended_at' => $end,
            'duration_seconds' => $duration, 'source_event_start_id' => $sourceStart, 'source_event_end_id' => $sourceEnd,
        ]);
    }

    private function id(string $value): ?string
    {
        return ctype_digit($value) && (int) $value > 0 ? $value : null;
    }
}
