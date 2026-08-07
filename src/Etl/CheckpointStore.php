<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use DateTimeImmutable;
use RuntimeException;

final class CheckpointStore
{
    /** @return array{id: int, date: string} */
    public function watermark(string $pipeline, string $source): array
    {
        global $DB;
        $row = $DB->request([
            'FROM' => 'glpi_plugin_marifex_etl_checkpoints',
            'WHERE' => ['pipeline' => $pipeline, 'source_table' => $source],
            'LIMIT' => 1,
        ])->current();

        return [
            'id' => (int) ($row['watermark_id'] ?? 0),
            'date' => (string) ($row['watermark_date'] ?? '1970-01-01 00:00:00'),
        ];
    }

    public function acquire(string $pipeline, string $source, string $token): void
    {
        global $DB;
        $cutoff = (new DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s');
        $existing = $DB->request([
            'FROM' => 'glpi_plugin_marifex_etl_checkpoints',
            'WHERE' => ['pipeline' => $pipeline, 'source_table' => $source],
            'LIMIT' => 1,
        ])->current();

        if ($existing && $existing['status'] === 'running' && $existing['locked_at'] > $cutoff) {
            throw new RuntimeException('The ETL pipeline is already running.');
        }

        $DB->updateOrInsert('glpi_plugin_marifex_etl_checkpoints', [
            'status' => 'running', 'lock_token' => $token, 'locked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null,
        ], ['pipeline' => $pipeline, 'source_table' => $source]);
    }

    public function complete(string $pipeline, string $source, string $token, int $watermark, ?string $watermarkDate = null): void
    {
        global $DB;
        $DB->update('glpi_plugin_marifex_etl_checkpoints', [
            'watermark_id' => $watermark,
            'watermark_date' => $watermarkDate ?? gmdate('Y-m-d H:i:s'),
            'status' => 'idle',
            'lock_token' => null,
            'locked_at' => null,
        ], ['pipeline' => $pipeline, 'source_table' => $source, 'lock_token' => $token]);
    }

    public function fail(string $pipeline, string $source, string $token, string $error): void
    {
        global $DB;
        $DB->update('glpi_plugin_marifex_etl_checkpoints', [
            'status' => 'failed', 'last_error' => mb_substr($error, 0, 65535), 'lock_token' => null,
        ], ['pipeline' => $pipeline, 'source_table' => $source, 'lock_token' => $token]);
    }
}
