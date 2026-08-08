<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/Dashboard', name: 'marifex_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new RedirectResponse('/front/central.php?forcetab=GlpiPlugin%5CMarifex%5CHomeDashboardTab%241');
    }
}

