<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Palette;

use GlpiPlugin\Marifex\Insight\AnalyticalAuditService;
use GlpiPlugin\Marifex\Security\EntityScope;
use InvalidArgumentException;
use Session;

final class PaletteService
{
    private PaletteValidator $validator;
    private EntityScope $scope;

    public function __construct()
    {
        $this->validator = new PaletteValidator();
        $this->scope = new EntityScope();
    }

    /** @return array<string, mixed> */
    public function catalogue(): array
    {
        $active = $this->scope->activeEntityId();
        $palettes = array_values(PaletteRegistry::builtIns());
        foreach ($this->rowsVisibleFrom($active) as $row) $palettes[] = $this->present($row, $active);
        return ['schemaVersion' => 1, 'default' => $this->defaultKey($palettes, $active), 'palettes' => $palettes, 'limit' => 20];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        global $DB;
        $entity = $this->scope->activeEntityId();
        if ($DB->request(['FROM' => 'glpi_plugin_marifex_chart_palettes', 'WHERE' => ['entities_id' => $entity]])->count() >= 20) throw new InvalidArgumentException('The entity already has 20 custom palettes.');
        $definition = $this->validator->validate($input);
        if ($DB->request(['FROM' => 'glpi_plugin_marifex_chart_palettes', 'WHERE' => ['entities_id' => $entity, 'name' => $definition['name']]])->count() > 0) throw new InvalidArgumentException('A palette with this name already exists in the entity.');
        $DB->insert('glpi_plugin_marifex_chart_palettes', [
            'name' => $definition['name'], 'entities_id' => $entity, 'is_recursive' => $definition['isRecursive'] ? 1 : 0,
            'is_default' => 0, 'palette_type' => $definition['type'], 'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'revision' => 1, 'users_id' => (int) Session::getLoginUserID(),
        ]);
        $id = (int) $DB->insertId();
        (new AnalyticalAuditService())->record('chart_palette_created', [], ['id' => $id, 'revision' => 1, 'definition' => $definition]);
        return $this->catalogue();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(int $id, array $input, bool $confirmed): array
    {
        global $DB;
        $row = $this->owned($id); $definition = $this->validator->validate($input); $impact = $this->impact('custom:' . $id);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_marifex_chart_palettes', 'WHERE' => ['entities_id' => $this->scope->activeEntityId(), 'name' => $definition['name']]]) as $same) if ((int) $same['id'] !== $id) throw new InvalidArgumentException('A palette with this name already exists in the entity.');
        if (($impact['widgets'] > 0 || $impact['childEntities'] > 0) && !$confirmed) return ['confirmationRequired' => true, 'impact' => $impact];
        $revision = (int) $row['revision'] + 1;
        $DB->update('glpi_plugin_marifex_chart_palettes', ['name' => $definition['name'], 'is_recursive' => $definition['isRecursive'] ? 1 : 0, 'palette_type' => $definition['type'], 'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'revision' => $revision], ['id' => $id, 'entities_id' => $this->scope->activeEntityId()]);
        (new AnalyticalAuditService())->record('chart_palette_updated', ['id' => $id, 'revision' => (int) $row['revision'], 'definition' => json_decode((string) $row['definition'], true)], ['id' => $id, 'revision' => $revision, 'definition' => $definition, 'impact' => $impact]);
        return $this->catalogue();
    }

    /** @return array<string, mixed> */
    public function delete(int $id, string $replacement, bool $confirmed): array
    {
        global $DB;
        $row = $this->owned($id); $source = 'custom:' . $id;
        if ($source === $replacement || !$this->keyExists($replacement)) throw new InvalidArgumentException('A valid replacement palette is required.');
        $impact = $this->impact($source);
        if (!$confirmed) return ['confirmationRequired' => true, 'impact' => $impact];
        $DB->beginTransaction();
        try {
            foreach ($DB->request(['SELECT' => ['id','definition'], 'FROM' => 'glpi_plugin_marifex_dashboard_definitions']) as $dashboard) {
                $definition = json_decode((string) $dashboard['definition'], true, 64, JSON_THROW_ON_ERROR); $changed = false;
                foreach (($definition['widgets'] ?? []) as $index => $widget) if (($widget['chartPalette'] ?? '') === $source) { $definition['widgets'][$index]['chartPalette'] = $replacement; $changed = true; }
                if ($changed) $DB->update('glpi_plugin_marifex_dashboard_definitions', ['definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], ['id' => (int) $dashboard['id']]);
            }
            if ((int) $row['is_default'] === 1 && str_starts_with($replacement, 'custom:')) $DB->update('glpi_plugin_marifex_chart_palettes', ['is_default' => 1], ['id' => (int) substr($replacement, 7), 'entities_id' => $this->scope->activeEntityId()]);
            $entity = $this->scope->activeEntityId(); $config = \Config::getConfigurationValues('plugin:marifex');
            if ((string) ($config['chart_palette_default_' . $entity] ?? '') === $source) \Config::setConfigurationValues('plugin:marifex', ['chart_palette_default_' . $entity => $replacement]);
            $DB->delete('glpi_plugin_marifex_chart_palettes', ['id' => $id, 'entities_id' => $this->scope->activeEntityId()]);
            (new AnalyticalAuditService())->record('chart_palette_deleted', ['id' => $id, 'revision' => (int) $row['revision']], ['replacement' => $replacement, 'impact' => $impact]);
            $DB->commit();
        } catch (\Throwable $exception) { $DB->rollBack(); throw $exception; }
        return $this->catalogue();
    }

    public function setDefault(string $key): array
    {
        global $DB;
        if (!$this->keyExists($key)) throw new InvalidArgumentException('Unknown default palette.');
        $entity = $this->scope->activeEntityId();
        $DB->update('glpi_plugin_marifex_chart_palettes', ['is_default' => 0], ['entities_id' => $entity]);
        $configKey = 'chart_palette_default_' . $entity;
        \Config::setConfigurationValues('plugin:marifex', [$configKey => $key]);
        if (str_starts_with($key, 'custom:')) $DB->update('glpi_plugin_marifex_chart_palettes', ['is_default' => 1], ['id' => (int) substr($key, 7), 'entities_id' => $entity]);
        (new AnalyticalAuditService())->record('chart_palette_default_changed', [], ['key' => $key]);
        return $this->catalogue();
    }

    public function import(string $json): array { return $this->create($this->validator->assertImport($json)); }

    public function canAssign(string $key, int $requiredSlots): bool
    {
        $palette = PaletteRegistry::builtIns()[$key] ?? null;
        if ($palette === null) {
            foreach ($this->rowsVisibleFrom($this->scope->activeEntityId()) as $row) {
                if ('custom:' . $row['id'] === $key) $palette = $this->present($row, $this->scope->activeEntityId());
            }
        }
        if ($palette === null) return false;
        $available = $palette['type'] === 'monochrome' ? (int) ($palette['slotCount'] ?? 0) : count($palette['colors'] ?? []);
        return $available >= $requiredSlots;
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $key): ?array
    {
        $palette = PaletteRegistry::builtIns()[$key] ?? null;
        if ($palette !== null) return $palette;
        foreach ($this->rowsVisibleFrom($this->scope->activeEntityId()) as $row) if ('custom:' . $row['id'] === $key) return $this->present($row, $this->scope->activeEntityId());
        return null;
    }

    private function owned(int $id): array
    {
        global $DB; $row = $DB->request(['FROM' => 'glpi_plugin_marifex_chart_palettes', 'WHERE' => ['id' => $id, 'entities_id' => $this->scope->activeEntityId()], 'LIMIT' => 1])->current();
        if (!$row) throw new InvalidArgumentException('Inherited or unknown palettes cannot be changed.'); return $row;
    }

    private function keyExists(string $key): bool
    {
        if (isset(PaletteRegistry::builtIns()[$key])) return true;
        if (!preg_match('/^custom:(\d+)$/', $key, $match)) return false;
        foreach ($this->rowsVisibleFrom($this->scope->activeEntityId()) as $row) if ((int) $row['id'] === (int) $match[1]) return true;
        return false;
    }

    private function defaultKey(array $palettes, int $entity): string
    {
        $config = \Config::getConfigurationValues('plugin:marifex'); $available = array_column($palettes, 'id');
        foreach (array_merge([$entity], $this->ancestorEntities($entity)) as $candidateEntity) {
            $key = (string) ($config['chart_palette_default_' . $candidateEntity] ?? '');
            if ($key !== '' && in_array($key, $available, true)) return $key;
        }
        return 'chart_cream_gold';
    }

    /** @return list<int> */
    private function ancestorEntities(int $entity): array
    {
        global $DB; $result = []; $seen = [];
        while ($entity > 0 && !isset($seen[$entity])) {
            $seen[$entity] = true; $row = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $entity], 'LIMIT' => 1])->current();
            $entity = $row ? (int) $row['entities_id'] : 0;
            $result[] = $entity;
        }
        return $result;
    }

    private function rowsVisibleFrom(int $active): array
    {
        global $DB; $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_marifex_chart_palettes', 'ORDER' => ['name ASC']]) as $row) {
            $owner = (int) $row['entities_id'];
            if ($owner === $active || ((int) $row['is_recursive'] === 1 && in_array($active, array_map('intval', getSonsOf('glpi_entities', $owner)), true))) $rows[] = $row;
        }
        return $rows;
    }

    private function present(array $row, int $active): array
    {
        $definition = json_decode((string) $row['definition'], true, 32, JSON_THROW_ON_ERROR); $inherited = (int) $row['entities_id'] !== $active;
        return $definition + ['id' => 'custom:' . $row['id'], 'revision' => (int) $row['revision'], 'builtIn' => false, 'inherited' => $inherited, 'readOnly' => $inherited, 'entityId' => (int) $row['entities_id'], 'isDefault' => (bool) $row['is_default']];
    }

    private function impact(string $key): array
    {
        global $DB; $widgets = 0; $names = []; $entities = [];
        foreach ($DB->request(['SELECT' => ['name','entities_id','definition'], 'FROM' => 'glpi_plugin_marifex_dashboard_definitions']) as $row) {
            if (!$this->scope->canAccessEntity((int) $row['entities_id'])) continue;
            $definition = json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR); $count = 0;
            foreach (($definition['widgets'] ?? []) as $widget) if (($widget['chartPalette'] ?? '') === $key) $count++;
            if ($count) { $widgets += $count; $names[] = (string) $row['name']; $entities[(int) $row['entities_id']] = true; }
        }
        unset($entities[$this->scope->activeEntityId()]);
        return ['widgets' => $widgets, 'dashboards' => count($names), 'dashboardNames' => array_values(array_unique($names)), 'childEntities' => count($entities)];
    }
}
