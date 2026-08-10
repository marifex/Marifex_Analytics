<?php

declare(strict_types=1);

/**
 * Direct, deterministic GLPI 11 acceptance-data loader for the Laragon test DB.
 *
 * This intentionally does not bootstrap or execute the Experience Kit plugin.
 * It writes a clearly marked operational-ticket cohort and its native relations
 * so MarifeX can rebuild analytics through the normal ETL path.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['confirm:', 'replace']);
if (($options['confirm'] ?? '') !== 'SEED_LARAGON_TEST_DB') {
    fwrite(STDERR, "Refused. Pass --confirm=SEED_LARAGON_TEST_DB.\n");
    exit(2);
}

$db = new mysqli('127.0.0.1', 'root', '', 'glpi11_db');
$db->set_charset('utf8mb4');
$db->query("SET time_zone = '+00:00'");
$marker = 'MARIFEX-UAT-';
$existing = (int) $db->query("SELECT COUNT(*) c FROM glpi_tickets WHERE externalid LIKE 'MARIFEX-UAT-%'")->fetch_assoc()['c'];
if ($existing > 0 && !array_key_exists('replace', $options)) {
    throw new RuntimeException("A {$existing}-ticket MARIFEX-UAT cohort already exists. Use --replace to rebuild it.");
}

/** @return string */
function sqlValue(mysqli $db, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . $db->real_escape_string((string) $value) . "'";
}

/** @param list<list<mixed>> $rows */
function insertRows(mysqli $db, string $table, array $columns, array $rows): void
{
    foreach (array_chunk($rows, 250) as $chunk) {
        $values = array_map(
            static fn(array $row): string => '(' . implode(',', array_map(static fn(mixed $value): string => sqlValue($db, $value), $row)) . ')',
            $chunk,
        );
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES %s',
            $table,
            implode('`,`', $columns),
            implode(',', $values),
        );
        if (!$db->query($sql)) {
            throw new RuntimeException("Insert into {$table} failed: {$db->error}");
        }
    }
}

/** @return array<int, list<int>> */
function requestersByEntity(mysqli $db): array
{
    $result = [2 => [], 3 => [], 4 => []];
    $rows = $db->query(
        "SELECT DISTINCT pu.entities_id, pu.users_id
         FROM glpi_profiles_users pu
         JOIN glpi_users u ON u.id=pu.users_id AND u.is_active=1
         WHERE pu.entities_id IN (2,3,4)
         ORDER BY pu.entities_id,pu.users_id"
    );
    while ($row = $rows->fetch_assoc()) {
        $result[(int) $row['entities_id']][] = (int) $row['users_id'];
    }
    foreach ($result as $entity => $ids) {
        if ($ids === []) {
            throw new RuntimeException("No active requester is available for entity {$entity}.");
        }
    }
    return $result;
}

function at(DateTimeImmutable $day, int $hour, int $minute = 0): DateTimeImmutable
{
    return $day->setTime($hour, $minute);
}

function fmt(?DateTimeImmutable $date): ?string
{
    return $date?->format('Y-m-d H:i:s');
}

$ticketColumns = [
    'id', 'entities_id', 'name', 'date', 'closedate', 'solvedate', 'takeintoaccountdate', 'date_mod',
    'users_id_lastupdater', 'status', 'users_id_recipient', 'requesttypes_id', 'content', 'urgency',
    'impact', 'priority', 'itilcategories_id', 'type', 'global_validation', 'slas_id_ttr',
    'time_to_resolve', 'time_to_own', 'takeintoaccount_delay_stat', 'actiontime', 'is_deleted',
    'date_creation', 'externalid',
];
$logColumns = [
    'itemtype', 'items_id', 'itemtype_link', 'linked_action', 'user_name', 'date_mod',
    'id_search_option', 'old_value', 'new_value', 'old_id', 'new_id',
];

