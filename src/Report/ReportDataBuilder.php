<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use DateTimeImmutable;
use DateTimeZone;
use GlpiPlugin\Marifex\Metric\MetricQueryService;
use GlpiPlugin\Marifex\Insight\InsightDomainRegistry;
use GlpiPlugin\Marifex\Insight\InsightService;
use GlpiPlugin\Marifex\Security\EntityScope;

final class ReportDataBuilder
{
    /** @param array<string, mixed> $dashboard
     *  @param list<int> $entityIds
     *  @return array<string, mixed>
     */
    public function build(array $dashboard, array $entityIds, int $entityId, string $timezone = 'UTC', bool $recursive = false): array
    {
        $definition = $dashboard['definition'];
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $to = $now->setTime(0, 0);
        $from = $to->modify(sprintf('-%d days', (int) $definition['dateRangeDays']));
        $scope = new EntityScope($entityIds, $entityId, $recursive);
        $query = new MetricQueryService($scope);
        $groupId = isset($definition['filters']['groupId']) ? (int) $definition['filters']['groupId'] : null;
        $widgets = [];
        foreach ($definition['widgets'] as $widget) {
            $supportsGroup = in_array($widget['metric'], ['current_open_tickets', 'historical_open_backlog'], true);
            $widgets[] = [
                'definition' => $widget,
                'data' => $query->query($widget['metric'], $from, $to, $supportsGroup && $groupId > 0 ? $groupId : null),
            ];
        }
        $insights = (new InsightService($scope))->build(
            (int) $definition['dateRangeDays'],
            $groupId > 0 ? $groupId : null,
            $to->modify('-1 day'),
            InsightDomainRegistry::forWidgets($definition['widgets']),
        );
        return [
            'dashboard' => $dashboard,
            'generated_at' => $now->format(DATE_ATOM),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => $timezone,
            'entities_id' => $entityId,
            'widgets' => $widgets,
            'insights' => $insights,
        ];
    }
}
