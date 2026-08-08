<?php

declare(strict_types=1);

use GlpiPlugin\Marifex\Cron\AnalyticsCron;
use GlpiPlugin\Marifex\Install\Installer;
use GlpiPlugin\Marifex\Profile;

function plugin_marifex_install(): bool
{
    if (is_file(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    $installer = new Installer();
    $installer->install();
    Profile::installRights();

    CronTask::register(
        AnalyticsCron::class,
        'incrementalEtl',
        300,
        ['mode' => CronTask::MODE_EXTERNAL, 'comment' => 'MarifeX incremental analytics ETL']
    );
    CronTask::register(
        AnalyticsCron::class,
        'dailySnapshot',
        DAY_TIMESTAMP,
        ['mode' => CronTask::MODE_EXTERNAL, 'comment' => 'MarifeX logical daily backlog snapshot']
    );
    CronTask::register(
        AnalyticsCron::class,
        'incrementalLogEtl',
        300,
        ['mode' => CronTask::MODE_EXTERNAL, 'comment' => 'MarifeX incremental ticket log ETL']
    );
    CronTask::register(
        AnalyticsCron::class,
        'reconcileAnalytics',
        DAY_TIMESTAMP,
        ['mode' => CronTask::MODE_EXTERNAL, 'comment' => 'MarifeX analytics reconciliation']
    );
    CronTask::register(
        AnalyticsCron::class,
        'scheduledReports',
        300,
        ['mode' => CronTask::MODE_EXTERNAL, 'comment' => 'Generate and deliver governed MarifeX dashboard reports']
    );

    return true;
}

function plugin_marifex_uninstall(): bool
{
    if (is_file(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    Profile::uninstallRights();

    return (new Installer())->uninstall();
}

