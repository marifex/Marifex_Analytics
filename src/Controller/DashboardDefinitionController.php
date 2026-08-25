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
use InvalidArgumentException;
use JsonException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardDefinitionController extends AbstractController
{
    #[Route('/api/dashboard', name: 'marifex_dashboard_definition', methods: ['GET', 'POST', 'PUT', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }
        $service = new DashboardDefinitionService();
        try {
            if (!$request->isMethod('GET')) {
                Session::checkCSRF(['_glpi_csrf_token' => $request->headers->get('X-GLPI-CSRF-Token')], true);
            }
            $payload = $request->getContent() === ''
                ? []
                : json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Invalid dashboard payload.');
            }

            if ($request->isMethod('PUT')) {
                if (!is_array($payload['definition'] ?? null)) {
                    throw new InvalidArgumentException('Invalid dashboard definition.');
                }
                $id = isset($payload['id']) ? (int) $payload['id'] : null;
                $result = $service->save($id, (string) ($payload['name'] ?? ''), $payload['definition']);
            } elseif ($request->isMethod('POST')) {
                $result = match ((string) ($payload['action'] ?? '')) {
                    'create' => $service->createFromTemplate((string) ($payload['template'] ?? ''), (string) ($payload['name'] ?? '')),
                    'duplicate' => $service->duplicate((int) ($payload['id'] ?? 0), (string) ($payload['name'] ?? '')),
                    'activate' => $service->activate((int) ($payload['id'] ?? 0)),
                    default => throw new InvalidArgumentException('Unsupported dashboard operation.'),
                };
            } elseif ($request->isMethod('DELETE')) {
                $result = $service->delete((int) ($payload['id'] ?? 0));
            } else {
                $result = $service->workspace();
            }
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new BadRequestHttpException('Invalid dashboard definition.', $exception);
        }
        return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
