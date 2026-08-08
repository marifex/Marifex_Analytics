<?php

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
    #[Route('/api/dashboard', name: 'marifex_dashboard_definition', methods: ['GET', 'PUT'])]
    public function __invoke(Request $request): Response
    {
        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }
        $service = new DashboardDefinitionService();
        try {
            if ($request->isMethod('PUT')) {
                Session::checkCSRF(['_glpi_csrf_token' => $request->headers->get('X-GLPI-CSRF-Token')], true);
                $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !is_array($payload['definition'] ?? null)) {
                    throw new InvalidArgumentException('Invalid dashboard payload.');
                }
                $result = $service->save((string) ($payload['name'] ?? ''), $payload['definition']);
            } else {
                $result = $service->load();
            }
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new BadRequestHttpException('Invalid dashboard definition.', $exception);
        }
        return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
