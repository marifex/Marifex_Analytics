<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Config;
use DateTimeZone;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Profile;
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

        return $this->render('@marifex/settings/index.html.twig', [
            'config' => Config::getConfigurationValues('plugin:marifex'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'pipelines' => $this->pipelines(),
        ]);
    }

    private function save(Request $request): void
    {
        $batchSize = $request->request->getInt('etl_batch_size');
        $timezone = $request->request->getString('snapshot_timezone');

        if ($batchSize < 50 || $batchSize > 5000) {
            throw new BadRequestHttpException('ETL batch size must be between 50 and 5,000.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new BadRequestHttpException('Unknown snapshot timezone.');
        }

        Config::setConfigurationValues('plugin:marifex', [
            'etl_batch_size' => $batchSize,
            'snapshot_timezone' => $timezone,
            'retain_analytics_on_uninstall' => $request->request->getBoolean('retain_analytics_on_uninstall') ? 1 : 0,
        ]);
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
}
