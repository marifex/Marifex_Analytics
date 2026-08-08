<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Dashboard;

use GlpiPlugin\Marifex\Security\EntityScope;
use InvalidArgumentException;
use Session;

final class DashboardDefinitionService
{
    private const METRICS = [
        'current_open_tickets' => ['kpi'],
        'average_open_ticket_age' => ['kpi', 'line'],
        'historical_open_backlog' => ['kpi', 'line', 'bar'],
        'historical_group_backlog' => ['bar', 'donut', 'table'],
    ];

    public function __construct(private readonly EntityScope $entityScope = new EntityScope())
    {
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        global $DB;
        $userId = (int) Session::getLoginUserID();
        $entityId = $this->entityScope->activeEntityId();
        $row = $DB->request([
            'SELECT' => ['id', 'name', 'definition', 'date_mod'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => ['users_id' => $userId, 'entities_id' => $entityId, 'is_active' => 1],
            'ORDER' => ['date_mod DESC', 'id DESC'],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return ['id' => null, 'name' => 'Executive Operations Command', 'definition' => $this->defaults(), 'date_mod' => null];
        }
        $definition = json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR);
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'definition' => $this->validate($definition), 'date_mod' => $row['date_mod']];
    }

    /** @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    public function save(string $name, array $definition): array
    {
        global $DB;
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Dashboard name must contain 1 to 120 characters.');
        }
        $definition = $this->validate($definition);
        $userId = (int) Session::getLoginUserID();
        $entityId = $this->entityScope->activeEntityId();
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => ['users_id' => $userId, 'entities_id' => $entityId, 'is_active' => 1],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();
        $values = [
            'name' => $name,
            'entities_id' => $entityId,
            'is_recursive' => Session::getIsActiveEntityRecursive() ? 1 : 0,
            'is_active' => 1,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'users_id' => $userId,
        ];
        if ($existing) {
            $DB->update('glpi_plugin_marifex_dashboard_definitions', $values, ['id' => (int) $existing['id'], 'users_id' => $userId]);
        } else {
            $values['date_creation'] = gmdate('Y-m-d H:i:s');
            $DB->insert('glpi_plugin_marifex_dashboard_definitions', $values);
        }
        return $this->load();
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $range = (int) ($input['dateRangeDays'] ?? 30);
        if (!in_array($range, [7, 30, 90, 180, 365], true)) {
            throw new InvalidArgumentException('Unsupported dashboard date range.');
        }
        $widgets = $input['widgets'] ?? null;
        if (!is_array($widgets) || count($widgets) < 1 || count($widgets) > 24) {
            throw new InvalidArgumentException('A dashboard must contain between 1 and 24 widgets.');
        }
        $validated = [];
        $ids = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                throw new InvalidArgumentException('Invalid widget definition.');
            }
            $id = (string) ($widget['id'] ?? '');
            $metric = (string) ($widget['metric'] ?? '');
            $type = (string) ($widget['type'] ?? '');
            $title = trim((string) ($widget['title'] ?? ''));
            if (!preg_match('/^[a-z0-9-]{6,64}$/', $id) || isset($ids[$id])) {
                throw new InvalidArgumentException('Widget IDs must be unique and URL-safe.');
            }
            if (!isset(self::METRICS[$metric]) || !in_array($type, self::METRICS[$metric], true)) {
                throw new InvalidArgumentException('Widget type is not compatible with its certified metric.');
            }
            if ($title === '' || mb_strlen($title) > 100) {
                throw new InvalidArgumentException('Widget title must contain 1 to 100 characters.');
            }
            $w = max(3, min(12, (int) ($widget['w'] ?? 4)));
            $h = max(2, min(8, (int) ($widget['h'] ?? 3)));
            $validated[] = ['id' => $id, 'metric' => $metric, 'type' => $type, 'title' => $title, 'w' => $w, 'h' => $h];
            $ids[$id] = true;
        }
        return ['version' => 1, 'dateRangeDays' => $range, 'widgets' => $validated];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'version' => 1,
            'dateRangeDays' => 30,
            'widgets' => [
                ['id' => 'open-now', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Open now', 'w' => 3, 'h' => 2],
                ['id' => 'average-age', 'metric' => 'average_open_ticket_age', 'type' => 'kpi', 'title' => 'Average ticket age', 'w' => 3, 'h' => 2],
                ['id' => 'backlog-trend', 'metric' => 'historical_open_backlog', 'type' => 'line', 'title' => 'Enterprise backlog trajectory', 'w' => 6, 'h' => 4],
                ['id' => 'group-share', 'metric' => 'historical_group_backlog', 'type' => 'donut', 'title' => 'Workload concentration', 'w' => 5, 'h' => 4],
                ['id' => 'group-ranking', 'metric' => 'historical_group_backlog', 'type' => 'table', 'title' => 'Service ownership ranking', 'w' => 7, 'h' => 4],
            ],
        ];
    }
}
