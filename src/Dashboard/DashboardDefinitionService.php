<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Dashboard;

use GlpiPlugin\Marifex\Security\EntityScope;
use InvalidArgumentException;
use Session;

final class DashboardDefinitionService
{
    private const MAX_DASHBOARDS = 20;
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
    public function workspace(): array
    {
        $rows = $this->rows();
        $active = null;
        foreach ($rows as $row) {
            if ((int) $row['is_active'] === 1) {
                $active = $row;
                break;
            }
        }

        return [
            'dashboard' => $active ? $this->dashboardFromRow($active) : $this->defaultDashboard(),
            'dashboards' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'is_active' => (int) $row['is_active'] === 1,
                'date_mod' => $row['date_mod'],
            ], $rows),
            'templates' => array_map(static fn(array $template): array => [
                'key' => $template['key'],
                'name' => $template['name'],
                'description' => $template['description'],
            ], array_values($this->templates())),
        ];
    }

    /** @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    public function save(?int $id, string $name, array $definition): array
    {
        global $DB;
        $name = $this->validateName($name);
        $definition = $this->validate($definition);

        if ($id === null) {
            $this->ensureCapacity();
            $this->deactivateAll();
            $DB->insert('glpi_plugin_marifex_dashboard_definitions', $this->values($name, $definition) + [
                'date_creation' => gmdate('Y-m-d H:i:s'),
            ]);
        } else {
            $this->ownedRow($id);
            $DB->update('glpi_plugin_marifex_dashboard_definitions', [
                'name' => $name,
                'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'is_recursive' => Session::getIsActiveEntityRecursive() ? 1 : 0,
            ], $this->ownershipWhere() + ['id' => $id]);
        }

        return $this->workspace();
    }

    /** @return array<string, mixed> */
    public function createFromTemplate(string $templateKey, string $name): array
    {
        global $DB;
        $templates = $this->templates();
        if (!isset($templates[$templateKey])) {
            throw new InvalidArgumentException('Unknown dashboard template.');
        }
        $this->ensureCapacity();
        $this->deactivateAll();
        $DB->insert('glpi_plugin_marifex_dashboard_definitions', $this->values(
            $this->validateName($name),
            $this->validate($templates[$templateKey]['definition'])
        ) + ['date_creation' => gmdate('Y-m-d H:i:s')]);

        return $this->workspace();
    }

    /** @return array<string, mixed> */
    public function duplicate(int $id, string $name): array
    {
        global $DB;
        $row = $this->ownedRow($id);
        $definition = json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR);
        $this->ensureCapacity();
        $this->deactivateAll();
        $DB->insert('glpi_plugin_marifex_dashboard_definitions', $this->values(
            $this->validateName($name),
            $this->validate($definition)
        ) + ['date_creation' => gmdate('Y-m-d H:i:s')]);

        return $this->workspace();
    }

    /** @return array<string, mixed> */
    public function activate(int $id): array
    {
        global $DB;
        $this->ownedRow($id);
        $this->deactivateAll();
        $DB->update('glpi_plugin_marifex_dashboard_definitions', ['is_active' => 1], $this->ownershipWhere() + ['id' => $id]);
        return $this->workspace();
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        global $DB;
        $row = $this->ownedRow($id);
        $wasActive = (int) $row['is_active'] === 1;
        $DB->delete('glpi_plugin_marifex_dashboard_definitions', $this->ownershipWhere() + ['id' => $id]);

        if ($wasActive) {
            $remaining = $this->rows();
            if ($remaining !== []) {
                $DB->update('glpi_plugin_marifex_dashboard_definitions', ['is_active' => 1], $this->ownershipWhere() + ['id' => (int) $remaining[0]['id']]);
            }
        }
        return $this->workspace();
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT' => ['id', 'name', 'definition', 'is_active', 'date_mod'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => $this->ownershipWhere(),
            'ORDER' => ['is_active DESC', 'date_mod DESC', 'id DESC'],
        ]), false);
    }

    /** @return array<string, mixed> */
    private function ownedRow(int $id): array
    {
        global $DB;
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid dashboard identifier.');
        }
        $row = $DB->request([
            'SELECT' => ['id', 'name', 'definition', 'is_active', 'date_mod'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => $this->ownershipWhere() + ['id' => $id],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            throw new InvalidArgumentException('Dashboard is not available in the current user and entity scope.');
        }
        return $row;
    }

    /** @return array<string, int> */
    private function ownershipWhere(): array
    {
        return [
            'users_id' => (int) Session::getLoginUserID(),
            'entities_id' => $this->entityScope->activeEntityId(),
        ];
    }

    private function deactivateAll(): void
    {
        global $DB;
        $DB->update('glpi_plugin_marifex_dashboard_definitions', ['is_active' => 0], $this->ownershipWhere());
    }

    private function ensureCapacity(): void
    {
        if (count($this->rows()) >= self::MAX_DASHBOARDS) {
            throw new InvalidArgumentException('A user can save at most 20 dashboards per entity.');
        }
    }

    /** @param array<string, mixed> $definition
     *  @return array<string, mixed>
     */
    private function values(string $name, array $definition): array
    {
        return [
            'name' => $name,
            'entities_id' => $this->entityScope->activeEntityId(),
            'is_recursive' => Session::getIsActiveEntityRecursive() ? 1 : 0,
            'is_active' => 1,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'users_id' => (int) Session::getLoginUserID(),
        ];
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Dashboard name must contain 1 to 120 characters.');
        }
        return $name;
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
        $refresh = (int) ($input['refreshMinutes'] ?? 0);
        if (!in_array($refresh, [0, 5, 15, 30, 60], true)) {
            throw new InvalidArgumentException('Unsupported dashboard refresh interval.');
        }
        $groupId = $input['filters']['groupId'] ?? null;
        if ($groupId !== null && (!is_int($groupId) || $groupId < 1)) {
            throw new InvalidArgumentException('Invalid saved group filter.');
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
            $validated[] = [
                'id' => $id,
                'metric' => $metric,
                'type' => $type,
                'title' => $title,
                'w' => max(3, min(12, (int) ($widget['w'] ?? 4))),
                'h' => max(2, min(8, (int) ($widget['h'] ?? 3))),
            ];
            $ids[$id] = true;
        }
        return [
            'version' => 2,
            'dateRangeDays' => $range,
            'refreshMinutes' => $refresh,
            'filters' => ['groupId' => $groupId],
            'widgets' => $validated,
        ];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function dashboardFromRow(array $row): array
    {
        $definition = json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR);
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'definition' => $this->validate($definition),
            'date_mod' => $row['date_mod'],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultDashboard(): array
    {
        $template = $this->templates()['executive'];
        return ['id' => null, 'name' => $template['name'], 'definition' => $template['definition'], 'date_mod' => null];
    }

    /** @return array<string, array<string, mixed>> */
    private function templates(): array
    {
        $base = ['version' => 2, 'dateRangeDays' => 30, 'refreshMinutes' => 0, 'filters' => ['groupId' => null]];
        return [
            'executive' => [
                'key' => 'executive',
                'name' => 'Executive Operations Command',
                'description' => 'Current state, trajectory, workload concentration and service ownership.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'open-now', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Open now', 'w' => 3, 'h' => 2],
                    ['id' => 'average-age', 'metric' => 'average_open_ticket_age', 'type' => 'kpi', 'title' => 'Average ticket age', 'w' => 3, 'h' => 2],
                    ['id' => 'backlog-trend', 'metric' => 'historical_open_backlog', 'type' => 'line', 'title' => 'Enterprise backlog trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'group-share', 'metric' => 'historical_group_backlog', 'type' => 'donut', 'title' => 'Workload concentration', 'w' => 5, 'h' => 4],
                    ['id' => 'group-ranking', 'metric' => 'historical_group_backlog', 'type' => 'table', 'title' => 'Service ownership ranking', 'w' => 7, 'h' => 4],
                ]],
            ],
            'service-desk' => [
                'key' => 'service-desk',
                'name' => 'Service Desk Operations',
                'description' => 'Backlog, ticket age and assignment-group workload for daily operations.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'desk-open', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Open service desk tickets', 'w' => 4, 'h' => 2],
                    ['id' => 'desk-age', 'metric' => 'average_open_ticket_age', 'type' => 'line', 'title' => 'Ticket age trajectory', 'w' => 8, 'h' => 3],
                    ['id' => 'desk-backlog', 'metric' => 'historical_open_backlog', 'type' => 'line', 'title' => 'Backlog trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-groups', 'metric' => 'historical_group_backlog', 'type' => 'bar', 'title' => 'Assignment group workload', 'w' => 6, 'h' => 4],
                ]],
            ],
            'team-workload' => [
                'key' => 'team-workload',
                'name' => 'Team Workload',
                'description' => 'Focused workload distribution and service ownership ranking.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'team-open', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Focused open tickets', 'w' => 4, 'h' => 2],
                    ['id' => 'team-share', 'metric' => 'historical_group_backlog', 'type' => 'donut', 'title' => 'Workload concentration', 'w' => 4, 'h' => 4],
                    ['id' => 'team-bars', 'metric' => 'historical_group_backlog', 'type' => 'bar', 'title' => 'Group comparison', 'w' => 8, 'h' => 4],
                    ['id' => 'team-table', 'metric' => 'historical_group_backlog', 'type' => 'table', 'title' => 'Service ownership ranking', 'w' => 12, 'h' => 4],
                ]],
            ],
        ];
    }
}
