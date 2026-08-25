<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use CommonITILValidation;
use DateTimeImmutable;
use DateTimeZone;

final class TicketOperationsSnapshotBuilder
{
    private const METRICS = [
        'open_tickets_by_priority',
        'unassigned_open_tickets',
        'average_unassigned_time',
        'tickets_approaching_sla_breach',
        'sla_breach_count',
        'sla_breach_rate',
        'sla_breaches_by_technician',
        'tickets_by_request_source',
        'created_vs_resolved_tickets',
        'assignment_changes_per_ticket',
        'technician_workload_distribution',
        'unsatisfied_survey_responses',
        'resolution_time_age_bands',
        'open_incidents_by_assignment_group',
        'created_tickets_by_request_source',
        'ticket_reopen_events',
        'ticket_resolution_events',
        'first_response_p50_seconds',
        'first_response_p75_seconds',
        'first_response_p90_seconds',
        'survey_responses_total',
        'dissatisfied_responses_total',
        'customer_dissatisfaction_rate',
        'solution_proposed_tickets',
        'solution_refused_tickets',
        'refused_solution_rate',
    ];

    private const MATRIX_METRICS = ['open_tickets_priority_category_matrix'];

    public function run(DateTimeImmutable $localDay, DateTimeZone $timezone): int
    {
        global $DB;
        $date = $localDay->format('Y-m-d');
        $startUtc = $localDay->setTimezone(new DateTimeZone('UTC'));
        $cutoffUtc = $localDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $start = $startUtc->format('Y-m-d H:i:s');
        $cutoff = $cutoffUtc->format('Y-m-d H:i:s');
        $trailing30 = $cutoffUtc->modify('-30 days')->format('Y-m-d H:i:s');
        $approachingCutoff = $cutoffUtc->modify('+1 day')->format('Y-m-d H:i:s');

        $DB->delete('glpi_plugin_marifex_daily_rollups', [
            'rollup_date' => $date,
            'metric_key' => self::METRICS,
        ]);
        $DB->delete('glpi_plugin_marifex_daily_matrix_rollups', [
            'rollup_date' => $date,
            'metric_key' => self::MATRIX_METRICS,
        ]);
        $DB->delete('glpi_plugin_marifex_daily_response_observations', ['snapshot_date' => $date]);

        $assignments = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => ['type' => 2],
        ]) as $assignment) {
            $assignments[(int) $assignment['tickets_id']][(int) $assignment['users_id']] = true;
        }

        $groupAssignments = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'groups_id'],
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => ['type' => 2],
        ]) as $assignment) {
            $groupAssignments[(int) $assignment['tickets_id']][(int) $assignment['groups_id']] = true;
        }

        $values = [];
        $ticketEntities = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'entities_id', 'date', 'date_creation', 'date_mod', 'solvedate', 'status', 'priority', 'requesttypes_id', 'slas_id_ttr', 'time_to_resolve', 'type', 'itilcategories_id', 'takeintoaccountdate', 'takeintoaccount_delay_stat'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['is_deleted' => 0, ['date_creation' => ['<', $cutoff]]],
        ]) as $ticket) {
            $ticketId = (int) $ticket['id'];
            $entityId = (int) $ticket['entities_id'];
            $ticketEntities[$ticketId] = $entityId;
            $values[$entityId] ??= $this->emptyEntity();
            $created = (string) ($ticket['date'] ?: $ticket['date_creation'] ?: $ticket['date_mod']);
            $solved = $ticket['solvedate'] ? (string) $ticket['solvedate'] : null;

            if ((string) $ticket['date_mod'] >= $start && (string) $ticket['date_mod'] < $cutoff) {
                ++$values[$entityId]['active_tickets'];
            }
            if ($created >= $start && $created < $cutoff) {
                ++$values[$entityId]['flow'][1];
                $source = max(0, (int) $ticket['requesttypes_id']);
                $values[$entityId]['created_sources'][$source] = ($values[$entityId]['created_sources'][$source] ?? 0) + 1;
            }
            if ($created >= $trailing30 && $created < $cutoff && $ticket['takeintoaccountdate'] !== null && (int) $ticket['takeintoaccount_delay_stat'] >= 0) {
                $values[$entityId]['first_response_delays'][] = (int) $ticket['takeintoaccount_delay_stat'];
                $DB->insert('glpi_plugin_marifex_daily_response_observations', [
                    'snapshot_date' => $date,
                    'entities_id' => $entityId,
                    'tickets_id' => $ticketId,
                    'delay_seconds' => (int) $ticket['takeintoaccount_delay_stat'],
                ]);
            }
            if ($solved !== null && $solved >= $start && $solved < $cutoff) {
                ++$values[$entityId]['flow'][2];
                $seconds = max(0, (new DateTimeImmutable($solved))->getTimestamp() - (new DateTimeImmutable($created))->getTimestamp());
                $band = $seconds < 86400 ? 1 : ($seconds < 259200 ? 2 : ($seconds < 604800 ? 3 : ($seconds < 2592000 ? 4 : 5)));
                ++$values[$entityId]['resolution_bands'][$band];
            }

            $isOpen = $created < $cutoff && ($solved === null || $solved >= $cutoff);
            if (!$isOpen) {
                continue;
            }
            $priority = max(1, min(6, (int) $ticket['priority']));
            ++$values[$entityId]['priorities'][$priority];
            $category = max(0, (int) $ticket['itilcategories_id']);
            ++$values[$entityId]['priority_category'][$priority][$category];
            $source = max(0, (int) $ticket['requesttypes_id']);
            ++$values[$entityId]['sources'][$source];

            if ((int) $ticket['type'] === 1) {
                $groups = array_keys($groupAssignments[$ticketId] ?? []);
                if ($groups === []) {
                    ++$values[$entityId]['incident_groups'][0];
                } else {
                    foreach ($groups as $groupId) {
                        ++$values[$entityId]['incident_groups'][$groupId];
                    }
                }
            }

            $technicians = array_keys($assignments[$ticketId] ?? []);
            if ($technicians === []) {
                ++$values[$entityId]['unassigned'];
                $values[$entityId]['unassigned_seconds'] += max(0, $cutoffUtc->getTimestamp() - (new DateTimeImmutable($created))->getTimestamp());
            } else {
                foreach ($technicians as $userId) {
                    ++$values[$entityId]['technician_workload'][$userId];
                }
            }

            $deadline = $ticket['time_to_resolve'] ? (string) $ticket['time_to_resolve'] : null;
            if ($deadline !== null && $deadline >= $cutoff && $deadline < $approachingCutoff) {
                ++$values[$entityId]['approaching_sla'];
            }
            if ((int) $ticket['slas_id_ttr'] > 0 && $deadline !== null) {
                ++$values[$entityId]['sla_population'];
                if ($deadline < $cutoff) {
                    ++$values[$entityId]['sla_breaches'];
                    foreach ($technicians as $userId) {
                        ++$values[$entityId]['sla_by_technician'][$userId];
                    }
                }
            }
        }

        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'old_value', 'new_value'],
            'FROM' => 'glpi_plugin_marifex_ticket_events',
            'WHERE' => [
                'event_type' => EventMappingRegistry::TICKET_STATUS_CHANGED,
                ['occurred_at' => ['>=' , $start]],
                ['occurred_at' => ['<' , $cutoff]],
            ],
        ]) as $event) {
            $entityId = $ticketEntities[(int) $event['tickets_id']] ?? null;
            if ($entityId === null) {
                continue;
            }
            $values[$entityId] ??= $this->emptyEntity();
            $old = (int) $event['old_value'];
            $new = (int) $event['new_value'];
            if (in_array($old, [5, 6], true) && in_array($new, [1, 2, 3, 4], true)) {
                ++$values[$entityId]['reopen_events'];
            }
            if (in_array($old, [1, 2, 3, 4], true) && in_array($new, [5, 6], true)) {
                ++$values[$entityId]['resolution_events'];
            }
        }

        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'date_answered', 'satisfaction_scaled_to_5'],
            'FROM' => 'glpi_ticketsatisfactions',
            'WHERE' => [
                ['date_answered' => ['>=', $trailing30]],
                ['date_answered' => ['<', $cutoff]],
            ],
        ]) as $survey) {
            if ($survey['satisfaction_scaled_to_5'] === null) {
                continue;
            }
            $entityId = $ticketEntities[(int) $survey['tickets_id']] ?? null;
            if ($entityId !== null) {
                $values[$entityId] ??= $this->emptyEntity();
                ++$values[$entityId]['survey_total'];
                if ((float) $survey['satisfaction_scaled_to_5'] <= 2.0) {
                    ++$values[$entityId]['dissatisfied_total'];
                }
                if ((string) $survey['date_answered'] >= $start && (float) $survey['satisfaction_scaled_to_5'] <= 2.0) {
                    ++$values[$entityId]['unsatisfied'];
                }
            }
        }

        $proposed = [];
        $refused = [];
        foreach ($DB->request([
            'SELECT' => ['items_id', 'status'],
            'FROM' => 'glpi_itilsolutions',
            'WHERE' => [
                'itemtype' => 'Ticket',
                ['date_creation' => ['>=', $trailing30]],
                ['date_creation' => ['<', $cutoff]],
            ],
        ]) as $solution) {
            $ticketId = (int) $solution['items_id'];
            $entityId = $ticketEntities[$ticketId] ?? null;
            if ($entityId === null) {
                continue;
            }
            $proposed[$entityId][$ticketId] = true;
            if ((int) $solution['status'] === CommonITILValidation::REFUSED) {
                $refused[$entityId][$ticketId] = true;
            }
        }
        foreach (array_unique(array_merge(array_keys($proposed), array_keys($refused))) as $entityId) {
            $values[$entityId] ??= $this->emptyEntity();
            $values[$entityId]['solution_proposed'] = count($proposed[$entityId] ?? []);
            $values[$entityId]['solution_refused'] = count($refused[$entityId] ?? []);
        }

        foreach ($DB->request([
            'SELECT' => ['tickets_id'],
            'FROM' => 'glpi_plugin_marifex_ticket_events',
            'WHERE' => [
                'event_type' => EventMappingRegistry::TICKET_TECHNICIAN_ASSIGNMENT_CHANGED,
                ['occurred_at' => ['>=', $start]],
                ['occurred_at' => ['<', $cutoff]],
            ],
        ]) as $event) {
            $entityId = $ticketEntities[(int) $event['tickets_id']] ?? null;
            if ($entityId !== null) {
                $values[$entityId] ??= $this->emptyEntity();
                ++$values[$entityId]['assignment_changes'];
            }
        }

        $written = 0;
        foreach ($values as $entityId => $entity) {
            // A zero marker proves the daily demand-flow collector completed;
            // it is not an "unknown source" ticket and contributes no value.
            if ($entity['created_sources'] === []) {
                $entity['created_sources'][0] = 0;
            }
            $written += $this->writeDimensions($date, $entityId, 'open_tickets_by_priority', 'priority', $entity['priorities']);
            $written += $this->writeDimensions($date, $entityId, 'tickets_by_request_source', 'request_type', $entity['sources']);
            $written += $this->writeDimensions($date, $entityId, 'created_vs_resolved_tickets', 'flow', $entity['flow']);
            $written += $this->writeDimensions($date, $entityId, 'sla_breaches_by_technician', 'technician', $entity['sla_by_technician']);
            $written += $this->writeDimensions($date, $entityId, 'technician_workload_distribution', 'technician', $entity['technician_workload']);
            $written += $this->writeDimensions($date, $entityId, 'resolution_time_age_bands', 'age_band', $entity['resolution_bands']);
            $written += $this->writeDimensions($date, $entityId, 'open_incidents_by_assignment_group', 'group', $entity['incident_groups']);
            $written += $this->writeDimensions($date, $entityId, 'created_tickets_by_request_source', 'request_type', $entity['created_sources']);
            foreach ($entity['priority_category'] as $priority => $categories) {
                foreach ($categories as $category => $value) {
                    $DB->insert('glpi_plugin_marifex_daily_matrix_rollups', [
                        'rollup_date' => $date, 'entities_id' => $entityId,
                        'metric_key' => 'open_tickets_priority_category_matrix',
                        'row_key' => 'priority', 'row_value' => (string) $priority,
                        'column_key' => 'itil_category', 'column_value' => (string) $category,
                        'metric_value' => $value,
                    ]);
                    ++$written;
                }
            }
            $this->writeRollup($date, $entityId, 'unassigned_open_tickets', $entity['unassigned'], max(1, $entity['unassigned']));
            $this->writeRollup($date, $entityId, 'average_unassigned_time', $entity['unassigned'] > 0 ? $entity['unassigned_seconds'] / $entity['unassigned'] : 0, max(1, $entity['unassigned']));
            $this->writeRollup($date, $entityId, 'tickets_approaching_sla_breach', $entity['approaching_sla'], max(1, $entity['approaching_sla']));
            $this->writeRollup($date, $entityId, 'sla_breach_count', $entity['sla_breaches'], max(1, $entity['sla_breaches']));
            $this->writeRollup($date, $entityId, 'sla_breach_rate', $entity['sla_population'] > 0 ? ($entity['sla_breaches'] / $entity['sla_population']) * 100 : 0, max(1, $entity['sla_population']));
            $this->writeRollup($date, $entityId, 'assignment_changes_per_ticket', $entity['active_tickets'] > 0 ? $entity['assignment_changes'] / $entity['active_tickets'] : 0, max(1, $entity['active_tickets']));
            $this->writeRollup($date, $entityId, 'unsatisfied_survey_responses', $entity['unsatisfied'], max(1, $entity['unsatisfied']));
            $this->writeRollup($date, $entityId, 'ticket_reopen_events', $entity['reopen_events'], max(1, $entity['reopen_events']));
            $this->writeRollup($date, $entityId, 'ticket_resolution_events', $entity['resolution_events'], max(1, $entity['resolution_events']));
            $delays = $entity['first_response_delays'];
            $delayCount = count($delays);
            $this->writeRollup($date, $entityId, 'first_response_p50_seconds', $this->nearestRank($delays, 0.50), $delayCount);
            $this->writeRollup($date, $entityId, 'first_response_p75_seconds', $this->nearestRank($delays, 0.75), $delayCount);
            $this->writeRollup($date, $entityId, 'first_response_p90_seconds', $this->nearestRank($delays, 0.90), $delayCount);
            $this->writeRollup($date, $entityId, 'survey_responses_total', $entity['survey_total'], $entity['survey_total']);
            $this->writeRollup($date, $entityId, 'dissatisfied_responses_total', $entity['dissatisfied_total'], $entity['survey_total']);
            $this->writeRollup($date, $entityId, 'customer_dissatisfaction_rate', $entity['survey_total'] > 0 ? $entity['dissatisfied_total'] / $entity['survey_total'] * 100 : 0, $entity['survey_total']);
            $this->writeRollup($date, $entityId, 'solution_proposed_tickets', $entity['solution_proposed'], $entity['solution_proposed']);
            $this->writeRollup($date, $entityId, 'solution_refused_tickets', $entity['solution_refused'], $entity['solution_proposed']);
            $this->writeRollup($date, $entityId, 'refused_solution_rate', $entity['solution_proposed'] > 0 ? $entity['solution_refused'] / $entity['solution_proposed'] * 100 : 0, $entity['solution_proposed']);
            $written += 18;
        }
        return $written;
    }

    /** @return array<string, mixed> */
    private function emptyEntity(): array
    {
        return [
            'priorities' => [], 'sources' => [], 'flow' => [1 => 0, 2 => 0],
            'sla_by_technician' => [], 'technician_workload' => [], 'resolution_bands' => [],
            'unassigned' => 0, 'unassigned_seconds' => 0, 'approaching_sla' => 0,
            'sla_population' => 0, 'sla_breaches' => 0, 'assignment_changes' => 0,
            'active_tickets' => 0, 'unsatisfied' => 0,
            'incident_groups' => [], 'priority_category' => [],
            'created_sources' => [], 'reopen_events' => 0, 'resolution_events' => 0,
            'first_response_delays' => [], 'survey_total' => 0, 'dissatisfied_total' => 0,
            'solution_proposed' => 0, 'solution_refused' => 0,
        ];
    }

    /** @param list<int> $values */
    private function nearestRank(array $values, float $percentile): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $index = max(0, min(count($values) - 1, (int) ceil($percentile * count($values)) - 1));
        return $values[$index];
    }

    /** @param array<int, int> $values */
    private function writeDimensions(string $date, int $entityId, string $metric, string $dimension, array $values): int
    {
        $written = 0;
        foreach ($values as $id => $value) {
            $this->writeRollup($date, $entityId, $metric, $value, max(1, $value), $dimension, (string) $id);
            ++$written;
        }
        return $written;
    }

    private function writeRollup(string $date, int $entityId, string $metric, float|int $value, int $samples, string $dimension = '', string $dimensionValue = ''): void
    {
        global $DB;
        $DB->insert('glpi_plugin_marifex_daily_rollups', [
            'rollup_date' => $date, 'entities_id' => $entityId, 'metric_key' => $metric,
            'dimension_key' => $dimension, 'dimension_value' => $dimensionValue,
            'metric_value' => $value, 'sample_count' => $samples,
        ]);
    }
}
