<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['confirm:']);
if (($options['confirm'] ?? '') !== 'SEED_LARAGON_DOMAIN_HISTORY') {
    fwrite(STDERR, "Refused. Pass --confirm=SEED_LARAGON_DOMAIN_HISTORY.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', 'glpi11_db');
$db->set_charset('utf8mb4');
$db->begin_transaction();

try {
    $db->query("DELETE ios FROM glpi_items_operatingsystems ios JOIN glpi_operatingsystems os ON os.id=ios.operatingsystems_id WHERE os.comment='MARIFEX-UAT'");
    $db->query("DELETE FROM glpi_operatingsystems WHERE comment='MARIFEX-UAT'");
    $db->query("DELETE isv FROM glpi_items_softwareversions isv JOIN glpi_softwareversions sv ON sv.id=isv.softwareversions_id WHERE sv.comment='MARIFEX-UAT'");
    $db->query("DELETE FROM glpi_softwareversions WHERE comment='MARIFEX-UAT'");
    $db->query("DELETE FROM glpi_items_disks WHERE name LIKE 'MX-UAT %'");
    $db->query("DELETE isl FROM glpi_items_softwarelicenses isl JOIN glpi_softwarelicenses sl ON sl.id=isl.softwarelicenses_id WHERE sl.name LIKE 'MX-UAT License %'");
    $db->query("DELETE FROM glpi_softwarelicenses WHERE name LIKE 'MX-UAT License %'");
    $db->query("DELETE FROM glpi_changes WHERE content LIKE 'MARIFEX-UAT historical change%'");
    $db->query("DELETE FROM glpi_problems WHERE content LIKE 'MARIFEX-UAT historical problem%'");
    $db->query("UPDATE glpi_softwares SET is_valid=1 WHERE id IN (7,14,21,28)");

    $now = '2026-08-10 00:00:00';
    $osNames = ['Windows 11 Enterprise', 'Windows 10 Enterprise', 'Ubuntu 24.04 LTS', 'macOS 15'];
    $osIds = [];
    $osInsert = $db->prepare('INSERT INTO glpi_operatingsystems (name,comment,date_mod,date_creation) VALUES (?,\'MARIFEX-UAT\',?,?)');
    foreach ($osNames as $name) {
        $osInsert->bind_param('sss', $name, $now, $now);
        $osInsert->execute();
        $osIds[] = (int) $db->insert_id;
    }

    $computers = [];
    $result = $db->query('SELECT id,entities_id FROM glpi_computers WHERE is_deleted=0 AND is_template=0 AND entities_id IN (2,3,4) ORDER BY id');
    while ($row = $result->fetch_assoc()) {
        $computers[] = ['id' => (int) $row['id'], 'entity' => (int) $row['entities_id']];
    }
    if ($computers === []) {
        throw new RuntimeException('No managed computers are available for domain seeding.');
    }

    $osLink = $db->prepare('INSERT INTO glpi_items_operatingsystems (items_id,itemtype,operatingsystems_id,entities_id,is_deleted,is_dynamic,is_recursive,install_date,date_mod,date_creation) VALUES (?,\'Computer\',?,?,0,0,0,?,?,?)');
    $diskInsert = $db->prepare('INSERT INTO glpi_items_disks (entities_id,itemtype,items_id,name,device,mountpoint,filesystems_id,totalsize,freesize,is_deleted,is_dynamic,date_mod,date_creation) VALUES (?,\'Computer\',?,\'MX-UAT System volume\',\'C:\',\'C:\',14,?,?,0,0,?,?)');
    $inventoryUpdate = $db->prepare('UPDATE glpi_computers SET last_inventory_update=?,date_mod=? WHERE id=?');
    foreach ($computers as $index => $computer) {
        $installDate = (new DateTimeImmutable('2024-08-10'))->modify('+' . (($index * 7) % 650) . ' days')->format('Y-m-d');
        $createdAt = $installDate . ' 08:00:00';
        $osId = $osIds[$index % count($osIds)];
        $osLink->bind_param('iiisss', $computer['id'], $osId, $computer['entity'], $installDate, $createdAt, $createdAt);
        $osLink->execute();

        $total = 512000;
        $free = $index % 9 === 0 ? 24500 + ($index % 7000) : 90000 + (($index * 7919) % 260000);
        $diskInsert->bind_param('iiiiss', $computer['entity'], $computer['id'], $total, $free, $createdAt, $createdAt);
        $diskInsert->execute();

        $inventory = match (true) {
            $index % 10 === 0 => null,
            $index % 5 === 0 => '2026-05-' . str_pad((string) (($index % 27) + 1), 2, '0', STR_PAD_LEFT) . ' 06:00:00',
            default => '2026-07-' . str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT) . ' 06:00:00',
        };
        $inventoryUpdate->bind_param('ssi', $inventory, $now, $computer['id']);
        $inventoryUpdate->execute();
    }

    $versionIds = [];
    $versionInsert = $db->prepare('INSERT INTO glpi_softwareversions (entities_id,is_recursive,softwares_id,name,arch,comment,date_mod,date_creation) VALUES (0,1,?, ?,\'x64\',\'MARIFEX-UAT\',?,?)');
    for ($softwareId = 1; $softwareId <= 30; ++$softwareId) {
        $versionDate = (new DateTimeImmutable('2024-07-15'))->modify('+' . ($softwareId * 9) . ' days')->format('Y-m-d H:i:s');
        $versionName = 'MX-UAT ' . (1 + intdiv($softwareId, 10)) . '.' . ($softwareId % 10);
        $versionInsert->bind_param('isss', $softwareId, $versionName, $versionDate, $versionDate);
        $versionInsert->execute();
        $versionIds[$softwareId] = (int) $db->insert_id;
    }
    $db->query('UPDATE glpi_softwares SET is_valid=0 WHERE id IN (7,14,21,28)');

    $installInsert = $db->prepare('INSERT INTO glpi_items_softwareversions (items_id,itemtype,softwareversions_id,is_deleted_item,is_template_item,entities_id,is_deleted,is_dynamic,date_install) VALUES (?,\'Computer\',?,0,0,?,0,0,?)');
    $installationCount = 0;
    foreach ($computers as $index => $computer) {
        $titleCount = 7 + ($index % 6);
        for ($offset = 0; $offset < $titleCount; ++$offset) {
            $softwareId = 1 + (($index * 5 + $offset * 7) % 30);
            $installed = (new DateTimeImmutable('2024-08-10'))->modify('+' . (($index * 11 + $offset * 23) % 710) . ' days')->format('Y-m-d');
            $versionId = $versionIds[$softwareId];
            $installInsert->bind_param('iiis', $computer['id'], $versionId, $computer['entity'], $installed);
            $installInsert->execute();
            ++$installationCount;
        }
    }

    $licenceType = (int) ($db->query('SELECT id FROM glpi_softwarelicensetypes ORDER BY id LIMIT 1')->fetch_column() ?: 0);
    $licenceInsert = $db->prepare('INSERT INTO glpi_softwarelicenses (softwares_id,softwarelicenses_id,completename,level,entities_id,is_recursive,number,softwarelicensetypes_id,name,comment,date_mod,is_valid,date_creation,is_deleted,is_helpdesk_visible,is_template,allow_overquota) VALUES (?,0,?,1,?,1,?,?,?,\'MARIFEX-UAT governed allocation\',?,1,?,0,1,0,1)');
    $allocationInsert = $db->prepare('INSERT INTO glpi_items_softwarelicenses (items_id,itemtype,softwarelicenses_id,is_deleted,is_dynamic) VALUES (?,\'Computer\',?,0,0)');
    $licenceCount = 0;
    $allocationCount = 0;
    foreach ([2, 3, 4] as $entityId) {
        $entityComputers = array_values(array_filter($computers, static fn(array $computer): bool => $computer['entity'] === $entityId));
        for ($softwareId = 1; $softwareId <= 18; ++$softwareId) {
            $entitlement = in_array($softwareId, [5, 10, 15], true) ? 8 + $entityId : 22 + (($softwareId + $entityId) % 16);
            $allocationTarget = in_array($softwareId, [5, 10, 15], true) ? $entitlement + 8 + ($entityId % 3) : max(5, $entitlement - 6 - ($softwareId % 5));
            $licenceName = sprintf('MX-UAT License E%d S%02d', $entityId, $softwareId);
            $licenceDate = (new DateTimeImmutable('2024-08-01'))->modify('+' . (($softwareId * 31 + $entityId * 17) % 650) . ' days')->format('Y-m-d H:i:s');
            $licenceInsert->bind_param('isiiisss', $softwareId, $licenceName, $entityId, $entitlement, $licenceType, $licenceName, $licenceDate, $licenceDate);
            $licenceInsert->execute();
            $licenceId = (int) $db->insert_id;
            ++$licenceCount;
            foreach (array_slice($entityComputers, ($softwareId * 7) % max(1, count($entityComputers)), $allocationTarget) as $computer) {
                $allocationInsert->bind_param('ii', $computer['id'], $licenceId);
                $allocationInsert->execute();
                ++$allocationCount;
            }
        }
    }

    $changeInsert = $db->prepare('INSERT INTO glpi_changes (name,entities_id,is_recursive,is_deleted,status,content,date_mod,date,solvedate,closedate,users_id_recipient,users_id_lastupdater,urgency,impact,priority,date_creation) VALUES (?, ?,1,0,?,\'MARIFEX-UAT historical change acceptance record\',?,?,?,?,2,2,?,?,?,?)');
    $problemInsert = $db->prepare('INSERT INTO glpi_problems (name,entities_id,is_recursive,is_deleted,status,content,date_mod,date,solvedate,closedate,users_id_recipient,users_id_lastupdater,urgency,impact,priority,date_creation) VALUES (?, ?,1,0,?,\'MARIFEX-UAT historical problem acceptance record\',?,?,?,?,2,2,?,?,?,?)');
    $changeNames = ['Endpoint security rollout', 'Network maintenance window', 'Application release deployment', 'Identity policy update', 'Database capacity expansion'];
    $problemNames = ['Recurring VPN instability', 'Application performance degradation', 'Authentication failure pattern', 'Printing service recurrence'];
    $changeCount = 0;
    $problemCount = 0;
    for ($day = 0; $day < 730; ++$day) {
        $date = (new DateTimeImmutable('2024-08-10'))->modify('+' . $day . ' days');
        $changeDaily = $day < 365 ? ($day % 4 === 0 ? 1 : 0) : ($day < 650 ? ($day % 3 === 0 ? 1 : 0) : ($day % 2 === 0 ? 2 : 1));
        for ($sequence = 0; $sequence < $changeDaily; ++$sequence) {
            $created = $date->setTime(8 + (($day + $sequence) % 9), ($day * 7 + $sequence * 13) % 60);
            $open = $day > 680 && (($day + $sequence) % 5 === 0);
            $status = $open ? (($day + $sequence) % 2 === 0 ? 1 : 2) : 6;
            $solved = $open ? null : $created->modify('+' . (1 + (($day + $sequence) % 6)) . ' days')->format('Y-m-d H:i:s');
            $closed = $solved;
            $createdText = $created->format('Y-m-d H:i:s');
            $modified = $closed ?? $createdText;
            $name = '[MX-UAT] ' . $changeNames[($day + $sequence) % count($changeNames)];
            $entityId = 2 + (($day + $sequence) % 3);
            $urgency = 1 + (($day + $sequence) % 5);
            $impact = 1 + (($day * 2 + $sequence) % 5);
            $priority = min(5, (int) ceil(($urgency + $impact) / 2));
            $changeInsert->bind_param('siissssiiis', $name, $entityId, $status, $modified, $createdText, $solved, $closed, $urgency, $impact, $priority, $createdText);
            $changeInsert->execute();
            ++$changeCount;
        }

        $problemDaily = $day < 365 ? ($day % 8 === 0 ? 1 : 0) : ($day < 650 ? ($day % 6 === 0 ? 1 : 0) : ($day % 3 === 0 ? 1 : 0));
        for ($sequence = 0; $sequence < $problemDaily; ++$sequence) {
            $created = $date->setTime(9 + ($day % 8), ($day * 11) % 60);
            $open = $day > 670 && $day % 7 === 0;
            $status = $open ? 2 : 6;
            $solved = $open ? null : $created->modify('+' . (3 + ($day % 12)) . ' days')->format('Y-m-d H:i:s');
            $closed = $solved;
            $createdText = $created->format('Y-m-d H:i:s');
            $modified = $closed ?? $createdText;
            $name = '[MX-UAT] ' . $problemNames[$day % count($problemNames)];
            $entityId = 2 + ($day % 3);
            $urgency = 2 + ($day % 4);
            $impact = 2 + (($day * 2) % 4);
            $priority = min(5, (int) ceil(($urgency + $impact) / 2));
            $problemInsert->bind_param('siissssiiis', $name, $entityId, $status, $modified, $createdText, $solved, $closed, $urgency, $impact, $priority, $createdText);
            $problemInsert->execute();
            ++$problemCount;
        }
    }

    $db->commit();
    echo json_encode([
        'computers_enriched' => count($computers),
        'operating_system_links' => count($computers),
        'disk_records' => count($computers),
        'software_versions' => count($versionIds),
        'software_installations' => $installationCount,
        'invalid_software_titles' => 4,
        'licences' => $licenceCount,
        'licence_allocations' => $allocationCount,
        'changes' => $changeCount,
        'problems' => $problemCount,
    ], JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $error) {
    $db->rollback();
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
