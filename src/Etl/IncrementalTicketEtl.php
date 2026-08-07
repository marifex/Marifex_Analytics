<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use Config;
use Throwable;

final class IncrementalTicketEtl
{
    private const BACKFILL_PIPELINE = 'tickets_backfill_v1';
    private const CHANGES_PIPELINE = 'tickets_changes_v1';
    private const SOURCE = 'glpi_tickets';

    public function __construct(private readonly CheckpointStore $checkpoints = new CheckpointStore())
    {
    }

    public function run(): int
    {
        return $this->runBackfill() + $this->runChanges();
    }

    private function runBackfill(): int
    {
        global $DB;
        $token = $this->token();
        $this->checkpoints->acquire(self::BACKFILL_PIPELINE, self::SOURCE, $token);
        $watermark = $this->checkpoints->watermark(self::BACKFILL_PIPELINE, self::SOURCE)['id'];
        $config = Config::getConfigurationValues('plugin:marifex');
        $limit = max(50, min(5000, (int) ($config['etl_batch_size'] ?? 500)));
        $processed = 0;

        try {
            $tickets = $DB->request([
                'SELECT' => ['id', 'entities_id', 'date', 'date_mod', 'status', 'priority'],
                'FROM' => self::SOURCE,
                'WHERE' => [['id' => ['>', $watermark]]],
                'ORDER' => ['id ASC'],
                'LIMIT' => $limit,
            ]);

            foreach ($tickets as $ticket) {
                $eventKey = hash('sha256', implode('|', ['ticket_created', $ticket['id'], $ticket['date']]));
                $DB->updateOrInsert('glpi_plugin_marifex_ticket_events', [
                    'tickets_id' => (int) $ticket['id'],
                    'entities_id' => (int) $ticket['entities_id'],
                    'event_type' => 'ticket_created',
                    'source_type' => 'ticket',
                    'source_id' => (int) $ticket['id'],
                    'occurred_at' => $ticket['date'],
                    'new_value' => (string) $ticket['status'],
                    'payload' => json_encode(['status' => (int) $ticket['status'], 'priority' => (int) $ticket['priority']], JSON_THROW_ON_ERROR),
                ], ['event_key' => $eventKey]);
                (new StateIntervalProjector())->project(
                    (int) $ticket['id'],
                    (int) $ticket['entities_id'],
                    'status',
                    (string) $ticket['status'],
                    (string) $ticket['date']
                );
                $watermark = (int) $ticket['id'];
                ++$processed;
            }

            $this->checkpoints->complete(self::BACKFILL_PIPELINE, self::SOURCE, $token, $watermark);
            return $processed;
        } catch (Throwable $exception) {
            $this->checkpoints->fail(self::BACKFILL_PIPELINE, self::SOURCE, $token, $exception->getMessage());
            throw $exception;
        }
    }

    private function runChanges(): int
    {
        global $DB;
        $token = $this->token();
        $this->checkpoints->acquire(self::CHANGES_PIPELINE, self::SOURCE, $token);
        $watermark = $this->checkpoints->watermark(self::CHANGES_PIPELINE, self::SOURCE);
        $config = Config::getConfigurationValues('plugin:marifex');
        $limit = max(50, min(5000, (int) ($config['etl_batch_size'] ?? 500)));
        $processed = 0;

        try {
            $tickets = $DB->request([
                'SELECT' => ['id', 'entities_id', 'date_mod', 'status', 'priority'],
                'FROM' => self::SOURCE,
                'WHERE' => [[
                    'OR' => [
                        ['date_mod' => ['>', $watermark['date']]],
                        [
                            'AND' => [
                                ['date_mod' => $watermark['date']],
                                ['id' => ['>', $watermark['id']]],
                            ],
                        ],
                    ],
                ]],
                'ORDER' => ['date_mod ASC', 'id ASC'],
                'LIMIT' => $limit,
            ]);

            foreach ($tickets as $ticket) {
                $eventKey = hash('sha256', implode('|', [
                    'ticket_state', $ticket['id'], $ticket['date_mod'], $ticket['status'], $ticket['priority'],
                ]));
                $DB->updateOrInsert('glpi_plugin_marifex_ticket_events', [
                    'tickets_id' => (int) $ticket['id'],
                    'entities_id' => (int) $ticket['entities_id'],
                    'event_type' => 'ticket_state_observed',
                    'source_type' => 'ticket',
                    'source_id' => (int) $ticket['id'],
                    'occurred_at' => $ticket['date_mod'],
                    'new_value' => (string) $ticket['status'],
                    'payload' => json_encode(['status' => (int) $ticket['status'], 'priority' => (int) $ticket['priority']], JSON_THROW_ON_ERROR),
                ], ['event_key' => $eventKey]);
                (new StateIntervalProjector())->project(
                    (int) $ticket['id'],
                    (int) $ticket['entities_id'],
                    'status',
                    (string) $ticket['status'],
                    (string) $ticket['date_mod']
                );
                $watermark = ['id' => (int) $ticket['id'], 'date' => (string) $ticket['date_mod']];
                ++$processed;
            }

            $this->checkpoints->complete(
                self::CHANGES_PIPELINE,
                self::SOURCE,
                $token,
                $watermark['id'],
                $watermark['date']
            );
            return $processed;
        } catch (Throwable $exception) {
            $this->checkpoints->fail(self::CHANGES_PIPELINE, self::SOURCE, $token, $exception->getMessage());
            throw $exception;
        }
    }

    private function token(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