$subjects = [
    'VPN disconnects during business calls', 'Shared drive access is unavailable',
    'Application freezes when opening reports', 'Password reset for shared mailbox',
    'Laptop performance degradation', 'Network printer is not responding',
    'Request for analytics dashboard access', 'Software installation approval required',
];
$categories = [13, 20, 10, 19, 2, 4, 8, 9];
$groups = [13, 13, 13, 13, 14, 18, 15, 17];
$groupNames = [13 => 'Service Desk L1', 14 => 'Service Desk L2', 15 => 'Network Team', 17 => 'Security Team', 18 => 'Applications Team'];
$technicians = [4, 7, 47, 194];
$technicianNames = [4 => 'tech', 7 => 'elizabeth.robinson', 47 => 'kenneth.adams', 194 => 'steven.allen'];
$requesters = requestersByEntity($db);
$entities = [2, 2, 2, 2, 3, 3, 4];
$computerIds = [];
$computers = $db->query('SELECT id FROM glpi_computers WHERE is_deleted=0 AND entities_id IN (2,3,4) ORDER BY id LIMIT 30');
while ($computer = $computers->fetch_assoc()) {
    $computerIds[] = (int) $computer['id'];
}
if ($computerIds === []) {
    throw new RuntimeException('No computers are available for repeat-incident links.');
}

$end = new DateTimeImmutable('yesterday', new DateTimeZone('UTC'));
$start = $end->sub(new DateInterval('P729D'));
$nextTicketId = (int) $db->query('SELECT COALESCE(MAX(id),0)+1 id FROM glpi_tickets')->fetch_assoc()['id'];
$ticketRows = $requesterRows = $assigneeRows = $groupRows = $logRows = $surveyRows = $solutionRows = $itemRows = [];
$counts = ['tickets' => 0, 'closed' => 0, 'open' => 0, 'logs' => 0, 'surveys' => 0, 'solutions' => 0, 'computer_links' => 0];

