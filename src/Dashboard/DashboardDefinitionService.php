<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Dashboard;

use GlpiPlugin\Marifex\Security\EntityScope;
use InvalidArgumentException;
use Session;
use GlpiPlugin\Marifex\Palette\PaletteRegistry;
use GlpiPlugin\Marifex\Palette\PaletteService;

final class DashboardDefinitionService
{
    private const MAX_DASHBOARDS = 20;
    private const MAX_WIDGETS = 40;
    private const PHASE4_PROVISION = 'phase4-domain-dashboards-v1';
    private const PHASE4_EXECUTIVE_PROVISION = 'phase4-executive-dashboard-v1';
    private const ALIGNED_METRICS_PROVISION = 'aligned-certified-metrics-v1';
    private const PREMIUM_COMMAND_PROVISION = 'premium-command-layout-v1';
    private const PREMIUM_SECTION_ORDER_PROVISION = 'premium-section-order-v1';
    private const PHASE4_TEMPLATES = ['asset-governance', 'change-control', 'problem-control'];
    private const WIDGET_PALETTES = ['cream_gold', 'ocean', 'mint', 'lavender', 'charcoal_gold', 'neutral', 'classic_blue', 'teal_green', 'deep_purple', 'warm_amber', 'coral_red', 'sky_blue', 'bright_orange', 'rose_pink', 'forest_green', 'slate_gray'];
    private const METRICS = [
        'current_open_tickets' => ['kpi'],
        'average_open_ticket_age' => ['kpi', 'line'],
        'historical_open_backlog' => ['kpi', 'line', 'bar'],
        'historical_group_backlog' => ['bar', 'donut', 'table', 'insight'],
        'open_tickets_by_priority' => ['bar', 'donut', 'table', 'insight'],
        'unassigned_open_tickets' => ['kpi', 'line'],
        'average_unassigned_time' => ['kpi', 'line'],
        'tickets_approaching_sla_breach' => ['kpi', 'line'],
        'sla_breach_count' => ['kpi', 'line'],
        'sla_breach_rate' => ['kpi', 'line'],
        'sla_breaches_by_technician' => ['bar', 'donut', 'table', 'insight'],
        'tickets_by_request_source' => ['bar', 'donut', 'table', 'insight'],
        'created_vs_resolved_tickets' => ['line', 'bar', 'table'],
        'assignment_changes_per_ticket' => ['kpi', 'line'],
        'technician_workload_distribution' => ['bar', 'donut', 'table', 'insight'],
        'unsatisfied_survey_responses' => ['kpi', 'line'],
        'resolution_time_age_bands' => ['bar', 'donut', 'table'],
        'asset_inventory_total' => ['kpi', 'line'],
        'asset_inventory_by_state' => ['bar', 'donut', 'table'],
        'stale_computer_inventory' => ['kpi', 'line'],
        'prohibited_software_installations' => ['bar', 'donut', 'table'],
        'unlicensed_software_installations' => ['bar', 'donut', 'table'],
        'low_disk_capacity_computers' => ['kpi', 'line'],
        'computers_in_stock_over_30_days' => ['kpi', 'line'],
        'incidents_by_operating_system' => ['bar', 'donut', 'table'],
        'repeat_incident_computers' => ['bar', 'donut', 'table'],
        'software_license_entitlements' => ['kpi', 'line'],
        'software_license_allocations' => ['kpi', 'line'],
        'software_license_overallocated_seats' => ['kpi', 'line'],
        'software_license_compliance_rate' => ['kpi', 'line'],
        'open_changes' => ['kpi', 'line'],
        'daily_change_volume' => ['kpi', 'line', 'bar'],
        'daily_change_resolutions' => ['kpi', 'line', 'bar'],
        'open_change_status_distribution' => ['bar', 'donut', 'table'],
        'open_problems' => ['kpi', 'line'],
        'daily_problem_volume' => ['kpi', 'line', 'bar'],
        'daily_problem_resolutions' => ['kpi', 'line', 'bar'],
        'open_problem_status_distribution' => ['bar', 'donut', 'table'],
        'latest_solution_refused_tickets' => ['kpi', 'detail_table'],
        'open_incidents_by_assignment_group' => ['bar', 'table', 'insight'],
        'open_tickets_priority_category_matrix' => ['matrix'],
        'active_sla_exceptions' => ['kpi', 'detail_table'],
        'operational_attention' => ['attention'],
        'created_tickets_by_request_source' => ['bar', 'donut', 'table', 'insight'],
        'ticket_reopen_events' => ['kpi', 'line'],
        'ticket_resolution_events' => ['kpi', 'line'],
        'first_response_p50_seconds' => ['kpi', 'line'],
        'first_response_p75_seconds' => ['kpi', 'line'],
        'first_response_p90_seconds' => ['kpi', 'line'],
        'survey_responses_total' => ['kpi', 'line'],
        'dissatisfied_responses_total' => ['kpi', 'line'],
        'customer_dissatisfaction_rate' => ['kpi', 'line'],
        'solution_proposed_tickets' => ['kpi', 'line'],
        'solution_refused_tickets' => ['kpi', 'line'],
        'refused_solution_rate' => ['kpi', 'line'],
        'incident_linked_computers' => ['kpi', 'line'],
        'repeat_incident_computers_90d' => ['kpi', 'line'],
        'repeat_incident_asset_rate' => ['kpi', 'line'],
        'licence_covered_titles' => ['kpi', 'line'],
        'licence_installed_titles' => ['kpi', 'line'],
        'licence_utilization_rate' => ['kpi', 'line'],
        'licence_coverage_gap_rate' => ['kpi', 'line'],
    ];

