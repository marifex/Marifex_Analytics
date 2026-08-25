<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use GLPIMailer;
use GlpiPlugin\Marifex\Profile;
use InvalidArgumentException;

final class ReportAuthorizationService
{
    public function canExecute(int $userId, int $entityId, bool $recursive = false): bool
    {
        global $DB;
        $user = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_users',
            'WHERE' => ['id' => $userId, 'is_active' => 1, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();
        if (!$user) {
            return false;
        }
        foreach ($this->scopeEntityIds($entityId, $recursive) as $scopeEntityId) {
            if (!$this->hasRightInEntity($userId, $scopeEntityId, Profile::RIGHT_EXPORT, READ)
                || !$this->hasRightInEntity($userId, $scopeEntityId, Profile::RIGHT_SCHEDULE, UPDATE)) {
                return false;
            }
        }
        return true;
    }

    public function hasRightInEntity(int $userId, int $entityId, string $right, int $required): bool
    {
        global $DB;
        $assignments = iterator_to_array($DB->request([
            'SELECT' => ['profiles_id', 'entities_id', 'is_recursive'],
            'FROM' => 'glpi_profiles_users',
            'WHERE' => ['users_id' => $userId],
        ]), false);
        if ($assignments === []) {
            return false;
        }
        $profileIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['profiles_id'], $assignments)));
        $rights = [];
        foreach ($DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM' => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $profileIds, 'name' => $right],
        ]) as $row) {
            $rights[(int) $row['profiles_id']] = (int) $row['rights'];
        }
        foreach ($assignments as $assignment) {
            if (((int) ($rights[(int) $assignment['profiles_id']] ?? 0) & $required) !== $required) {
                continue;
            }
            $root = (int) $assignment['entities_id'];
            if ($root === $entityId || ((int) $assignment['is_recursive'] === 1 && in_array($entityId, array_map('intval', getSonsOf('glpi_entities', $root)), true))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public function validateRecipients(array $input, int $entityId, bool $recursive = false): array
    {
        global $DB;
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn(mixed $email): string => mb_strtolower(trim((string) $email)),
            $input
        ))));
        if ($recipients === [] || count($recipients) > 20) {
            throw new InvalidArgumentException('A schedule requires between 1 and 20 recipients.');
        }
        foreach ($recipients as $email) {
            if (!GLPIMailer::validateAddress($email)) {
                throw new InvalidArgumentException('A report recipient address is invalid.');
            }
            $users = $DB->request([
                'SELECT' => ['glpi_users.id'],
                'FROM' => 'glpi_useremails',
                'INNER JOIN' => ['glpi_users' => ['ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']]],
                'WHERE' => ['glpi_useremails.email' => $email, 'glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0],
            ]);
            $authorized = false;
            foreach ($users as $user) {
                $authorized = true;
                foreach ($this->scopeEntityIds($entityId, $recursive) as $scopeEntityId) {
                    if (!$this->hasRightInEntity((int) $user['id'], $scopeEntityId, Profile::RIGHT_DASHBOARD, READ)) {
                        $authorized = false;
                        break;
                    }
                }
                if ($authorized) {
                    break;
                }
            }
            if (!$authorized) {
                throw new InvalidArgumentException('Every recipient must be an active GLPI user allowed to view the report entity.');
            }
        }
        return $recipients;
    }

    /** @return list<int> */
    private function scopeEntityIds(int $entityId, bool $recursive): array
    {
        $entityIds = [$entityId];
        if ($recursive) {
            $entityIds = array_merge($entityIds, array_map('intval', getSonsOf('glpi_entities', $entityId)));
        }
        return array_values(array_unique($entityIds));
    }
}
