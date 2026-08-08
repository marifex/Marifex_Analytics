<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\Profile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/Dashboard', name: 'marifex_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }

        return $this->render('@marifex/dashboard/index.html.twig', [
            'metric_endpoint' => '/plugins/marifex/api/metrics',
            'definition_endpoint' => '/plugins/marifex/api/dashboard',
            'ticket_drilldown_url' => '/plugins/marifex/drilldown/tickets',
        ]);
    }
}

