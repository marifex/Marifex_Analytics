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

final class StateIntervalProjector
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
            'SELECT' => ['id', 'entities_id', 'date', 'date_creation', 'date_mod', 'status'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['id' => $ticketId],
            'LIMIT' => 1,
        ])->current();
        if (!$ticket) {
            return false;
        }

        $events = iterator_to_array($DB->request([
            'SELECT' => ['id', 'occurred_at', 'old_value', 'new_value'],
            'FROM' => 'glpi_plugin_marifex_ticket_events',
            'WHERE' => ['tickets_id' => $ticketId, 'event_type' => 'ticket_status_changed'],
            'ORDER' => ['occurred_at ASC', 'id ASC'],
        ]));
        $changes = [];
        foreach ($events as $event) {
            $changes[(string) $event['occurred_at']] = $event;
        }
        $changes = array_values($changes);

        $state = $changes !== [] && $this->isStatus($changes[0]['old_value'])
            ? (string) $changes[0]['old_value']
            : (string) $ticket['status'];
        $startedAt = (string) ($ticket['date'] ?: $ticket['date_creation'] ?: $ticket['date_mod']);
        $sourceStartId = null;

        $DB->delete('glpi_plugin_marifex_state_intervals', [
            'tickets_id' => $ticketId,
            'state_type' => 'status',
        ]);

        foreach ($changes as $event) {
            if (!$this->isStatus($event['new_value']) || (string) $event['occurred_at'] < $startedAt) {
                continue;
            }
            $newState = (string) $event['new_value'];
            if ($newState === $state) {
                continue;
            }
            if ((string) $event['occurred_at'] === $startedAt) {
                $state = $newState;
                $sourceStartId = (int) $event['id'];
                continue;
            }
            $this->insertInterval($ticketId, (int) $ticket['entities_id'], $state, $startedAt, (string) $event['occurred_at'], $sourceStartId, (int) $event['id']);
            $state = $newState;
            $startedAt = (string) $event['occurred_at'];
            $sourceStartId = (int) $event['id'];
        }

        $this->insertInterval($ticketId, (int) $ticket['entities_id'], $state, $startedAt, null, $sourceStartId, null);
        return true;
    }

    private function insertInterval(int $ticketId, int $entityId, string $state, string $startedAt, ?string $endedAt, ?int $sourceStartId, ?int $sourceEndId): void
    {
        global $DB;
        $duration = $endedAt === null ? null : max(0, (new DateTimeImmutable($endedAt))->getTimestamp() - (new DateTimeImmutable($startedAt))->getTimestamp());
        $DB->insert('glpi_plugin_marifex_state_intervals', [
            'tickets_id' => $ticketId,
            'entities_id' => $entityId,
            'state_type' => 'status',
            'state_value' => $state,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'source_event_start_id' => $sourceStartId,
            'source_event_end_id' => $sourceEndId,
        ]);
    }

    private function isStatus(mixed $value): bool
    {
        return in_array((string) $value, ['1', '2', '3', '4', '5', '6'], true);
    }
}
