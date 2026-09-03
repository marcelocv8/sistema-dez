import { defineConfig, devices } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export default defineConfig({
  testDir: './tests/e2e', testMatch: 'controlled-publication.spec.ts', workers: 1,
  timeout: 180000, expect: { timeout: 20000 },
  outputDir: join(tmpdir(), 'dez-publication-e2e-results'), reporter: [['list']],
  use: { baseURL: 'http://localhost:4175', screenshot: 'off', trace: 'off', actionTimeout: 20000 },
  webServer: [
    { command: 'node node_modules/vite/bin/vite.js apps/equipe --host localhost --port 4175', port: 4175, reuseExistingServer: false, env: { VITE_USE_MSW: 'false', VITE_API_URL: 'http://localhost:18001' } },
    { command: 'node node_modules/vite/bin/vite.js apps/agendamento --host localhost --port 4176', port: 4176, reuseExistingServer: false, env: { VITE_USE_MSW: 'false', VITE_API_URL: 'http://localhost:18001' } },
  ],
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
