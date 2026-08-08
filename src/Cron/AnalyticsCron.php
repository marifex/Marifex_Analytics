<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Cron;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Marifex\Etl\IncrementalTicketEtl;
use GlpiPlugin\Marifex\Etl\IncrementalLogEtl;
use GlpiPlugin\Marifex\Etl\AnalyticsReconciler;
use GlpiPlugin\Marifex\Etl\SnapshotBuilder;
use GlpiPlugin\Marifex\Report\ScheduledReportRunner;
use Throwable;

final class AnalyticsCron extends CommonDBTM
{
    public static function cronInfo($name): array
    {
        return match ($name) {
            'incrementalEtl' => ['description' => __('Incremental ticket analytics ETL', 'marifex')],
            'dailySnapshot' => ['description' => __('Build daily ticket, asset, licence, change and problem rollups', 'marifex')],
            'incrementalLogEtl' => ['description' => __('Import verified ticket status events', 'marifex')],
            'reconcileAnalytics' => ['description' => __('Reconcile ticket analytics with GLPI', 'marifex')],
            'scheduledReports' => ['description' => __('Generate and deliver scheduled MarifeX dashboard reports', 'marifex')],
            default => [],
        };
    }

    public static function cronIncrementalEtl(CronTask $task): int
    {
        try {
            $processed = (new IncrementalTicketEtl())->run();
            $task->addVolume($processed);
            return 1;
        } catch (Throwable $exception) {
            $task->log($exception->getMessage());
            return 0;
        }
    }

    public static function cronDailySnapshot(CronTask $task): int
    {
        try {
            $processed = (new SnapshotBuilder())->run();
            $task->addVolume($processed);
            return 1;
        } catch (Throwable $exception) {
            $task->log($exception->getMessage());
            return 0;
        }
    }

    public static function cronIncrementalLogEtl(CronTask $task): int
    {
        try {
            $processed = (new IncrementalLogEtl())->run();
            $task->addVolume($processed);
            return 1;
        } catch (Throwable $exception) {
            $task->log($exception->getMessage());
            return 0;
        }
    }

    public static function cronReconcileAnalytics(CronTask $task): int
    {
        try {
            $differences = (new AnalyticsReconciler())->run();
            $task->addVolume($differences);
            return 1;
        } catch (Throwable $exception) {
            $task->log($exception->getMessage());
            return 0;
        }
    }

    public static function cronScheduledReports(CronTask $task): int
    {
        try {
            $processed = (new ScheduledReportRunner())->run();
            $task->addVolume($processed);
            return 1;
        } catch (Throwable $exception) {
            $task->log($exception->getMessage());
            return 0;
        }
    }
}

