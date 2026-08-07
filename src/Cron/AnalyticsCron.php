<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Cron;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Marifex\Etl\IncrementalTicketEtl;
use GlpiPlugin\Marifex\Etl\SnapshotBuilder;
use Throwable;

final class AnalyticsCron extends CommonDBTM
{
    public static function cronInfo($name): array
    {
        return match ($name) {
            'incrementalEtl' => ['description' => __('Incremental ticket analytics ETL', 'marifex')],
            'dailySnapshot' => ['description' => __('Build daily ticket snapshot and backlog rollup', 'marifex')],
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
}

