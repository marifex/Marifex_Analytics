<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Insight;

use Session;

final class AnalyticalAuditService
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function record(string $eventType, array $before, array $after, ?int $userId = null, ?int $entityId = null): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_analytical_audit')) return;
        $DB->insert('glpi_plugin_marifex_analytical_audit', [
            'event_type' => mb_substr($eventType, 0, 64),
            'users_id' => $userId ?? (class_exists(Session::class) ? (int) Session::getLoginUserID() : 0),
            'entities_id' => $entityId ?? (class_exists(Session::class) ? (int) Session::getActiveEntity() : 0),
            'formula_version' => InsightRuleRegistry::FORMULA_VERSION,
            'before_state' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'after_state' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