    public function __construct(private readonly EntityScope $entityScope = new EntityScope())
    {
    }

    /** @return array<string, mixed> */
    public function workspace(): array
    {
        $this->provisionPremiumCommand();
        $this->provisionPremiumSectionOrder();
        $this->provisionAlignedMetrics();
        $this->provisionPhase4Executive();
        $this->provisionPhase4Dashboards();
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

    private function provisionPremiumCommand(): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_dashboard_provisions')) return;
        $marker = $this->ownershipWhere() + ['release_key' => self::PREMIUM_COMMAND_PROVISION];
        if ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_marifex_dashboard_provisions', 'WHERE' => $marker, 'LIMIT' => 1])->current()) return;
        $row = $DB->request(['SELECT' => ['id', 'definition'], 'FROM' => 'glpi_plugin_marifex_dashboard_definitions', 'WHERE' => $this->ownershipWhere() + ['name' => 'Executive Operations Command'], 'LIMIT' => 1])->current();
        if ($row) {
            $current = $this->validate(json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR));
            $palettes = [];
            foreach ($current['widgets'] as $widget) $palettes[$widget['id']] = $widget['palette'];
            $premium = $this->templates()['executive']['definition'];
            foreach ($premium['widgets'] as &$widget) if (isset($palettes[$widget['id']])) $widget['palette'] = $palettes[$widget['id']];
            unset($widget);
            $DB->update('glpi_plugin_marifex_dashboard_definitions', ['definition' => json_encode($this->validate($premium), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], $this->ownershipWhere() + ['id' => (int) $row['id']]);
        }
        $DB->insert('glpi_plugin_marifex_dashboard_provisions', $marker + ['date_creation' => gmdate('Y-m-d H:i:s')]);
    }

    private function provisionPremiumSectionOrder(): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_dashboard_provisions')) return;
        $marker = $this->ownershipWhere() + ['release_key' => self::PREMIUM_SECTION_ORDER_PROVISION];
        if ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_marifex_dashboard_provisions', 'WHERE' => $marker, 'LIMIT' => 1])->current()) return;

