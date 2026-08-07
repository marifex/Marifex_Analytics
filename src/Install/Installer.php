<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Install;

use Config;
use DBmysql;
use Migration;
use RuntimeException;

final class Installer
{
    private const VERSION = 100;
    private const TABLE_PREFIX = 'glpi_plugin_marifex_';

    public function install(): void
    {
        global $DB;

        if (!$DB instanceof DBmysql) {
            throw new RuntimeException('GLPI database connection is unavailable.');
        }

        $migration = new Migration(self::VERSION);
        foreach (Schema::tables() as $table => $sql) {
            if (!$DB->tableExists($table)) {
                $DB->doQuery($sql);
            }
        }
        $migration->executeMigration();

        Config::setConfigurationValues('plugin:marifex', [
            'schema_version' => (string) self::VERSION,
            'retain_analytics_on_uninstall' => 1,
            'etl_batch_size' => 500,
            'snapshot_timezone' => 'UTC',
        ]);
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

