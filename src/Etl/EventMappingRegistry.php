<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Etl;

use Glpi\Search\SearchOption;
use RuntimeException;

final class EventMappingRegistry
{
    public const TICKET_STATUS_CHANGED = 'ticket_status_changed';
    public const TICKET_TECHNICIAN_ASSIGNMENT_CHANGED = 'ticket_technician_assignment_changed';
    public const TICKET_GROUP_ASSIGNMENT_CHANGED = 'ticket_group_assignment_changed';
    private const MAPPING_VERSION = 1;

    /** @return array<string, mixed> */
    public function refreshTicketStatus(): array
    {
        global $DB;

        $matches = [];
        foreach (SearchOption::getOptionsForItemtype('Ticket') as $id => $option) {
            if (($option['table'] ?? '') === 'glpi_tickets' && ($option['field'] ?? '') === 'status') {
                $matches[] = ['id' => (int) $id, 'option' => $option];
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException('GLPI ticket status mapping could not be verified safely.');
        }

        $mapping = [
            'itemtype' => 'Ticket',
            'source_table' => 'glpi_tickets',
            'source_field' => 'status',
            'search_option_id' => $matches[0]['id'],
            'semantic_event' => self::TICKET_STATUS_CHANGED,
            'glpi_version_min' => GLPI_VERSION,
            'glpi_version_max' => GLPI_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'validation_status' => 'verified',
            'validated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $DB->updateOrInsert('glpi_plugin_marifex_event_mappings', $mapping, [
            'semantic_event' => self::TICKET_STATUS_CHANGED,
            'glpi_version_min' => GLPI_VERSION,
            'glpi_version_max' => GLPI_VERSION,
        ]);

        return $mapping;
    }

    /** @return list<array<string, mixed>> */
    public function refreshAll(): array
    {
        return [
            $this->refreshTicketStatus(),
            $this->refreshAssignment('glpi_users', 'users_id', self::TICKET_TECHNICIAN_ASSIGNMENT_CHANGED),
            $this->refreshAssignment('glpi_groups', 'groups_id', self::TICKET_GROUP_ASSIGNMENT_CHANGED),
        ];
    }

    /** @return array<string, mixed> */
    private function refreshAssignment(string $table, string $linkField, string $semanticEvent): array
    {
        global $DB;
        $matches = [];
        foreach (SearchOption::getOptionsForItemtype('Ticket') as $id => $option) {
            $roleType = $option['joinparams']['beforejoin']['joinparams']['condition']['NEWTABLE.type'] ?? null;
            if (($option['table'] ?? '') === $table && ($option['linkfield'] ?? '') === $linkField && (int) $roleType === 2) {
                $matches[] = ['id' => (int) $id, 'option' => $option];
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('GLPI ticket assignment mapping could not be verified safely.');
        }

        $mapping = [
            'itemtype' => 'Ticket',
            'source_table' => $table,
            'source_field' => $linkField,
            'search_option_id' => $matches[0]['id'],
            'semantic_event' => $semanticEvent,
            'glpi_version_min' => GLPI_VERSION,
            'glpi_version_max' => GLPI_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'validation_status' => 'verified',
            'validated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $DB->updateOrInsert('glpi_plugin_marifex_event_mappings', $mapping, [
            'semantic_event' => $semanticEvent,
            'glpi_version_min' => GLPI_VERSION,
            'glpi_version_max' => GLPI_VERSION,
        ]);
        return $mapping;
    }

    /** @return array<string, mixed> */
    public function verified(string $semanticEvent): array
    {
        global $DB;
        $mapping = $DB->request([
            'FROM' => 'glpi_plugin_marifex_event_mappings',
            'WHERE' => [
                'semantic_event' => $semanticEvent,
                'glpi_version_min' => GLPI_VERSION,
                'glpi_version_max' => GLPI_VERSION,
                'validation_status' => 'verified',
            ],
            'LIMIT' => 1,
        ])->current();

        if (!$mapping) {
            throw new RuntimeException('No verified event mapping is available for this GLPI version.');
        }

        return $mapping;
    }
}
