<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Insight\InsightService;
use GlpiPlugin\Marifex\Profile;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class InsightController extends AbstractController
{
    #[Route('/api/insights', name: 'marifex_insights', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!Profile::canView()) throw new AccessDeniedHttpException();
        $horizon = $request->query->getInt('horizon', 30);
        $groupId = $request->query->getInt('group_id');
        $domains = array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('domains', '')))));
        try {
            return new JsonResponse((new InsightService())->build($horizon, $groupId > 0 ? $groupId : null, null, $domains));
        } catch (RuntimeException $error) {
            throw new BadRequestHttpException($error->getMessage(), $error);
        }
    }
}
