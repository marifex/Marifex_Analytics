<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex;

use Central;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;

final class HomeDashboardTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Analytics', 'marifex');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Central || !Profile::canView()) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-chart-histogram');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Central || !Profile::canView()) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@marifex/dashboard/embed.html.twig', [
            'metric_endpoint' => '/plugins/marifex/api/metrics',
            'definition_endpoint' => '/plugins/marifex/api/dashboard',
            'ticket_drilldown_url' => '/plugins/marifex/drilldown/tickets',
            'asset_search_url' => '/front/computer.php',
            'licence_search_url' => '/front/softwarelicense.php',
            'change_search_url' => '/front/change.php',
            'problem_search_url' => '/front/problem.php',
            'report_export_url' => '/plugins/marifex/reports/export',
            'report_schedule_endpoint' => '/plugins/marifex/api/reports/schedules',
            'can_export' => Profile::canExport(),
            'can_schedule' => Profile::canSchedule(),
        ]);
        return true;
    }
}