        $row = $DB->request([
            'SELECT' => ['id', 'definition'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => $this->ownershipWhere() + ['name' => 'Executive Operations Command'],
            'LIMIT' => 1,
        ])->current();
        if ($row) {
            $current = $this->validate(json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR));
            $hasExplicitCanvasPosition = array_filter(
                $current['widgets'],
                static fn(array $widget): bool => isset($widget['x'], $widget['y']),
            ) !== [];
            if (!$hasExplicitCanvasPosition) {
                $currentById = [];
                foreach ($current['widgets'] as $widget) $currentById[$widget['id']] = $widget;
                $ordered = [];
                foreach ($this->premiumExecutiveWidgets() as $premiumWidget) {
                    $id = $premiumWidget['id'];
                    if (!isset($currentById[$id])) continue;
                    $ordered[] = $currentById[$id];
                    unset($currentById[$id]);
                }
                foreach ($current['widgets'] as $widget) {
                    if (isset($currentById[$widget['id']])) $ordered[] = $widget;
                }
                $current['widgets'] = $ordered;
                $DB->update('glpi_plugin_marifex_dashboard_definitions', [
                    'definition' => json_encode($this->validate($current), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ], $this->ownershipWhere() + ['id' => (int) $row['id']]);
            }
        }
        $DB->insert('glpi_plugin_marifex_dashboard_provisions', $marker + ['date_creation' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> */
    public function reportDashboard(int $id): array
    {
        return $this->dashboardFromRow($this->ownedRow($id));
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

    private function provisionPhase4Dashboards(): void
    {
        global $DB;
        $where = $this->ownershipWhere() + ['release_key' => self::PHASE4_PROVISION];
        if (!$DB->tableExists('glpi_plugin_marifex_dashboard_provisions') || $DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_marifex_dashboard_provisions',
            'WHERE' => $where,
            'LIMIT' => 1,
        ])->current()) {
            return;
        }

        $rows = $this->rows();
        $names = array_fill_keys(array_map(static fn(array $row): string => (string) $row['name'], $rows), true);
        $templates = $this->templates();
        $missing = array_values(array_filter(
            self::PHASE4_TEMPLATES,
            static fn(string $key): bool => !isset($names[(string) $templates[$key]['name']])
        ));
        if (count($rows) + count($missing) > self::MAX_DASHBOARDS) {
            return;
        }

        $DB->beginTransaction();
        try {
            foreach ($missing as $key) {
                $template = $templates[$key];
                $values = $this->values((string) $template['name'], $this->validate($template['definition']));
                $values['is_active'] = 0;
                $values['date_creation'] = gmdate('Y-m-d H:i:s');
                $DB->insert('glpi_plugin_marifex_dashboard_definitions', $values);
            }
            $DB->insert('glpi_plugin_marifex_dashboard_provisions', $where + [
                'date_creation' => gmdate('Y-m-d H:i:s'),
            ]);
            $DB->commit();
        } catch (\Throwable $error) {
            $DB->rollBack();
            throw $error;
        }
    }

    private function provisionAlignedMetrics(): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_dashboard_provisions')) {
            return;
        }
        $marker = $this->ownershipWhere() + ['release_key' => self::ALIGNED_METRICS_PROVISION];
        if ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_marifex_dashboard_provisions',
            'WHERE' => $marker,
            'LIMIT' => 1,
        ])->current()) {
            return;
        }

        $row = $DB->request([
            'SELECT' => ['id', 'definition'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => $this->ownershipWhere() + ['name' => 'Executive Operations Command'],
            'LIMIT' => 1,
        ])->current();
        if ($row) {
            $current = $this->validate(json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR));
            $currentById = [];
            foreach ($current['widgets'] as $widget) {
                $currentById[$widget['id']] = $widget;
            }
            $aligned = [];
            $templateIds = [];
            foreach ($this->templates()['executive']['definition']['widgets'] as $widget) {
                $templateIds[$widget['id']] = true;
                if (isset($currentById[$widget['id']])) {
                    $widget['title'] = $currentById[$widget['id']]['title'];
                    $widget['palette'] = $currentById[$widget['id']]['palette'];
                }
                $aligned[] = $widget;
            }
            foreach ($current['widgets'] as $widget) {
                if (!isset($templateIds[$widget['id']]) && count($aligned) < self::MAX_WIDGETS) {
                    $aligned[] = $widget;
                }
            }
            $current['widgets'] = $aligned;
            $DB->update('glpi_plugin_marifex_dashboard_definitions', [
                'definition' => json_encode($this->validate($current), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ], $this->ownershipWhere() + ['id' => (int) $row['id']]);
        }
        $DB->insert('glpi_plugin_marifex_dashboard_provisions', $marker + ['date_creation' => gmdate('Y-m-d H:i:s')]);
    }

    private function provisionPhase4Executive(): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_dashboard_provisions')) {
            return;
        }
        $marker = $this->ownershipWhere() + ['release_key' => self::PHASE4_EXECUTIVE_PROVISION];
        if ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_plugin_marifex_dashboard_provisions',
            'WHERE' => $marker,
            'LIMIT' => 1,
        ])->current()) {
            return;
        }

        $row = $DB->request([
            'SELECT' => ['id', 'definition'],
            'FROM' => 'glpi_plugin_marifex_dashboard_definitions',
            'WHERE' => $this->ownershipWhere() + ['name' => 'Executive Operations Command'],
            'LIMIT' => 1,
        ])->current();
        if ($row) {
            $definition = $this->validate(json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR));
            $ids = array_fill_keys(array_column($definition['widgets'], 'id'), true);
            foreach ($this->templates()['executive']['definition']['widgets'] as $widget) {
                if (count($definition['widgets']) >= self::MAX_WIDGETS) {
                    break;
                }
                if (!isset($ids[$widget['id']])) {
                    $definition['widgets'][] = $widget;
                }
            }
            $definition = $this->validate($definition);
            $DB->update('glpi_plugin_marifex_dashboard_definitions', [
                'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ], $this->ownershipWhere() + ['id' => (int) $row['id']]);
        }
        $DB->insert('glpi_plugin_marifex_dashboard_provisions', $marker + [
            'date_creation' => gmdate('Y-m-d H:i:s'),
        ]);
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
        if (!is_array($widgets) || count($widgets) < 1 || count($widgets) > self::MAX_WIDGETS) {
            throw new InvalidArgumentException(sprintf('A dashboard must contain between 1 and %d widgets.', self::MAX_WIDGETS));
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
            $palette = (string) ($widget['palette'] ?? 'cream_gold');
            if (!preg_match('/^[a-z0-9-]{6,64}$/', $id) || isset($ids[$id])) {
                throw new InvalidArgumentException('Widget IDs must be unique and URL-safe.');
            }
            if (!isset(self::METRICS[$metric]) || !in_array($type, self::METRICS[$metric], true)) {
                throw new InvalidArgumentException('Widget type is not compatible with its certified metric.');
            }
            if ($title === '' || mb_strlen($title) > 100) {
                throw new InvalidArgumentException('Widget title must contain 1 to 100 characters.');
            }
            if (!in_array($palette, self::WIDGET_PALETTES, true)) {
                throw new InvalidArgumentException('Unsupported widget color palette.');
            }
            $requiredColorSlots = PaletteRegistry::requiredSlots($type, $metric);
            $chartPalette = (string) ($widget['chartPalette'] ?? (PaletteRegistry::SURFACE_TO_CHART[$palette] ?? ''));
            if (!(new PaletteService())->canAssign($chartPalette, $requiredColorSlots)) {
                throw new InvalidArgumentException('The chart palette is unavailable or has insufficient rendered color slots.');
            }
            [$minW, $maxW, $allowedHeights] = match ($type) {
                'kpi' => [2, 4, [2, 3]],
                'insight' => [3, 5, [2, 3]],
                'line', 'bar', 'donut' => [4, 8, [6, 7]],
                'table' => [5, 8, [6, 7]],
                'detail_table', 'matrix' => [6, 12, [7, 8]],
                'attention' => [6, 8, [6, 7]],
                default => [3, 12, [3]],
            };
            $height = (int) ($widget['h'] ?? $allowedHeights[0]);
            usort($allowedHeights, static fn(int $a, int $b): int => abs($height - $a) <=> abs($height - $b));
            $validatedWidget = [
                'id' => $id,
                'metric' => $metric,
                'type' => $type,
                'title' => $title,
                'palette' => $palette,
                'chartPalette' => $chartPalette,
                'requiredColorSlots' => $requiredColorSlots,
                'w' => max($minW, min($maxW, (int) ($widget['w'] ?? $minW))),
                'h' => $allowedHeights[0],
            ];
            if (isset($widget['x'], $widget['y']) && is_numeric($widget['x']) && is_numeric($widget['y'])) {
                $validatedWidget['x'] = max(0, min(12 - $validatedWidget['w'], (int) $widget['x']));
                $validatedWidget['y'] = max(0, min(999, (int) $widget['y']));
            }
            $validated[] = $validatedWidget;
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
        return ['id' => null, 'name' => $template['name'], 'definition' => $this->validate($template['definition']), 'date_mod' => null];
    }

    /** @return array<string, array<string, mixed>> */
    private function templates(): array
    {
        $base = ['version' => 2, 'dateRangeDays' => 30, 'refreshMinutes' => 0, 'filters' => ['groupId' => null]];
        $templates = [
            'executive' => [
                'key' => 'executive',
                'name' => 'Executive Operations Command',
                'description' => 'Aligned enterprise command view across certified service desk, asset, licence, change and problem metrics.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'open-now', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Open now', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-unassigned', 'metric' => 'unassigned_open_tickets', 'type' => 'kpi', 'title' => 'Unassigned open tickets', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-sla-approaching', 'metric' => 'tickets_approaching_sla_breach', 'type' => 'kpi', 'title' => 'Approaching SLA breach', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-sla-breaches', 'metric' => 'sla_breach_count', 'type' => 'kpi', 'title' => 'Open SLA breaches', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-sla-rate', 'metric' => 'sla_breach_rate', 'type' => 'kpi', 'title' => 'SLA breach rate', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-unassigned-age', 'metric' => 'average_unassigned_time', 'type' => 'kpi', 'title' => 'Average unassigned age', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-assignment-changes', 'metric' => 'assignment_changes_per_ticket', 'type' => 'kpi', 'title' => 'Assignment changes per ticket', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-unsatisfied', 'metric' => 'unsatisfied_survey_responses', 'type' => 'kpi', 'title' => 'Unsatisfied responses', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-asset-total', 'metric' => 'asset_inventory_total', 'type' => 'kpi', 'title' => 'Managed computers', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-asset-stale', 'metric' => 'stale_computer_inventory', 'type' => 'kpi', 'title' => 'Stale computer inventory', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-low-disk', 'metric' => 'low_disk_capacity_computers', 'type' => 'kpi', 'title' => 'Low disk capacity', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-stock-age', 'metric' => 'computers_in_stock_over_30_days', 'type' => 'kpi', 'title' => 'In stock over 30 days', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-licence-entitlements', 'metric' => 'software_license_entitlements', 'type' => 'kpi', 'title' => 'Licence entitlements', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-licence-allocations', 'metric' => 'software_license_allocations', 'type' => 'kpi', 'title' => 'Allocated licence seats', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-licence-compliance', 'metric' => 'software_license_compliance_rate', 'type' => 'kpi', 'title' => 'Licence compliance', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-licence-risk', 'metric' => 'software_license_overallocated_seats', 'type' => 'kpi', 'title' => 'Overallocated seats', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-change-open', 'metric' => 'open_changes', 'type' => 'kpi', 'title' => 'Open changes', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-change-volume', 'metric' => 'daily_change_volume', 'type' => 'kpi', 'title' => 'Changes raised', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-problem-open', 'metric' => 'open_problems', 'type' => 'kpi', 'title' => 'Open problems', 'w' => 3, 'h' => 2],
                    ['id' => 'executive-problem-volume', 'metric' => 'daily_problem_volume', 'type' => 'kpi', 'title' => 'Problems raised', 'w' => 3, 'h' => 2],
                    ['id' => 'average-age', 'metric' => 'average_open_ticket_age', 'type' => 'line', 'title' => 'Ticket age trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'backlog-trend', 'metric' => 'historical_open_backlog', 'type' => 'line', 'title' => 'Enterprise backlog trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'group-share', 'metric' => 'historical_group_backlog', 'type' => 'donut', 'title' => 'Workload concentration', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-priority', 'metric' => 'open_tickets_by_priority', 'type' => 'donut', 'title' => 'Open tickets by priority', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-sla-technician', 'metric' => 'sla_breaches_by_technician', 'type' => 'bar', 'title' => 'SLA breaches by technician', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-request-source', 'metric' => 'tickets_by_request_source', 'type' => 'donut', 'title' => 'Tickets by request source', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-ticket-flow', 'metric' => 'created_vs_resolved_tickets', 'type' => 'line', 'title' => 'Created versus resolved tickets', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-technician-workload', 'metric' => 'technician_workload_distribution', 'type' => 'bar', 'title' => 'Technician workload distribution', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-resolution-bands', 'metric' => 'resolution_time_age_bands', 'type' => 'bar', 'title' => 'Resolution-time age bands', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-asset-states', 'metric' => 'asset_inventory_by_state', 'type' => 'donut', 'title' => 'Asset lifecycle distribution', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-prohibited-software', 'metric' => 'prohibited_software_installations', 'type' => 'table', 'title' => 'Software marked invalid', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-unlicensed-software', 'metric' => 'unlicensed_software_installations', 'type' => 'table', 'title' => 'Installations above entitlement', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-incidents-os', 'metric' => 'incidents_by_operating_system', 'type' => 'bar', 'title' => 'Incidents by operating system', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-repeat-assets', 'metric' => 'repeat_incident_computers', 'type' => 'table', 'title' => 'Computers with repeated incidents', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-change-resolved', 'metric' => 'daily_change_resolutions', 'type' => 'line', 'title' => 'Change resolution trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-problem-resolved', 'metric' => 'daily_problem_resolutions', 'type' => 'line', 'title' => 'Problem resolution trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-change-status', 'metric' => 'open_change_status_distribution', 'type' => 'donut', 'title' => 'Open changes by status', 'w' => 6, 'h' => 4],
                    ['id' => 'executive-problem-status', 'metric' => 'open_problem_status_distribution', 'type' => 'donut', 'title' => 'Open problems by status', 'w' => 6, 'h' => 4],
                    ['id' => 'group-ranking', 'metric' => 'historical_group_backlog', 'type' => 'table', 'title' => 'Service ownership ranking', 'w' => 12, 'h' => 4],
                ]],
            ],
            'service-desk' => [
                'key' => 'service-desk',
                'name' => 'Service Desk Operations',
                'description' => 'Backlog, ticket age and assignment-group workload for daily operations.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'desk-open', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Open service desk tickets', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-unassigned', 'metric' => 'unassigned_open_tickets', 'type' => 'kpi', 'title' => 'Unassigned open tickets', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-sla-approaching', 'metric' => 'tickets_approaching_sla_breach', 'type' => 'kpi', 'title' => 'Approaching SLA breach', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-sla-rate', 'metric' => 'sla_breach_rate', 'type' => 'kpi', 'title' => 'SLA breach rate', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-unassigned-age', 'metric' => 'average_unassigned_time', 'type' => 'kpi', 'title' => 'Average unassigned age', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-assignment-changes', 'metric' => 'assignment_changes_per_ticket', 'type' => 'kpi', 'title' => 'Assignment changes per ticket', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-unsatisfied', 'metric' => 'unsatisfied_survey_responses', 'type' => 'kpi', 'title' => 'Unsatisfied responses', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-sla-breaches', 'metric' => 'sla_breach_count', 'type' => 'kpi', 'title' => 'Open SLA breaches', 'w' => 3, 'h' => 2],
                    ['id' => 'desk-age', 'metric' => 'average_open_ticket_age', 'type' => 'line', 'title' => 'Ticket age trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-backlog', 'metric' => 'historical_open_backlog', 'type' => 'line', 'title' => 'Backlog trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-priority', 'metric' => 'open_tickets_by_priority', 'type' => 'donut', 'title' => 'Open tickets by priority', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-source', 'metric' => 'tickets_by_request_source', 'type' => 'donut', 'title' => 'Tickets by request source', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-flow', 'metric' => 'created_vs_resolved_tickets', 'type' => 'line', 'title' => 'Created versus resolved tickets', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-workload', 'metric' => 'technician_workload_distribution', 'type' => 'bar', 'title' => 'Technician workload distribution', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-sla-technician', 'metric' => 'sla_breaches_by_technician', 'type' => 'bar', 'title' => 'SLA breaches by technician', 'w' => 6, 'h' => 4],
                    ['id' => 'desk-resolution-bands', 'metric' => 'resolution_time_age_bands', 'type' => 'bar', 'title' => 'Resolution-time age bands', 'w' => 6, 'h' => 4],
                ]],
            ],
            'team-workload' => [
                'key' => 'team-workload',
                'name' => 'Team Workload',
                'description' => 'Focused workload distribution and service ownership ranking.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'team-open', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Focused open tickets', 'w' => 3, 'h' => 2],
                    ['id' => 'team-share', 'metric' => 'historical_group_backlog', 'type' => 'donut', 'title' => 'Workload concentration', 'w' => 6, 'h' => 4],
                    ['id' => 'team-bars', 'metric' => 'historical_group_backlog', 'type' => 'bar', 'title' => 'Group comparison', 'w' => 6, 'h' => 4],
                    ['id' => 'team-table', 'metric' => 'historical_group_backlog', 'type' => 'table', 'title' => 'Service ownership ranking', 'w' => 12, 'h' => 4],
                ]],
            ],
            'asset-governance' => [
                'key' => 'asset-governance',
                'name' => 'Asset and Licence Governance',
                'description' => 'Computer lifecycle, inventory freshness and governed software licence allocation.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'asset-total', 'metric' => 'asset_inventory_total', 'type' => 'kpi', 'title' => 'Managed computers', 'w' => 3, 'h' => 2],
                    ['id' => 'asset-stale', 'metric' => 'stale_computer_inventory', 'type' => 'kpi', 'title' => 'Inventory stale over 30 days', 'w' => 3, 'h' => 2],
                    ['id' => 'licence-compliance', 'metric' => 'software_license_compliance_rate', 'type' => 'kpi', 'title' => 'Licence compliance', 'w' => 3, 'h' => 2],
                    ['id' => 'licence-overallocated', 'metric' => 'software_license_overallocated_seats', 'type' => 'kpi', 'title' => 'Overallocated seats', 'w' => 3, 'h' => 2],
                    ['id' => 'asset-states', 'metric' => 'asset_inventory_by_state', 'type' => 'donut', 'title' => 'Computer lifecycle distribution', 'w' => 6, 'h' => 4],
                    ['id' => 'asset-state-table', 'metric' => 'asset_inventory_by_state', 'type' => 'table', 'title' => 'Lifecycle state inventory', 'w' => 6, 'h' => 4],
                    ['id' => 'licence-entitlements', 'metric' => 'software_license_entitlements', 'type' => 'line', 'title' => 'Licence entitlement trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'licence-allocations', 'metric' => 'software_license_allocations', 'type' => 'line', 'title' => 'Allocated licence trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'asset-low-disk', 'metric' => 'low_disk_capacity_computers', 'type' => 'kpi', 'title' => 'Low disk capacity', 'w' => 3, 'h' => 2],
                    ['id' => 'asset-stock-age', 'metric' => 'computers_in_stock_over_30_days', 'type' => 'kpi', 'title' => 'In stock over 30 days', 'w' => 3, 'h' => 2],
                    ['id' => 'asset-prohibited-software', 'metric' => 'prohibited_software_installations', 'type' => 'table', 'title' => 'Software marked invalid', 'w' => 6, 'h' => 4],
                    ['id' => 'asset-unlicensed-software', 'metric' => 'unlicensed_software_installations', 'type' => 'table', 'title' => 'Installations above entitlement', 'w' => 6, 'h' => 4],
                    ['id' => 'asset-incidents-os', 'metric' => 'incidents_by_operating_system', 'type' => 'bar', 'title' => 'Incidents by operating system', 'w' => 6, 'h' => 4],
                    ['id' => 'asset-repeat-incidents', 'metric' => 'repeat_incident_computers', 'type' => 'table', 'title' => 'Computers with repeated incidents', 'w' => 6, 'h' => 4],
                ]],
            ],
            'change-control' => [
                'key' => 'change-control',
                'name' => 'Change Control',
                'description' => 'Open change exposure, daily demand, resolutions and active status distribution.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'change-open', 'metric' => 'open_changes', 'type' => 'kpi', 'title' => 'Open changes', 'w' => 3, 'h' => 2],
                    ['id' => 'change-new', 'metric' => 'daily_change_volume', 'type' => 'kpi', 'title' => 'Changes raised', 'w' => 3, 'h' => 2],
                    ['id' => 'change-resolved', 'metric' => 'daily_change_resolutions', 'type' => 'kpi', 'title' => 'Changes resolved', 'w' => 3, 'h' => 2],
                    ['id' => 'change-trend', 'metric' => 'daily_change_volume', 'type' => 'line', 'title' => 'Change demand trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'change-status', 'metric' => 'open_change_status_distribution', 'type' => 'donut', 'title' => 'Open changes by status', 'w' => 6, 'h' => 4],
                    ['id' => 'change-resolution-trend', 'metric' => 'daily_change_resolutions', 'type' => 'line', 'title' => 'Change resolution trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'change-status-table', 'metric' => 'open_change_status_distribution', 'type' => 'table', 'title' => 'Active change queue', 'w' => 6, 'h' => 4],
                ]],
            ],
            'problem-control' => [
                'key' => 'problem-control',
                'name' => 'Problem Control',
                'description' => 'Open problem exposure, new demand, resolutions and active status distribution.',
                'definition' => $base + ['widgets' => [
                    ['id' => 'problem-open', 'metric' => 'open_problems', 'type' => 'kpi', 'title' => 'Open problems', 'w' => 3, 'h' => 2],
                    ['id' => 'problem-new', 'metric' => 'daily_problem_volume', 'type' => 'kpi', 'title' => 'Problems raised', 'w' => 3, 'h' => 2],
                    ['id' => 'problem-resolved', 'metric' => 'daily_problem_resolutions', 'type' => 'kpi', 'title' => 'Problems resolved', 'w' => 3, 'h' => 2],
                    ['id' => 'problem-trend', 'metric' => 'daily_problem_volume', 'type' => 'line', 'title' => 'Problem demand trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'problem-status', 'metric' => 'open_problem_status_distribution', 'type' => 'donut', 'title' => 'Open problems by status', 'w' => 6, 'h' => 4],
                    ['id' => 'problem-resolution-trend', 'metric' => 'daily_problem_resolutions', 'type' => 'line', 'title' => 'Problem resolution trajectory', 'w' => 6, 'h' => 4],
                    ['id' => 'problem-status-table', 'metric' => 'open_problem_status_distribution', 'type' => 'table', 'title' => 'Active problem queue', 'w' => 6, 'h' => 4],
                ]],
            ],
        ];
        $templates['executive']['definition'] = $base + ['widgets' => $this->premiumExecutiveWidgets()];
        return $templates;
    }

    /** @return list<array<string, mixed>> */
    private function premiumExecutiveWidgets(): array
    {
        return [
            ['id' => 'open-now', 'metric' => 'current_open_tickets', 'type' => 'kpi', 'title' => 'Current open tickets', 'w' => 2, 'h' => 2],
            ['id' => 'executive-unassigned', 'metric' => 'unassigned_open_tickets', 'type' => 'kpi', 'title' => 'Unassigned', 'w' => 2, 'h' => 2],
            ['id' => 'executive-sla-breaches', 'metric' => 'sla_breach_count', 'type' => 'kpi', 'title' => 'Open SLA breaches', 'w' => 2, 'h' => 2],
            ['id' => 'executive-sla-approaching', 'metric' => 'tickets_approaching_sla_breach', 'type' => 'kpi', 'title' => 'Approaching SLA', 'w' => 2, 'h' => 2],
            ['id' => 'average-age', 'metric' => 'average_open_ticket_age', 'type' => 'kpi', 'title' => 'Average open age', 'w' => 2, 'h' => 2],
            ['id' => 'executive-low-disk', 'metric' => 'low_disk_capacity_computers', 'type' => 'kpi', 'title' => 'Low-disk computers', 'w' => 2, 'h' => 2],
            ['id' => 'executive-ticket-flow', 'metric' => 'created_vs_resolved_tickets', 'type' => 'line', 'title' => 'Created versus resolved', 'w' => 7, 'h' => 6],
            ['id' => 'executive-technician-workload', 'metric' => 'technician_workload_distribution', 'type' => 'bar', 'title' => 'Technician workload', 'w' => 5, 'h' => 6],
            ['id' => 'executive-attention', 'metric' => 'operational_attention', 'type' => 'attention', 'title' => 'Operational attention', 'w' => 7, 'h' => 6],
            ['id' => 'executive-priority', 'metric' => 'open_tickets_by_priority', 'type' => 'donut', 'title' => 'Open tickets by priority', 'w' => 5, 'h' => 6],
            ['id' => 'executive-sla-list', 'metric' => 'active_sla_exceptions', 'type' => 'detail_table', 'title' => 'Active SLA exceptions', 'w' => 8, 'h' => 7],
            ['id' => 'executive-sla-insight', 'metric' => 'sla_breaches_by_technician', 'type' => 'insight', 'title' => 'Top SLA pressure', 'w' => 4, 'h' => 3],
            ['id' => 'executive-resolution-bands', 'metric' => 'resolution_time_age_bands', 'type' => 'bar', 'title' => 'Resolution-time age bands', 'w' => 6, 'h' => 6],
            ['id' => 'executive-priority-category', 'metric' => 'open_tickets_priority_category_matrix', 'type' => 'matrix', 'title' => 'Priority by ITIL category', 'w' => 6, 'h' => 7],
            ['id' => 'executive-group-incidents', 'metric' => 'open_incidents_by_assignment_group', 'type' => 'bar', 'title' => 'Open incidents by assignment group', 'w' => 7, 'h' => 6],
            ['id' => 'executive-group-insight', 'metric' => 'historical_group_backlog', 'type' => 'insight', 'title' => 'Largest backlog group', 'w' => 5, 'h' => 3],
            ['id' => 'executive-assignment-changes', 'metric' => 'assignment_changes_per_ticket', 'type' => 'kpi', 'title' => 'Assignment changes per ticket', 'w' => 3, 'h' => 2],
            ['id' => 'executive-unsatisfied', 'metric' => 'unsatisfied_survey_responses', 'type' => 'kpi', 'title' => 'Unsatisfied responses', 'w' => 3, 'h' => 2],
            ['id' => 'executive-refused', 'metric' => 'latest_solution_refused_tickets', 'type' => 'kpi', 'title' => 'Latest solutions refused', 'w' => 3, 'h' => 2],
            ['id' => 'executive-request-source', 'metric' => 'tickets_by_request_source', 'type' => 'insight', 'title' => 'Leading request source', 'w' => 3, 'h' => 2],
            ['id' => 'executive-asset-stale', 'metric' => 'stale_computer_inventory', 'type' => 'kpi', 'title' => 'Stale computer inventory', 'w' => 3, 'h' => 2],
            ['id' => 'executive-stock-age', 'metric' => 'computers_in_stock_over_30_days', 'type' => 'kpi', 'title' => 'In stock over 30 days', 'w' => 3, 'h' => 2],
            ['id' => 'executive-incidents-os', 'metric' => 'incidents_by_operating_system', 'type' => 'bar', 'title' => 'Incidents by operating system', 'w' => 6, 'h' => 6],
            ['id' => 'executive-repeat-assets', 'metric' => 'repeat_incident_computers', 'type' => 'table', 'title' => 'Computers with repeated incidents', 'w' => 6, 'h' => 6],
            ['id' => 'executive-prohibited-software', 'metric' => 'prohibited_software_installations', 'type' => 'table', 'title' => 'Software marked invalid', 'w' => 6, 'h' => 6],
            ['id' => 'executive-unlicensed-software', 'metric' => 'unlicensed_software_installations', 'type' => 'table', 'title' => 'Installations above entitlement', 'w' => 6, 'h' => 6],
            ['id' => 'executive-change-open', 'metric' => 'open_changes', 'type' => 'kpi', 'title' => 'Open changes', 'w' => 3, 'h' => 2],
            ['id' => 'executive-problem-open', 'metric' => 'open_problems', 'type' => 'kpi', 'title' => 'Open problems', 'w' => 3, 'h' => 2],
            ['id' => 'executive-change-status', 'metric' => 'open_change_status_distribution', 'type' => 'donut', 'title' => 'Open changes by status', 'w' => 6, 'h' => 6],
            ['id' => 'executive-problem-status', 'metric' => 'open_problem_status_distribution', 'type' => 'donut', 'title' => 'Open problems by status', 'w' => 6, 'h' => 6],
        ];
    }
}
