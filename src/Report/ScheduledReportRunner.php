<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use Throwable;

final class ScheduledReportRunner
{
    public function run(): int
    {
        global $DB;
        $processed = 0;
        $exporter = new ReportExporter();
        $authorization = new ReportAuthorizationService();
        $schedules = iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_marifex_report_schedules',
            'WHERE' => ['is_active' => 1, ['next_run_at' => ['<=', gmdate('Y-m-d H:i:s')]]],
            'ORDER' => ['next_run_at ASC'],
            'LIMIT' => 10,
        ]), false);
        foreach ($schedules as $schedule) {
            $next = ReportScheduleService::nextRunAt($schedule)->format('Y-m-d H:i:s');
            $DB->update('glpi_plugin_marifex_report_schedules', [
                'last_run_at' => gmdate('Y-m-d H:i:s'),
                'next_run_at' => $next,
            ], ['id' => (int) $schedule['id'], 'next_run_at' => $schedule['next_run_at']]);
            if (!$authorization->canExecute((int) $schedule['users_id'], (int) $schedule['entities_id'])) {
                $exporter->recordBlocked($schedule, 'The schedule owner no longer has export and scheduling rights in this entity.');
                $DB->update('glpi_plugin_marifex_report_schedules', ['is_active' => 0], ['id' => (int) $schedule['id']]);
                $processed++;
                continue;
            }
            try {
                $recipients = json_decode((string) $schedule['recipients'], true, 32, JSON_THROW_ON_ERROR);
                $authorization->validateRecipients($recipients, (int) $schedule['entities_id']);
            } catch (Throwable $error) {
                $exporter->recordBlocked($schedule, $error->getMessage());
                $processed++;
                continue;
            }
            try {
                $artifact = $exporter->createScheduled($schedule);
            } catch (Throwable) {
                $processed++;
                continue;
            }
            try {
                (new ReportEmailDelivery())->send($schedule, $artifact);
            } catch (Throwable $error) {
                $DB->update('glpi_plugin_marifex_report_runs', [
                    'status' => 'failed',
                    'error_message' => mb_substr($error->getMessage(), 0, 4000),
                ], ['id' => (int) $artifact['id']]);
            }
            $processed++;
        }
        $exporter->cleanupExpired();
        return $processed;
    }
}
