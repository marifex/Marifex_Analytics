/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */
import { createApp } from 'vue';
import Dashboard from './Dashboard.vue';
import './dashboard.css';

function mountDashboard(root: HTMLElement): void {
  if (root.dataset.marifexMounted === 'true') return;
  root.dataset.marifexMounted = 'true';
  const pageCsrfToken = document.querySelector<HTMLMetaElement>('meta[property="glpi:csrf_token"]')?.content ?? '';
  createApp(Dashboard, {
    metricEndpoint: root.dataset.metricEndpoint ?? '/plugins/marifex/api/metrics',
    insightEndpoint: root.dataset.insightEndpoint ?? '/plugins/marifex/api/insights',
    definitionEndpoint: root.dataset.definitionEndpoint ?? '/plugins/marifex/api/dashboard',
    paletteEndpoint: root.dataset.paletteEndpoint ?? '/plugins/marifex/api/palettes',
    csrfToken: pageCsrfToken || root.dataset.csrfToken || '',
    ticketSearchUrl: root.dataset.ticketSearchUrl ?? '/plugins/marifex/drilldown/tickets',
    assetSearchUrl: root.dataset.assetSearchUrl ?? '/front/computer.php',
    licenceSearchUrl: root.dataset.licenceSearchUrl ?? '/front/softwarelicense.php',
    changeSearchUrl: root.dataset.changeSearchUrl ?? '/front/change.php',
    problemSearchUrl: root.dataset.problemSearchUrl ?? '/front/problem.php',
    reportExportUrl: root.dataset.reportExportUrl ?? '/plugins/marifex/reports/export',
    reportScheduleEndpoint: root.dataset.reportScheduleEndpoint ?? '/plugins/marifex/api/reports/schedules',
    canExport: root.dataset.canExport === '1',
    canSchedule: root.dataset.canSchedule === '1',
  }).mount(root);
}

function mountAvailableDashboards(): void {
  document.querySelectorAll<HTMLElement>('[data-marifex-dashboard]').forEach(mountDashboard);
}

mountAvailableDashboards();
new MutationObserver(mountAvailableDashboards).observe(document.body, { childList: true, subtree: true });

