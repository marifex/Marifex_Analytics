<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use GLPIMailer;
use GlpiPlugin\Marifex\Profile;
use InvalidArgumentException;

final class ReportAuthorizationService
{
    public function canExecute(int $userId, int $entityId): bool
    {
        global $DB;
        $user = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_users',
            'WHERE' => ['id' => $userId, 'is_active' => 1, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();
        return (bool) $user
            && $this->hasRightInEntity($userId, $entityId, Profile::RIGHT_EXPORT, READ)
            && $this->hasRightInEntity($userId, $entityId, Profile::RIGHT_SCHEDULE, UPDATE);
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
            if ($root === $entityId || ((int) $assignment['is_recursive'] === 1 && in_array($entityId, getSonsOf('glpi_entities', $root), true))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public function validateRecipients(array $input, int $entityId): array
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
                if ($this->hasRightInEntity((int) $user['id'], $entityId, Profile::RIGHT_DASHBOARD, READ)) {
                    $authorized = true;
                    break;
                }
            }
            if (!$authorized) {
                throw new InvalidArgumentException('Every recipient must be an active GLPI user allowed to view the report entity.');
            }
        }
        return $recipients;
    }
}
