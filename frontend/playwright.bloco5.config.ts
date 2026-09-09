import { defineConfig, devices } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'mt-relatorios.spec.ts',
  workers: 1,
  timeout: 240000,
  expect: { timeout: 30000 },
  outputDir: join(tmpdir(), 'dez-bloco5-e2e-results'),
  reporter: [['list']],
  use: { baseURL: 'http://localhost:4175', screenshot: 'only-on-failure', actionTimeout: 60000 },
  webServer: [
    { command: 'node tests/e2e/api-server.mjs', url: 'http://127.0.0.1:18001/api/v1/public/services', reuseExistingServer: false },
    { command: 'node tests/e2e/dev-server.mjs', port: 4175, reuseExistingServer: false },
  ],
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
