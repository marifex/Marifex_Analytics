<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use Config;
use GlpiPlugin\Marifex\Security\EntityScope;
use RuntimeException;
use Throwable;

final class ReportExporter
{
    public function __construct(
        private readonly ReportFileStore $store = new ReportFileStore(),
        private readonly ReportDataBuilder $data = new ReportDataBuilder(),
    ) {
    }

    /** @param array<string, mixed> $dashboard
     *  @return array<string, mixed>
     */
    public function createImmediate(array $dashboard, string $format): array
    {
        $scope = new EntityScope();
        return $this->create($dashboard, $scope->activeEntityIds(), $scope->activeEntityId(), $format, null, 0, 'UTC');
    }

    /** @param array<string, mixed> $schedule
     *  @return array<string, mixed>
     */
    public function createScheduled(array $schedule): array
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id', 'name', 'definition'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => [
                'id' => (int) $schedule['dashboard_definitions_id'],
                'users_id' => (int) $schedule['users_id'],
                'entities_id' => (int) $schedule['entities_id'],
            ],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            throw new RuntimeException('The scheduled dashboard no longer exists.');
        }
        $dashboard = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'definition' => json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR),
        ];
        $entityId = (int) $schedule['entities_id'];
        $entityIds = [$entityId];
        if ((int) $schedule['is_recursive'] === 1) {
            $entityIds = array_values(array_unique(array_merge($entityIds, array_map('intval', getSonsOf('glpi_entities', $entityId)))));
        }
        $recipients = json_decode((string) $schedule['recipients'], true, 32, JSON_THROW_ON_ERROR);
        return $this->create(
            $dashboard,
            $entityIds,
            $entityId,
            (string) $schedule['format'],
            (int) $schedule['id'],
            count($recipients),
            (string) $schedule['timezone'],
            (int) $schedule['users_id'],
        );
    }

    /** @param array<string, mixed> $dashboard
     *  @param list<int> $entityIds
     *  @return array<string, mixed>
     */
    private function create(
        array $dashboard,
        array $entityIds,
        int $entityId,
        string $format,
        ?int $scheduleId,
        int $recipientCount,
        string $timezone,
        ?int $userId = null,
    ): array {
        global $DB;
        if (!in_array($format, ['pdf', 'csv'], true)) {
            throw new RuntimeException('Unsupported report format.');
        }
        $userId ??= (int) \Session::getLoginUserID();
        $started = gmdate('Y-m-d H:i:s');
        $DB->insert('glpi_plugin_marifex_report_runs', [
            'schedules_id' => $scheduleId,
            'dashboard_definitions_id' => (int) $dashboard['id'],
            'users_id' => $userId,
            'entities_id' => $entityId,
            'format' => $format,
            'status' => 'running',
            'recipient_count' => $recipientCount,
            'started_at' => $started,
        ]);
        $runId = (int) $DB->insertId();
        try {
            $report = $this->data->build($dashboard, $entityIds, $entityId, $timezone);
            $path = $this->store->path($format);
            if ($format === 'csv') {
                (new CsvReportRenderer())->render($report, $path);
            } else {
                $html = (new HtmlReportRenderer())->render($report);
                (new HeadlessPdfRenderer($this->store))->render($html, $path);
            }
            $fileName = $this->slug((string) $dashboard['name']) . '-' . gmdate('Y-m-d') . '.' . $format;
            $config = Config::getConfigurationValues('plugin:marifex');
            $retention = max(1, min(365, (int) ($config['report_retention_days'] ?? 30)));
            $completed = gmdate('Y-m-d H:i:s');
            $DB->update('glpi_plugin_marifex_report_runs', [
                'status' => 'completed',
                'file_name' => $fileName,
                'file_path' => $path,
                'file_hash' => hash_file('sha256', $path),
                'formula_version' => (string) ($report['insights']['formula_version'] ?? ''),
                'insight_evidence' => json_encode($report['insights'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'presentation_evidence' => $format === 'pdf' ? json_encode($this->presentationEvidence($dashboard), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
                'completed_at' => $completed,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + ($retention * DAY_TIMESTAMP)),
            ], ['id' => $runId]);
            return ['id' => $runId, 'path' => $path, 'name' => $fileName, 'format' => $format];
        } catch (Throwable $error) {
            $DB->update('glpi_plugin_marifex_report_runs', [
                'status' => 'failed',
                'error_message' => mb_substr($error->getMessage(), 0, 4000),
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $runId]);
            throw $error;
        }
    }

    /** @param array<string, mixed> $dashboard @return list<array<string, mixed>> */
    private function presentationEvidence(array $dashboard): array
    {
        $service = new \GlpiPlugin\Marifex\Palette\PaletteService(); $result = [];
        foreach (($dashboard['definition']['widgets'] ?? []) as $widget) {
            $palette = $service->resolve((string) ($widget['chartPalette'] ?? ''));
            if ($palette !== null) $result[] = ['widget_id' => (string) $widget['id'], 'palette_id' => (string) $palette['id'], 'palette_name' => (string) $palette['name'], 'palette_revision' => (int) $palette['revision']];
        }
        return $result;
    }

    /** @param array<string, mixed> $schedule */
    public function recordBlocked(array $schedule, string $message): void
    {
        global $DB;
        $DB->insert('glpi_plugin_marifex_report_runs', [
            'schedules_id' => (int) $schedule['id'],
            'dashboard_definitions_id' => (int) $schedule['dashboard_definitions_id'],
            'users_id' => (int) $schedule['users_id'],
            'entities_id' => (int) $schedule['entities_id'],
            'format' => (string) $schedule['format'],
            'status' => 'blocked',
            'recipient_count' => 0,
            'error_message' => mb_substr($message, 0, 4000),
            'started_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function cleanupExpired(): int
    {
        global $DB;
        $count = 0;
        foreach ($DB->request([
            'SELECT' => ['id', 'file_path'],
            'FROM' => 'glpi_plugin_marifex_report_runs',
            'WHERE' => [['expires_at' => ['<', gmdate('Y-m-d H:i:s')]]],
        ]) as $row) {
            $path = (string) ($row['file_path'] ?? '');
            if ($path !== '' && $this->store->isManaged($path) && is_file($path)) {
                unlink($path);
            }
            $DB->update('glpi_plugin_marifex_report_runs', ['file_path' => null], ['id' => (int) $row['id']]);
            $count++;
        }
        return $count;
    }

    private function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
        return $slug === '' ? 'marifex-dashboard' : mb_strtolower($slug);
    }
}
