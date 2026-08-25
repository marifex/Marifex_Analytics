<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GlpiPlugin\Marifex\Security\EntityScope;
use InvalidArgumentException;
use Session;

final class ReportScheduleService
{
    public function __construct(
        private readonly EntityScope $entityScope = new EntityScope(),
        private readonly ReportAuthorizationService $authorization = new ReportAuthorizationService(),
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id', 'name', 'dashboard_definitions_id', 'format', 'frequency', 'send_hour', 'weekday', 'monthday', 'timezone', 'recipients', 'is_active', 'next_run_at', 'last_run_at'],
            'FROM' => 'glpi_plugin_marifex_report_schedules',
            'WHERE' => $this->ownership(),
            'ORDER' => ['is_active DESC', 'name ASC'],
        ]), false);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['dashboard_definitions_id'] = (int) $row['dashboard_definitions_id'];
            $row['send_hour'] = (int) $row['send_hour'];
            $row['weekday'] = $row['weekday'] === null ? null : (int) $row['weekday'];
            $row['monthday'] = $row['monthday'] === null ? null : (int) $row['monthday'];
            $row['is_active'] = (int) $row['is_active'] === 1;
            $row['recipients'] = json_decode((string) $row['recipients'], true, 32, JSON_THROW_ON_ERROR);
            return $row;
        }, $rows);
    }

    /** @param array<string, mixed> $input
     *  @return list<array<string, mixed>>
     */
    public function save(?int $id, array $input): array
    {
        global $DB;
        $values = $this->validate($input);
        if ($id === null) {
            $values += $this->ownership() + ['date_creation' => gmdate('Y-m-d H:i:s')];
            $DB->insert('glpi_plugin_marifex_report_schedules', $values);
        } else {
            $this->owned($id);
            $DB->update('glpi_plugin_marifex_report_schedules', $values, $this->ownership() + ['id' => $id]);
        }
        return $this->all();
    }

    /** @return list<array<string, mixed>> */
    public function delete(int $id): array
    {
        global $DB;
        $this->owned($id);
        $DB->delete('glpi_plugin_marifex_report_schedules', $this->ownership() + ['id' => $id]);
        return $this->all();
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        global $DB;
        $name = trim((string) ($input['name'] ?? ''));
        $dashboardId = (int) ($input['dashboard_id'] ?? 0);
        $format = (string) ($input['format'] ?? 'pdf');
        $frequency = (string) ($input['frequency'] ?? 'weekly');
        $hour = (int) ($input['send_hour'] ?? 8);
        $weekday = isset($input['weekday']) ? (int) $input['weekday'] : null;
        $monthday = isset($input['monthday']) ? (int) $input['monthday'] : null;
        $timezone = (string) ($input['timezone'] ?? 'UTC');
        try {
            new DateTimeZone($timezone);
            $validTimezone = true;
        } catch (Exception) {
            $validTimezone = false;
        }
        if ($name === '' || mb_strlen($name) > 120 || !in_array($format, ['pdf', 'csv'], true)
            || !in_array($frequency, ['daily', 'weekly', 'monthly'], true) || $hour < 0 || $hour > 23
            || !$validTimezone
            || ($frequency === 'weekly' && ($weekday === null || $weekday < 1 || $weekday > 7))
            || ($frequency === 'monthly' && ($monthday === null || $monthday < 1 || $monthday > 28))) {
            throw new InvalidArgumentException('Invalid report schedule.');
        }
        $dashboard = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => $this->ownership() + ['id' => $dashboardId],
            'LIMIT' => 1,
        ])->current();
        if (!$dashboard) {
            throw new InvalidArgumentException('The dashboard is not available in this user and entity scope.');
        }
        $recursive = $this->entityScope->isRecursive();
        $recipients = $this->authorization->validateRecipients(
            is_array($input['recipients'] ?? null) ? $input['recipients'] : [],
            $this->entityScope->activeEntityId(),
            $recursive,
        );
        $schedule = compact('frequency', 'hour', 'weekday', 'monthday', 'timezone');
        return [
            'name' => $name,
            'dashboard_definitions_id' => $dashboardId,
            'is_recursive' => $recursive ? 1 : 0,
            'format' => $format,
            'frequency' => $frequency,
            'send_hour' => $hour,
            'weekday' => $frequency === 'weekly' ? $weekday : null,
            'monthday' => $frequency === 'monthly' ? $monthday : null,
            'timezone' => $timezone,
            'recipients' => json_encode($recipients, JSON_THROW_ON_ERROR),
            'is_active' => !array_key_exists('is_active', $input) || (bool) $input['is_active'] ? 1 : 0,
            'next_run_at' => self::nextRunAt($schedule)->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, mixed> $schedule */
    public static function nextRunAt(array $schedule, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $zone = new DateTimeZone((string) $schedule['timezone']);
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone);
        $candidate = $now->setTime((int) ($schedule['send_hour'] ?? $schedule['hour'] ?? 8), 0, 0);
        if ($candidate <= $now) $candidate = $candidate->modify('+1 day');
        if ($schedule['frequency'] === 'weekly') {
            while ((int) $candidate->format('N') !== (int) $schedule['weekday']) $candidate = $candidate->modify('+1 day');
        } elseif ($schedule['frequency'] === 'monthly') {
            while ((int) $candidate->format('j') !== (int) $schedule['monthday']) $candidate = $candidate->modify('+1 day');
        }
        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string, int> */
    private function ownership(): array
    {
        return ['users_id' => (int) Session::getLoginUserID(), 'entities_id' => $this->entityScope->activeEntityId()];
    }

    /** @return array<string, mixed> */
    private function owned(int $id): array
    {
        global $DB;
        $row = $DB->request(['FROM' => 'glpi_plugin_marifex_report_schedules', 'WHERE' => $this->ownership() + ['id' => $id], 'LIMIT' => 1])->current();
        if (!$row) throw new InvalidArgumentException('Report schedule is not available.');
        return $row;
    }
}
