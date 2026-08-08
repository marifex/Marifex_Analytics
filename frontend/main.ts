import { createApp } from 'vue';
import Dashboard from './Dashboard.vue';
import './dashboard.css';

const root = document.getElementById('marifex-dashboard');
if (root) {
  const pageCsrfToken = document.querySelector<HTMLMetaElement>('meta[property="glpi:csrf_token"]')?.content ?? '';
  createApp(Dashboard, {
    metricEndpoint: root.dataset.metricEndpoint ?? '/plugins/marifex/api/metrics',
    definitionEndpoint: root.dataset.definitionEndpoint ?? '/plugins/marifex/api/dashboard',
    csrfToken: root.dataset.csrfToken ?? pageCsrfToken,
    ticketSearchUrl: root.dataset.ticketSearchUrl ?? '/plugins/marifex/drilldown/tickets',
  }).mount(root);
}

