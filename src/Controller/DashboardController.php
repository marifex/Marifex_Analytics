<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Marifex\HomeDashboardTab;
use GlpiPlugin\Marifex\Profile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/Dashboard', name: 'marifex_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new RedirectResponse('/front/central.php?forcetab=GlpiPlugin%5CMarifex%5CHomeDashboardTab%241');
    }

    /**
     * Chrome-free version of the same dashboard, for the Android app's
     * WebView. Gated by the same Profile::canView() right as the in-GLPI
     * tab and every /api/metrics/* call - no separate access rule to
     * maintain.
     */
    #[Route('/Dashboard/Mobile', name: 'marifex_dashboard_mobile', methods: ['GET'])]
    public function mobile(): Response
    {
        if (!Profile::canView()) {
            throw new AccessDeniedHttpException();
        }
        // Passed here rather than set inside the template - layout/parts/head.html.twig
        // expects these in its rendering context from the first line, and a `{% set %}`
        // inside mobile_embed.html.twig doesn't reliably reach it through the include.
        $headParameters = [
            'is_anonymous_page' => false,
            'css_files' => [
                ['path' => 'lib/base.css'],
                ['path' => 'lib/tabler.css'],
                ['path' => 'lib/gridstack.css'],
                ['path' => 'css/glpi.scss'],
                ['path' => 'css/core_palettes.scss'],
            ],
            'js_files' => [
                ['path' => 'lib/base.js'],
                ['path' => 'js/common.js'],
            ],
            'js_modules' => [],
            'custom_header_tags' => [],
            // The bare page does not use GLPI's normal footer, so its plugin bundle is
            // passed separately and rendered after the dashboard mount point exists.
            'dashboard_js_files' => [
                ['path' => 'lib/gridstack.js'],
                [
                    'path' => 'plugins/marifex/js/dashboard.js',
                    'options' => ['version' => PLUGIN_MARIFEX_VERSION],
                ],
            ],
            'title' => __('Analytics', 'marifex'),
        ];
        return $this->render('@marifex/dashboard/mobile_embed.html.twig', $headParameters + HomeDashboardTab::embedData());
    }
}

