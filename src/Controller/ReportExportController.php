<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Dashboard\DashboardDefinitionService;
use GlpiPlugin\Marifex\Profile;
use GlpiPlugin\Marifex\Report\ReportExporter;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReportExportController extends AbstractController
{
    #[Route('/reports/export/{dashboardId}/{format}', name: 'marifex_report_export', requirements: ['dashboardId' => '\\d+', 'format' => 'pdf|csv'], methods: ['GET'])]
    public function __invoke(int $dashboardId, string $format): Response
    {
        if (!Profile::canView() || !Profile::canExport()) {
            throw new AccessDeniedHttpException();
        }
        try {
            $dashboard = (new DashboardDefinitionService())->reportDashboard($dashboardId);
            $artifact = (new ReportExporter())->createImmediate($dashboard, $format);
        } catch (RuntimeException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }
        $response = new BinaryFileResponse((string) $artifact['path']);
        $response->headers->set('Content-Type', $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $artifact['name']);
        return $response;
    }
}
