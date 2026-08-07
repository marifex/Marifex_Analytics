import { createApp } from 'vue';
import Dashboard from './Dashboard.vue';
import '../public/css/marifex.css';

const root = document.getElementById('marifex-dashboard');
if (root) {
  createApp(Dashboard, { endpoint: root.dataset.metricEndpoint ?? '/plugins/marifex/api/metrics' }).mount(root);
}

