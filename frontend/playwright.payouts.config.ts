import { defineConfig, devices } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'payouts.spec.ts',
  workers: 1,
  timeout: 540000,
  expect: { timeout: 120000 },
  outputDir: join(tmpdir(), 'dez-payouts-e2e-results'),
  reporter: [['list']],
  use: { baseURL: 'http://127.0.0.1:4175', screenshot: 'only-on-failure', actionTimeout: 120000 },
  webServer: { command: 'node node_modules/vite/bin/vite.js apps/equipe --host 127.0.0.1 --port 4175', port: 4175, reuseExistingServer: false, env: { VITE_USE_MSW: 'false', VITE_API_URL: 'http://127.0.0.1:18001' } },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
