<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Install;

final class Schema
{
    /** @return array<string, string> */
    public static function tables(): array
    {
        $suffix = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

        return [
            'glpi_plugin_marifex_etl_checkpoints' => "CREATE TABLE `glpi_plugin_marifex_etl_checkpoints` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `pipeline` varchar(80) NOT NULL,
                `source_table` varchar(128) NOT NULL,
                `watermark_id` bigint unsigned NOT NULL DEFAULT 0,
                `watermark_date` timestamp NULL DEFAULT NULL,
                `status` enum('idle','running','failed') NOT NULL DEFAULT 'idle',
                `lock_token` char(36) DEFAULT NULL,
                `locked_at` timestamp NULL DEFAULT NULL,
                `last_error` text DEFAULT NULL,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `pipeline_source` (`pipeline`,`source_table`), KEY `status_locked_at` (`status`,`locked_at`)
            ) $suffix",
            'glpi_plugin_marifex_ticket_events' => "CREATE TABLE `glpi_plugin_marifex_ticket_events` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `event_key` char(64) NOT NULL,
                `tickets_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `event_type` varchar(64) NOT NULL,
                `source_type` varchar(32) NOT NULL,
                `source_id` bigint unsigned DEFAULT NULL,
                `occurred_at` timestamp NOT NULL,
                `old_value` text DEFAULT NULL,
                `new_value` text DEFAULT NULL,
                `payload` json DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `event_key` (`event_key`), KEY `ticket_time` (`tickets_id`,`occurred_at`), KEY `entity_type_time` (`entities_id`,`event_type`,`occurred_at`)
            ) $suffix",
            'glpi_plugin_marifex_state_intervals' => "CREATE TABLE `glpi_plugin_marifex_state_intervals` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `tickets_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `state_type` varchar(32) NOT NULL,
                `state_value` varchar(255) NOT NULL,
                `started_at` timestamp NOT NULL,
                `ended_at` timestamp NULL DEFAULT NULL,
                `duration_seconds` bigint unsigned DEFAULT NULL,
                `source_event_start_id` bigint unsigned DEFAULT NULL,
                `source_event_end_id` bigint unsigned DEFAULT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `interval_identity` (`tickets_id`,`state_type`,`started_at`), KEY `entity_state_time` (`entities_id`,`state_type`,`started_at`), KEY `open_intervals` (`ended_at`)
            ) $suffix",
            'glpi_plugin_marifex_daily_snapshots' => "CREATE TABLE `glpi_plugin_marifex_daily_snapshots` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `snapshot_date` date NOT NULL,
                `tickets_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `status` smallint unsigned NOT NULL,
                `priority` smallint unsigned NOT NULL DEFAULT 0,
                `groups_id_assign` int unsigned DEFAULT NULL,
                `users_id_assign` int unsigned DEFAULT NULL,
                `age_seconds` bigint unsigned NOT NULL DEFAULT 0,
                `is_open` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`), UNIQUE KEY `snapshot_ticket` (`snapshot_date`,`tickets_id`), KEY `entity_date_open` (`entities_id`,`snapshot_date`,`is_open`)
            ) $suffix",
            'glpi_plugin_marifex_daily_rollups' => "CREATE TABLE `glpi_plugin_marifex_daily_rollups` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `rollup_date` date NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `metric_key` varchar(80) NOT NULL,
                `dimension_key` varchar(80) NOT NULL DEFAULT '',
                `dimension_value` varchar(255) NOT NULL DEFAULT '',
                `metric_value` decimal(20,4) NOT NULL DEFAULT 0,
                `sample_count` bigint unsigned NOT NULL DEFAULT 0,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `rollup_grain` (`rollup_date`,`entities_id`,`metric_key`,`dimension_key`,`dimension_value`), KEY `metric_entity_date` (`metric_key`,`entities_id`,`rollup_date`)
            ) $suffix",
            'glpi_plugin_marifex_dashboard_definitions' => "CREATE TABLE `glpi_plugin_marifex_dashboard_definitions` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint(1) NOT NULL DEFAULT 0,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `definition` json NOT NULL,
                `users_id` int unsigned NOT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `entity_active` (`entities_id`,`is_active`)
            ) $suffix",
        ];
    }
}

