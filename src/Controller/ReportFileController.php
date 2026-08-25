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
use GlpiPlugin\Marifex\Profile;
use GlpiPlugin\Marifex\Report\ReportFileStore;
use GlpiPlugin\Marifex\Security\EntityScope;
use Session;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReportFileController extends AbstractController
{
    #[Route('/reports/files/{runId}', name: 'marifex_report_file', requirements: ['runId' => '\\d+'], methods: ['GET'])]
    public function __invoke(int $runId): Response
    {
        global $DB;
        if (!Profile::canView() || !Profile::canExport()) throw new AccessDeniedHttpException();
        $where = [
            'id' => $runId,
            'status' => 'completed',
            'entities_id' => (new EntityScope())->activeEntityIds(),
        ];
        if (!Profile::canAdminister()) {
            $where['users_id'] = (int) Session::getLoginUserID();
        }
        $run = $DB->request(['FROM' => 'glpi_plugin_marifex_report_runs', 'WHERE' => $where, 'LIMIT' => 1])->current();
        $path = (string) ($run['file_path'] ?? '');
        if (!$run || $path === '' || !(new ReportFileStore())->isManaged($path) || !is_file($path)
            || !hash_equals((string) $run['file_hash'], hash_file('sha256', $path))) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $run['format'] === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $run['file_name']);
        return $response;
    }
}