$db->begin_transaction();
try {
    if ($existing > 0) {
        $db->query('CREATE TEMPORARY TABLE mx_uat_ticket_ids (`id` int unsigned PRIMARY KEY)');
        $db->query("INSERT INTO mx_uat_ticket_ids SELECT id FROM glpi_tickets WHERE externalid LIKE 'MARIFEX-UAT-%'");
        foreach ([
            'glpi_tickets_users' => 'tickets_id', 'glpi_groups_tickets' => 'tickets_id',
            'glpi_ticketsatisfactions' => 'tickets_id', 'glpi_items_tickets' => 'tickets_id',
        ] as $table => $column) {
            $db->query("DELETE child FROM {$table} child JOIN mx_uat_ticket_ids seed ON seed.id=child.{$column}");
        }
        $db->query("DELETE child FROM glpi_itilsolutions child JOIN mx_uat_ticket_ids seed ON seed.id=child.items_id WHERE child.itemtype='Ticket'");
        $db->query("DELETE child FROM glpi_logs child JOIN mx_uat_ticket_ids seed ON seed.id=child.items_id WHERE child.itemtype='Ticket'");
        $db->query('DELETE ticket FROM glpi_tickets ticket JOIN mx_uat_ticket_ids seed ON seed.id=ticket.id');
        $db->query('DROP TEMPORARY TABLE mx_uat_ticket_ids');
        $nextTicketId = (int) $db->query('SELECT COALESCE(MAX(id),0)+1 id FROM glpi_tickets')->fetch_assoc()['id'];
    }

    for ($dayIndex = 0; $dayIndex < 730; ++$dayIndex) {
        $day = $start->add(new DateInterval('P' . $dayIndex . 'D'));
        $age = 729 - $dayIndex;
        $daily = $age < 7 ? 10 : ($age < 30 ? 8 : ($age < 90 ? 6 : ($age < 365 ? 4 : 2)));

        for ($sequence = 0; $sequence < $daily; ++$sequence) {
            $ticketId = $nextTicketId++;
            $entityId = $entities[($dayIndex + $sequence) % count($entities)];
            $entityRequesters = $requesters[$entityId];
            $requesterId = $entityRequesters[($ticketId + $sequence) % count($entityRequesters)];
            $type = (($ticketId + $dayIndex) % 10) < 7 ? 1 : 2;
            $subjectIndex = ($ticketId + $sequence) % count($subjects);
            $priority = $age < 365
                ? [2, 3, 3, 4, 4, 5][($ticketId + $dayIndex) % 6]
                : [1, 2, 2, 3, 3, 4][($ticketId + $dayIndex) % 6];
            $groupId = $groups[($ticketId + ($age < 365 ? 0 : 3)) % count($groups)];
            $unassigned = ($ticketId % ($age < 365 ? 5 : 12)) === 0;
            $technicianId = $technicians[($age < 90 ? 1 : $ticketId) % count($technicians)];
            $created = at($day, 8 + (($ticketId * 3) % 10), ($ticketId * 7) % 60);
            $responseDelay = 300 + (($ticketId * 97) % 1800) + ($age < 365 ? 1800 : 0) + ($age < 30 ? 1800 : 0);
            $taken = $created->add(new DateInterval('PT' . $responseDelay . 'S'));
            $closeProbability = $age < 7 ? 45 : ($age < 30 ? 60 : ($age < 365 ? 78 : 94));
            $closed = (($ticketId * 37 + $dayIndex) % 100) < $closeProbability;
            $solveDays = 1 + (($ticketId * 11) % ($age < 365 ? 14 : 6));
            $solved = $closed ? $created->add(new DateInterval('P' . $solveDays . 'D')) : null;
            if ($solved !== null && $solved > $end->setTime(23, 59, 59)) {
                $closed = false;
                $solved = null;
            }
            $closedAt = $solved?->add(new DateInterval('PT1H'));
            $status = $closed ? 6 : ($unassigned ? 1 : 2);
            $slaId = $type === 1 ? 2 : 4;
            if (!$closed && ($ticketId % 5) === 0) {
                $resolveBy = $end->sub(new DateInterval('P' . (1 + ($ticketId % 10)) . 'D'));
            } elseif (!$closed && ($ticketId % 7) === 0) {
                $resolveBy = $end->add(new DateInterval('PT' . (2 + ($ticketId % 20)) . 'H'));
            } else {
                $resolveBy = $created->add(new DateInterval('P' . (2 + ($ticketId % 5)) . 'D'));
            }
            $requestType = [1, 1, 1, 2, 3, 4][($ticketId + $dayIndex) % 6];
            $externalId = sprintf('%s%s-%03d', $marker, $day->format('Ymd'), $sequence + 1);
            $name = '[MX-UAT] ' . $subjects[$subjectIndex];
            $dateMod = $closedAt ?? $created;

            $ticketRows[] = [
                $ticketId, $entityId, $name, fmt($created), fmt($closedAt), fmt($solved), fmt($taken), fmt($dateMod),
                2, $status, $requesterId, $requestType,
                'MarifeX analytical acceptance record. Opened from dashboard evidence drilldown. ' . $subjects[$subjectIndex],
                max(1, min(5, $priority)), max(1, min(5, $priority)), $priority, $categories[$subjectIndex], $type,
                1, $slaId, fmt($resolveBy), fmt($taken), $responseDelay, 900 + (($ticketId * 13) % 7200), 0,
                fmt($created), $externalId,
            ];
            $requesterRows[] = [$ticketId, $requesterId, 1, 0, null];
            $groupRows[] = [$ticketId, $groupId, 2];
            $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($created), 8, '', $groupNames[$groupId] . " ({$groupId})", null, $groupId];
            if (!$unassigned) {
                $assigneeRows[] = [$ticketId, $technicianId, 2, 0, null];
                $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($created->add(new DateInterval('PT5M'))), 5, '', $technicianNames[$technicianId] . " ({$technicianId})", null, $technicianId];
                $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($created->add(new DateInterval('PT5M'))), 12, '1', '2', null, null];
            }
            if ($closed && $solved !== null && $closedAt !== null) {
                $reopened = $age < 365 && ($ticketId % 13) === 0 && $solved->add(new DateInterval('P2D')) < $end;
                $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($solved), 12, '2', '5', null, null];
                if ($reopened) {
                    $reopenAt = $solved->add(new DateInterval('PT2H'));
                    $resolvedAgain = $reopenAt->add(new DateInterval('P1D'));
                    $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($reopenAt), 12, '5', '2', null, null];
                    $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($resolvedAgain), 12, '2', '5', null, null];
                    $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($resolvedAgain->add(new DateInterval('PT1H'))), 12, '5', '6', null, null];
                } else {
                    $logRows[] = ['Ticket', $ticketId, '', 0, 'MarifeX UAT Seeder', fmt($closedAt), 12, '5', '6', null, null];
                }
                if (($ticketId % 3) === 0) {
                    $rating = $age < 365 && ($ticketId % 4) === 0 ? 2 : (3 + ($ticketId % 3));
                    $surveyRows[] = [$ticketId, 1, fmt($closedAt), fmt($closedAt->add(new DateInterval('P1D'))), $rating, $rating, '[MX-UAT] Deterministic acceptance response'];
                }
                if (($ticketId % 4) === 0) {
                    $refused = $age < 365 ? (($ticketId % 3) === 0) : (($ticketId % 11) === 0);
                    $solutionRows[] = ['Ticket', $ticketId, 0, null, '[MX-UAT] Governed resolution evidence', fmt($solved), fmt($solved), $refused ? null : fmt($solved), 2, 'MarifeX UAT Seeder', 2, $refused ? 0 : 2, $refused ? null : 'glpi', $refused ? 4 : 3, null];
                }
            }
            if ($type === 1 && ($ticketId % 4) === 0) {
                $computerId = $computerIds[($ticketId + $dayIndex) % min(20, count($computerIds))];
                $itemRows[] = ['Computer', $computerId, $ticketId];
            }
            ++$counts['tickets'];
            ++$counts[$closed ? 'closed' : 'open'];
        }
    }

    insertRows($db, 'glpi_tickets', $ticketColumns, $ticketRows);
    insertRows($db, 'glpi_tickets_users', ['tickets_id', 'users_id', 'type', 'use_notification', 'alternative_email'], array_merge($requesterRows, $assigneeRows));
    insertRows($db, 'glpi_groups_tickets', ['tickets_id', 'groups_id', 'type'], $groupRows);
    insertRows($db, 'glpi_logs', $logColumns, $logRows);
    insertRows($db, 'glpi_ticketsatisfactions', ['tickets_id', 'type', 'date_begin', 'date_answered', 'satisfaction', 'satisfaction_scaled_to_5', 'comment'], $surveyRows);
    insertRows($db, 'glpi_itilsolutions', ['itemtype', 'items_id', 'solutiontypes_id', 'solutiontype_name', 'content', 'date_creation', 'date_mod', 'date_approval', 'users_id', 'user_name', 'users_id_editor', 'users_id_approval', 'user_name_approval', 'status', 'itilfollowups_id'], $solutionRows);
    insertRows($db, 'glpi_items_tickets', ['itemtype', 'items_id', 'tickets_id'], $itemRows);

    // Complete lifecycle history for older Experience Kit tickets that have a solved date but no native status log.
    $db->query(
        "INSERT INTO glpi_logs
         (itemtype,items_id,itemtype_link,linked_action,user_name,date_mod,id_search_option,old_value,new_value,old_id,new_id)
         SELECT 'Ticket',t.id,'',0,'MarifeX UAT History Completion',t.solvedate,12,'2',IF(t.status=6,'6','5'),NULL,NULL
         FROM glpi_tickets t
         WHERE (t.externalid IS NULL OR t.externalid NOT LIKE 'MARIFEX-UAT-%')
           AND t.solvedate IS NOT NULL
           AND t.status IN (5,6)
           AND NOT EXISTS (
             SELECT 1 FROM glpi_logs l
             WHERE l.itemtype='Ticket' AND l.items_id=t.id AND l.id_search_option=12
           )"
    );
    $counts['completed_existing_lifecycles'] = $db->affected_rows;

    $db->commit();
    $counts['logs'] = count($logRows);
    $counts['surveys'] = count($surveyRows);
    $counts['solutions'] = count($solutionRows);
    $counts['computer_links'] = count($itemRows);
    $counts['from'] = $start->format('Y-m-d');
    $counts['to'] = $end->format('Y-m-d');
    echo json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    $db->rollback();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
