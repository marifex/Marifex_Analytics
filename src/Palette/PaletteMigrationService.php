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
use RuntimeException;

final class PaletteMigrationService
{
    public function migrateDashboardDefinitions(): void
    {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_marifex_dashboard_definitions')) return;
        $changes = [];
        foreach ($DB->request(['SELECT' => ['id','name','entities_id','definition'], 'FROM' => 'glpi_plugin_marifex_dashboard_definitions']) as $row) {
            $definition = json_decode((string) $row['definition'], true, 64, JSON_THROW_ON_ERROR);
            foreach (($definition['widgets'] ?? []) as $index => $widget) {
                if (isset($widget['chartPalette'])) continue;
                $surface = (string) ($widget['palette'] ?? '');
                if (!isset(PaletteRegistry::SURFACE_TO_CHART[$surface])) {
                    throw new RuntimeException(sprintf('Phase 5C migration blocked: dashboard "%s", widget "%s" has unknown surface palette "%s".', $row['name'], $widget['id'] ?? $index, $surface));
                }
                $definition['widgets'][$index]['chartPalette'] = PaletteRegistry::SURFACE_TO_CHART[$surface];
                $definition['widgets'][$index]['requiredColorSlots'] = PaletteRegistry::requiredSlots((string) ($widget['type'] ?? ''), (string) ($widget['metric'] ?? ''));
            }
            $changes[] = ['row' => $row, 'definition' => $definition];
        }
        $DB->beginTransaction();
        try {
            foreach ($changes as $change) {
                $DB->update('glpi_plugin_marifex_dashboard_definitions', ['definition' => json_encode($change['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], ['id' => (int) $change['row']['id']]);
                (new AnalyticalAuditService())->record('dashboard_palette_migrated', ['definition' => json_decode((string) $change['row']['definition'], true)], ['definition' => $change['definition']], 0, (int) $change['row']['entities_id']);
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }
}
