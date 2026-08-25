<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Install;

use Config;
use DBmysql;
use Migration;
use RuntimeException;
use GlpiPlugin\Marifex\Insight\AnalyticalAuditService;
use GlpiPlugin\Marifex\Analytics\MonitoringBaselineCollector;

final class Installer
{
    private const VERSION = 230;
    private const TABLE_PREFIX = 'glpi_plugin_marifex_';

    public function install(): void
    {
        global $DB;

        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }

        $configuration = Config::getConfigurationValues('plugin:marifex');
        $installedVersion = (int) ($configuration['schema_version'] ?? 0);
        $baselineTableExisted = $DB->tableExists('glpi_plugin_marifex_monitoring_baselines');
        $migration = new Migration(self::VERSION);
        foreach (Schema::tables() as $table => $sql) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery($sql);
            }
        }

        if ($installedVersion > 0 && $installedVersion < 110) {
            $migration->changeField(
                'glpi_plugin_marifex_etl_checkpoints',
                'watermark_date',
                'watermark_date',
                'timestamp NULL DEFAULT NULL'
            );
            $migration->changeField(
                'glpi_plugin_marifex_etl_checkpoints',
                'locked_at',
                'locked_at',
                'timestamp NULL DEFAULT NULL'
            );
            $migration->changeField(
                'glpi_plugin_marifex_ticket_events',
                'occurred_at',
                'occurred_at',
                'timestamp NOT NULL'
            );
            $migration->changeField(
                'glpi_plugin_marifex_state_intervals',
                'started_at',
                'started_at',
                'timestamp NOT NULL'
            );
            $migration->changeField(
                'glpi_plugin_marifex_state_intervals',
                'ended_at',
                'ended_at',
                'timestamp NULL DEFAULT NULL'
            );
        }
        if ($installedVersion > 0 && $installedVersion < 130) {
            $migration->dropKey('glpi_plugin_marifex_state_intervals', 'interval_identity');
            $migration->addKey(
                'glpi_plugin_marifex_state_intervals',
                ['tickets_id', 'state_type', 'state_value', 'started_at'],
                'interval_identity',
                'UNIQUE'
            );
        }
        if ($installedVersion > 0 && $installedVersion < 180) {
            $migration->addField('glpi_plugin_marifex_report_runs', 'formula_version', 'varchar(32) DEFAULT NULL');
            $migration->addField('glpi_plugin_marifex_report_runs', 'insight_evidence', 'json DEFAULT NULL');
        }
        if ($installedVersion > 0 && $installedVersion < 210) {
            $migration->addField('glpi_plugin_marifex_report_runs', 'presentation_evidence', 'json DEFAULT NULL');
        }
        $migration->executeMigration();

        if (!$baselineTableExisted || ($installedVersion > 0 && $installedVersion < 220)) {
            $baselineCount = (new MonitoringBaselineCollector())->captureEarliestCertifiedObservations();
            (new AnalyticalAuditService())->record('monitoring_baselines_established', [], ['created' => $baselineCount, 'provenance' => 'OBSERVED'], 0, 0);
        }

        if ($installedVersion > 0 && $installedVersion < 210) {
            (new \GlpiPlugin\Marifex\Palette\PaletteMigrationService())->migrateDashboardDefinitions();
        }

        Config::setConfigurationValues('plugin:marifex', array_merge([
            'retain_analytics_on_uninstall' => 1,
            'etl_batch_size' => 500,
            'snapshot_timezone' => 'UTC',
            'report_retention_days' => 30,
            'headless_browser_path' => '',
        ], $configuration, [
            'schema_version' => (string) self::VERSION,
        ]));
        if ($installedVersion < self::VERSION) {
            (new AnalyticalAuditService())->record('scope_version_installed', ['schema_version' => $installedVersion], ['schema_version' => self::VERSION], 0, 0);
        }
    }

    public function uninstall(): bool
    {
        global $DB;

        $config = Config::getConfigurationValues('plugin:marifex');
        $retain = (bool) ($config['retain_analytics_on_uninstall'] ?? true);

        if (!$retain) {
            foreach (array_reverse(array_keys(Schema::tables())) as $table) {
                if ($DB->tableExists($table)) {
                    $DB->doQuery(sprintf('DROP TABLE `%s`', $table));
                }
            }
        }

        Config::deleteConfigurationValues('plugin:marifex', ['schema_version']);
        return true;
    }
}

