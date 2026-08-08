<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Profile;
use GlpiPlugin\Marifex\Report\ReportScheduleService;
use InvalidArgumentException;
use JsonException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReportScheduleController extends AbstractController
{
    #[Route('/api/reports/schedules', name: 'marifex_report_schedules', methods: ['GET', 'POST', 'PUT', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        if (!Profile::canView() || !Profile::canSchedule()) {
            throw new AccessDeniedHttpException();
        }
        try {
            if (!$request->isMethod('GET')) {
                Session::checkCSRF(['_glpi_csrf_token' => $request->headers->get('X-GLPI-CSRF-Token')], true);
            }
            $payload = $request->getContent() === '' ? [] : json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new InvalidArgumentException('Invalid report schedule payload.');
            $service = new ReportScheduleService();
            $result = match ($request->getMethod()) {
                'POST' => $service->save(null, $payload),
                'PUT' => $service->save((int) ($payload['id'] ?? 0), $payload),
                'DELETE' => $service->delete((int) ($payload['id'] ?? 0)),
                default => $service->all(),
            };
        } catch (InvalidArgumentException|JsonException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }
        return new JsonResponse(['schedules' => $result], 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
