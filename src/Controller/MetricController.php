<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use DateTimeImmutable;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Metric\MetricQueryService;
use GlpiPlugin\Marifex\Profile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MetricController extends AbstractController
{
    #[Route('/api/metrics/{metricKey}', name: 'marifex_metric', methods: ['GET'])]
    public function __invoke(string $metricKey, Request $request): Response
    {
        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $from = $this->dateOrNull($request->query->get('from'));
            $to = $this->dateOrNull($request->query->get('to'));
            $payload = (new MetricQueryService())->query($metricKey, $from, $to);
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException('Unknown or invalid metric request.', $exception);
        }

        return new JsonResponse($payload, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }
        return $date;
    }
}

