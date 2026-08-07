import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  publicDir: false,
  build: {
    emptyOutDir: false,
    lib: { entry: 'frontend/main.ts', formats: ['iife'], name: 'MarifexDashboard', fileName: () => 'js/dashboard.js' },
    outDir: 'public',
    cssCodeSplit: false,
    rollupOptions: { output: { assetFileNames: 'css/marifex.css' } },
  },
});
