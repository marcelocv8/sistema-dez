import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const manualChunks = (id: string) => {
  if (!id.includes('node_modules')) return undefined;
  if (id.includes('@tanstack')) return 'query';
  if (id.includes('react-router') || id.includes('@remix-run')) return 'router';
  if (id.includes('react') || id.includes('scheduler')) return 'react';
  return 'vendor';
};

export default defineConfig({ base: '/painel/', plugins: [react()], build: { target: 'es2022', rollupOptions: { output: { manualChunks } } } });
