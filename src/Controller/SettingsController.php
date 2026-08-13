<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Config;
use DateTimeZone;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Metric\Phase4StatusService;
use GlpiPlugin\Marifex\Insight\InsightService;
use GlpiPlugin\Marifex\Insight\AnalyticalAuditService;
use GlpiPlugin\Marifex\Profile;
use GlpiPlugin\Marifex\Report\HeadlessPdfRenderer;
use GlpiPlugin\Marifex\Security\EntityScope;
use GlpiPlugin\Marifex\Palette\PaletteService;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/Settings', name: 'marifex_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        global $CFG_GLPI;

        if (!Profile::canAdminister()) {
            throw new AccessDeniedHttpException();
        }

        if ($request->isMethod('POST')) {
            $this->save($request);
            Session::addMessageAfterRedirect(__('MarifeX configuration saved.', 'marifex'));
            return new RedirectResponse($CFG_GLPI['root_doc'] . '/plugins/marifex/Settings');
        }

        $phase4Metrics = (new Phase4StatusService())->all();
        $phase5 = (new InsightService())->build(30);
        return $this->render('@marifex/settings/index.html.twig', [
            'config' => Config::getConfigurationValues('plugin:marifex'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'pipelines' => $this->pipelines(),
            'mappings' => $this->mappings(),
            'reconciliations' => $this->reconciliations(),
            'phase4_metrics' => $phase4Metrics,
            'phase5_readiness' => [
                'certified_current' => count(array_filter($phase4Metrics, static fn(array $metric): bool => in_array($metric['status'], ['current', 'live'], true))),
                'certified_total' => count($phase4Metrics),
                'comparison_ready' => (int) ($phase5['readiness']['ready_metrics'] ?? 0),
                'comparison_total' => (int) ($phase5['readiness']['total_metrics'] ?? 0),
                'activation_counts' => $phase5['readiness']['activation_counts'] ?? [],
                'formula_version' => (string) ($phase5['formula_version'] ?? ''),
                'metrics' => $phase5['readiness']['metrics'] ?? [],
            ],
            'report_engine' => (new HeadlessPdfRenderer())->status(),
            'report_runs' => $this->reportRuns(),
            'report_file_url' => $CFG_GLPI['root_doc'] . '/plugins/marifex/reports/files',
            'palette_catalogue' => (new PaletteService())->catalogue(),
            'palette_endpoint' => $CFG_GLPI['root_doc'] . '/plugins/marifex/api/palettes',
        ]);
    }

    private function save(Request $request): void
    {
        $before = Config::getConfigurationValues('plugin:marifex');
        $batchSize = $request->request->getInt('etl_batch_size');
        $timezone = $request->request->getString('snapshot_timezone');
        $retention = $request->request->getInt('report_retention_days');
        $browserPath = trim($request->request->getString('headless_browser_path'));

        if ($batchSize < 50 || $batchSize > 5000) {
            throw new BadRequestHttpException('ETL batch size must be between 50 and 5,000.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new BadRequestHttpException('Unknown snapshot timezone.');
        }
        if ($retention < 1 || $retention > 365) {
            throw new BadRequestHttpException('Report retention must be between 1 and 365 days.');
        }
        if ($browserPath !== '' && (!is_file($browserPath) || !is_executable($browserPath))) {
            throw new BadRequestHttpException('The headless browser path must identify an executable file.');
        }

        Config::setConfigurationValues('plugin:marifex', [
            'etl_batch_size' => $batchSize,
            'snapshot_timezone' => $timezone,
            'retain_analytics_on_uninstall' => $request->request->getBoolean('retain_analytics_on_uninstall') ? 1 : 0,
            'report_retention_days' => $retention,
            'headless_browser_path' => $browserPath,
        ]);
        $after = Config::getConfigurationValues('plugin:marifex');
        $keys = ['etl_batch_size', 'snapshot_timezone', 'retain_analytics_on_uninstall', 'report_retention_days', 'headless_browser_path'];
        (new AnalyticalAuditService())->record('configuration_changed', array_intersect_key($before, array_flip($keys)), array_intersect_key($after, array_flip($keys)));
    }

    /** @return list<array<string, mixed>> */
    private function pipelines(): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_etl_checkpoints')) {
            return [];
        }

        $pipelines = [];
        foreach ($DB->request([
            'SELECT' => ['pipeline', 'source_table', 'watermark_id', 'watermark_date', 'status', 'locked_at', 'last_error'],
            'FROM' => 'glpi_plugin_marifex_etl_checkpoints',
            'ORDER' => ['pipeline ASC'],
        ]) as $row) {
            $pipelines[] = $row;
        }
        return $pipelines;
    }

    /** @return list<array<string, mixed>> */
    private function mappings(): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_event_mappings')) {
            return [];
        }

        return iterator_to_array($DB->request([
            'SELECT' => ['semantic_event', 'source_field', 'search_option_id', 'glpi_version_min', 'validation_status', 'validated_at'],
            'FROM' => 'glpi_plugin_marifex_event_mappings',
            'ORDER' => ['semantic_event ASC'],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function reconciliations(): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_reconciliation_runs')) {
            return [];
        }

        return iterator_to_array($DB->request([
            'SELECT' => ['scope', 'completed_at', 'source_count', 'analytics_count', 'missing_count', 'orphan_count', 'status'],
            'FROM' => 'glpi_plugin_marifex_reconciliation_runs',
            'ORDER' => ['id DESC'],
            'LIMIT' => 5,
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function reportRuns(): array
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_report_runs')) return [];
        return iterator_to_array($DB->request([
            'SELECT' => ['id', 'format', 'status', 'file_name', 'file_path', 'recipient_count', 'formula_version', 'error_message', 'started_at', 'completed_at'],
            'FROM' => 'glpi_plugin_marifex_report_runs',
            'WHERE' => (new EntityScope())->criteria(),
            'ORDER' => ['id DESC'],
            'LIMIT' => 10,
        ]), false);
    }
}
