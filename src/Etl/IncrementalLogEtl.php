<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use Config;
use Throwable;

final class IncrementalLogEtl
{
    private const PIPELINE = 'ticket_logs_v1';
    private const SOURCE = 'glpi_logs';

    public function __construct(
        private readonly CheckpointStore $checkpoints = new CheckpointStore(),
        private readonly EventMappingRegistry $mappings = new EventMappingRegistry(),
    ) {
    }

    public function run(): int
    {
        global $DB;

        $this->mappings->refreshTicketStatus();
        $mapping = $this->mappings->verified(EventMappingRegistry::TICKET_STATUS_CHANGED);
        $token = $this->token();
        $this->checkpoints->acquire(self::PIPELINE, self::SOURCE, $token);
        $watermark = $this->checkpoints->watermark(self::PIPELINE, self::SOURCE);
        $config = Config::getConfigurationValues('plugin:marifex');
        $limit = max(50, min(5000, (int) ($config['etl_batch_size'] ?? 500)));
        $processed = 0;

        try {
            $logs = $DB->request([
                'SELECT' => ['id', 'items_id', 'date_mod', 'old_value', 'new_value'],
                'FROM' => self::SOURCE,
                'WHERE' => [
                    'itemtype' => 'Ticket',
                    'id_search_option' => (int) $mapping['search_option_id'],
                    [
                        'OR' => [
                            ['date_mod' => ['>', $watermark['date']]],
                            ['AND' => [
                                ['date_mod' => $watermark['date']],
                                ['id' => ['>', $watermark['id']]],
                            ]],
                        ],
                    ],
                ],
                'ORDER' => ['date_mod ASC', 'id ASC'],
                'LIMIT' => $limit,
            ]);

            $rows = iterator_to_array($logs);
            $ticketIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['items_id'], $rows)));
            $entities = [];
            if ($ticketIds !== []) {
                foreach ($DB->request([
                    'SELECT' => ['id', 'entities_id'],
                    'FROM' => 'glpi_tickets',
                    'WHERE' => ['id' => $ticketIds],
                ]) as $ticket) {
                    $entities[(int) $ticket['id']] = (int) $ticket['entities_id'];
                }
            }

            foreach ($rows as $log) {
                $logId = (int) $log['id'];
                $ticketId = (int) $log['items_id'];
                $eventKey = hash('sha256', implode('|', [
                    self::SOURCE,
                    $logId,
                    $mapping['semantic_event'],
                    $log['date_mod'],
                    $mapping['mapping_version'],
                ]));

                $DB->updateOrInsert('glpi_plugin_marifex_ticket_events', [
                    'tickets_id' => $ticketId,
                    'entities_id' => $entities[$ticketId] ?? 0,
                    'event_type' => (string) $mapping['semantic_event'],
                    'source_type' => 'log',
                    'source_id' => $logId,
                    'occurred_at' => $log['date_mod'],
                    'old_value' => (string) $log['old_value'],
                    'new_value' => (string) $log['new_value'],
                    'payload' => json_encode([
                        'mapping_version' => (int) $mapping['mapping_version'],
                        'source_missing' => !isset($entities[$ticketId]),
                    ], JSON_THROW_ON_ERROR),
                ], ['event_key' => $eventKey]);

                $watermark = ['id' => $logId, 'date' => (string) $log['date_mod']];
                ++$processed;
            }

            $this->checkpoints->complete(self::PIPELINE, self::SOURCE, $token, $watermark['id'], $watermark['date']);
            return $processed;
        } catch (Throwable $exception) {
            $this->checkpoints->fail(self::PIPELINE, self::SOURCE, $token, $exception->getMessage());
            throw $exception;
        }
    }

    private function token(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
