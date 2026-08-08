<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Install;

use Config;
use DBmysql;
use Migration;
use RuntimeException;

final class Installer
{
    private const VERSION = 140;
    private const TABLE_PREFIX = 'glpi_plugin_marifex_';

    public function install(): void
    {
        global $DB;

        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }

        $configuration = Config::getConfigurationValues('plugin:marifex');
        $installedVersion = (int) ($configuration['schema_version'] ?? 0);
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
        $migration->executeMigration();

        Config::setConfigurationValues('plugin:marifex', array_merge([
            'retain_analytics_on_uninstall' => 1,
            'etl_batch_size' => 500,
            'snapshot_timezone' => 'UTC',
        ], $configuration, [
            'schema_version' => (string) self::VERSION,
        ]));
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

