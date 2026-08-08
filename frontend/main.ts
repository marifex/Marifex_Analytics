import { createApp } from 'vue';
import Dashboard from './Dashboard.vue';
import './dashboard.css';

function mountDashboard(root: HTMLElement): void {
  if (root.dataset.marifexMounted === 'true') return;
  root.dataset.marifexMounted = 'true';
  const pageCsrfToken = document.querySelector<HTMLMetaElement>('meta[property="glpi:csrf_token"]')?.content ?? '';
  createApp(Dashboard, {
    metricEndpoint: root.dataset.metricEndpoint ?? '/plugins/marifex/api/metrics',
    definitionEndpoint: root.dataset.definitionEndpoint ?? '/plugins/marifex/api/dashboard',
    csrfToken: root.dataset.csrfToken ?? pageCsrfToken,
    ticketSearchUrl: root.dataset.ticketSearchUrl ?? '/plugins/marifex/drilldown/tickets',
  }).mount(root);
}

function mountAvailableDashboards(): void {
  document.querySelectorAll<HTMLElement>('[data-marifex-dashboard]').forEach(mountDashboard);
}

mountAvailableDashboards();
new MutationObserver(mountAvailableDashboards).observe(document.body, { childList: true, subtree: true });

