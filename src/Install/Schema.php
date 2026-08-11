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
                PRIMARY KEY (`id`), UNIQUE KEY `interval_identity` (`tickets_id`,`state_type`,`state_value`,`started_at`), KEY `entity_state_time` (`entities_id`,`state_type`,`started_at`), KEY `open_intervals` (`ended_at`)
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
            'glpi_plugin_marifex_daily_matrix_rollups' => "CREATE TABLE `glpi_plugin_marifex_daily_matrix_rollups` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `rollup_date` date NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `metric_key` varchar(80) NOT NULL,
                `row_key` varchar(80) NOT NULL,
                `row_value` varchar(255) NOT NULL,
                `column_key` varchar(80) NOT NULL,
                `column_value` varchar(255) NOT NULL,
                `metric_value` decimal(20,4) NOT NULL DEFAULT 0,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `matrix_grain` (`rollup_date`,`entities_id`,`metric_key`,`row_key`,`row_value`,`column_key`,`column_value`),
                KEY `matrix_metric_entity_date` (`metric_key`,`entities_id`,`rollup_date`)
            ) $suffix",
            'glpi_plugin_marifex_dashboard_provisions' => "CREATE TABLE `glpi_plugin_marifex_dashboard_provisions` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `users_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `release_key` varchar(80) NOT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), UNIQUE KEY `user_entity_release` (`users_id`,`entities_id`,`release_key`)
            ) $suffix",
            'glpi_plugin_marifex_report_schedules' => "CREATE TABLE `glpi_plugin_marifex_report_schedules` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(120) NOT NULL,
                `dashboard_definitions_id` int unsigned NOT NULL,
                `users_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint(1) NOT NULL DEFAULT 0,
                `format` enum('pdf','csv') NOT NULL DEFAULT 'pdf',
                `frequency` enum('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
                `send_hour` tinyint unsigned NOT NULL DEFAULT 8,
                `weekday` tinyint unsigned DEFAULT NULL,
                `monthday` tinyint unsigned DEFAULT NULL,
                `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
                `recipients` text NOT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `next_run_at` timestamp NOT NULL,
                `last_run_at` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `due_reports` (`is_active`,`next_run_at`), KEY `owner_entity` (`users_id`,`entities_id`)
            ) $suffix",
            'glpi_plugin_marifex_report_runs' => "CREATE TABLE `glpi_plugin_marifex_report_runs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `schedules_id` int unsigned DEFAULT NULL,
                `dashboard_definitions_id` int unsigned NOT NULL,
                `users_id` int unsigned NOT NULL,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `format` enum('pdf','csv') NOT NULL,
                `status` enum('running','completed','failed','blocked') NOT NULL DEFAULT 'running',
                `file_name` varchar(255) DEFAULT NULL,
                `file_path` varchar(512) DEFAULT NULL,
                `file_hash` char(64) DEFAULT NULL,
                `recipient_count` int unsigned NOT NULL DEFAULT 0,
                `formula_version` varchar(32) DEFAULT NULL,
                `insight_evidence` json DEFAULT NULL,
                `presentation_evidence` json DEFAULT NULL,
                `error_message` text DEFAULT NULL,
                `started_at` timestamp NOT NULL,
                `completed_at` timestamp NULL DEFAULT NULL,
                `expires_at` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `owner_created` (`users_id`,`entities_id`,`date_creation`), KEY `schedule_created` (`schedules_id`,`date_creation`), KEY `expiry` (`expires_at`)
            ) $suffix",
            'glpi_plugin_marifex_event_mappings' => "CREATE TABLE `glpi_plugin_marifex_event_mappings` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(128) NOT NULL,
                `source_table` varchar(128) NOT NULL,
                `source_field` varchar(128) NOT NULL,
                `search_option_id` int unsigned NOT NULL,
                `semantic_event` varchar(80) NOT NULL,
                `glpi_version_min` varchar(32) NOT NULL,
                `glpi_version_max` varchar(32) NOT NULL,
                `mapping_version` int unsigned NOT NULL DEFAULT 1,
                `validation_status` enum('verified','invalid') NOT NULL DEFAULT 'invalid',
                `validated_at` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `semantic_version` (`semantic_event`,`glpi_version_min`,`glpi_version_max`),
                KEY `runtime_lookup` (`itemtype`,`search_option_id`,`validation_status`)
            ) $suffix",
            'glpi_plugin_marifex_reconciliation_runs' => "CREATE TABLE `glpi_plugin_marifex_reconciliation_runs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `scope` varchar(80) NOT NULL,
                `started_at` timestamp NOT NULL,
                `completed_at` timestamp NULL DEFAULT NULL,
                `source_count` bigint unsigned NOT NULL DEFAULT 0,
                `analytics_count` bigint unsigned NOT NULL DEFAULT 0,
                `missing_count` bigint unsigned NOT NULL DEFAULT 0,
                `orphan_count` bigint unsigned NOT NULL DEFAULT 0,
                `status` enum('running','passed','warning','failed') NOT NULL DEFAULT 'running',
                `details` json DEFAULT NULL,
                PRIMARY KEY (`id`), KEY `scope_completed` (`scope`,`completed_at`)
            ) $suffix",
            'glpi_plugin_marifex_analytical_audit' => "CREATE TABLE `glpi_plugin_marifex_analytical_audit` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `event_type` varchar(64) NOT NULL,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `formula_version` varchar(32) NOT NULL,
                `before_state` json DEFAULT NULL,
                `after_state` json DEFAULT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `event_created` (`event_type`,`date_creation`)
            ) $suffix",
            'glpi_plugin_marifex_daily_response_observations' => "CREATE TABLE `glpi_plugin_marifex_daily_response_observations` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `snapshot_date` date NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `tickets_id` int unsigned NOT NULL,
                `delay_seconds` bigint unsigned NOT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `snapshot_ticket` (`snapshot_date`,`tickets_id`),
                KEY `entity_snapshot` (`entities_id`,`snapshot_date`)
            ) $suffix",
            'glpi_plugin_marifex_daily_licence_title_observations' => "CREATE TABLE `glpi_plugin_marifex_daily_licence_title_observations` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `snapshot_date` date NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `softwares_id` int unsigned NOT NULL,
                `entitlement_count` bigint unsigned NOT NULL DEFAULT 0,
                `allocation_count` bigint unsigned NOT NULL DEFAULT 0,
                `is_installed` tinyint(1) NOT NULL DEFAULT 0,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `snapshot_entity_title` (`snapshot_date`,`entities_id`,`softwares_id`),
                KEY `entity_snapshot` (`entities_id`,`snapshot_date`),
                KEY `title_snapshot` (`softwares_id`,`snapshot_date`)
            ) $suffix",
            'glpi_plugin_marifex_chart_palettes' => "CREATE TABLE `glpi_plugin_marifex_chart_palettes` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(50) NOT NULL,
                `entities_id` int unsigned NOT NULL,
                `is_recursive` tinyint(1) NOT NULL DEFAULT 0,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `palette_type` enum('categorical','monochrome','gradient') NOT NULL,
                `definition` json NOT NULL,
                `revision` int unsigned NOT NULL DEFAULT 1,
                `users_id` int unsigned NOT NULL,
                `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entity_name` (`entities_id`,`name`),
                KEY `entity_recursive` (`entities_id`,`is_recursive`),
                KEY `entity_default` (`entities_id`,`is_default`)
            ) $suffix",
        ];
    }
}

