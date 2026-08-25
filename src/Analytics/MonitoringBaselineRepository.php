<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

use DateTimeImmutable;
use DBmysql;
use RuntimeException;

final class MonitoringBaselineRepository
{
    /** @param array<string, mixed> $evidence */
    public function establishIfAbsent(MonitoringScope $scope, DateTimeImmutable $observedAt, array $evidence, ProvenanceEvidence $provenance): bool
    {
        global $DB;
        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }
        if (!$provenance->isEligibleForCertifiedUse() || $provenance->provenance !== Provenance::OBSERVED) {
            throw new RuntimeException('Production monitoring baselines may be established only from certified observed evidence.');
        }

        $fingerprint = $scope->fingerprint();
        if ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_marifex_monitoring_baselines',
            'WHERE' => ['scope_fingerprint' => $fingerprint],
            'LIMIT' => 1,
        ])->current()) {
            return false;
        }

        $entityIds = array_values(array_unique(array_map('intval', $scope->entityIds)));
        sort($entityIds, SORT_NUMERIC);
        $evidenceJson = self::canonicalEvidenceJson($evidence);
        $DB->insert('glpi_plugin_marifex_monitoring_baselines', [
            'scope_fingerprint' => $fingerprint,
            'metric_key' => $scope->metricKey,
            'entities_id' => $scope->rootEntityId,
            'is_recursive' => $scope->recursive ? 1 : 0,
            'entity_scope' => json_encode($entityIds, JSON_THROW_ON_ERROR),
            'groups_id' => $scope->groupId ?? 0,
            'grain' => $scope->grain,
            'monitoring_baseline_at' => $observedAt->format('Y-m-d'),
            'evidence' => $evidenceJson,
            'evidence_hash' => hash('sha256', $evidenceJson),
            'provenance' => $provenance->effectiveProvenance->value,
        ]);

        return true;
    }

    /** @return array<string, mixed>|null */
    public function find(MonitoringScope $scope): ?array
    {
        global $DB;
        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }
        $row = $DB->request([
            'FROM' => 'glpi_plugin_marifex_monitoring_baselines',
            'WHERE' => ['scope_fingerprint' => $scope->fingerprint()],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            return null;
        }

        $result = [
            'monitoring_baseline_at' => (string) $row['monitoring_baseline_at'],
            'provenance' => (string) $row['provenance'],
            'scope_fingerprint' => (string) $row['scope_fingerprint'],
            'evidence' => null,
            'integrity_valid' => false,
        ];
        $storedEvidence = (string) $row['evidence'];
        try {
            $evidence = json_decode($storedEvidence, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $result;
        }
        if (!is_array($evidence) || !self::integrityValid($evidence, (string) $row['evidence_hash'])) {
            return $result;
        }

        return array_merge($result, ['evidence' => $evidence, 'integrity_valid' => true]);
    }

    /** @param array<string, mixed> $evidence */
    public static function evidenceHash(array $evidence): string
    {
        return hash('sha256', self::canonicalEvidenceJson($evidence));
    }

    /** @param array<string, mixed> $evidence */
    public static function integrityValid(array $evidence, string $expectedHash): bool
    {
        if (hash_equals($expectedHash, self::evidenceHash($evidence))) {
            return true;
        }

        // Compatibility for baselines written before canonical JSON hashing was introduced.
        // Their semantic field order was deterministic at collection time, while JSON columns
        // may return those fields in a different order.
        $legacy = isset($evidence['dimensions'])
            ? ['format' => $evidence['format'] ?? '', 'dimensions' => $evidence['dimensions']]
            : ['format' => $evidence['format'] ?? '', 'value' => $evidence['value'] ?? 0, 'sample_count' => $evidence['sample_count'] ?? 0];
        if (isset($legacy['dimensions']) && is_array($legacy['dimensions'])) {
            ksort($legacy['dimensions'], SORT_NATURAL);
        }
        $legacyJson = json_encode($legacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return hash_equals($expectedHash, hash('sha256', $legacyJson));
    }

    /** @param array<string, mixed> $evidence */
    private static function canonicalEvidenceJson(array $evidence): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_float($value)) {
                $rounded = round($value, 6);
                return $rounded === -0.0 ? 0.0 : $rounded;
            }
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
            return $value;
        };

        return json_encode($normalize($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
