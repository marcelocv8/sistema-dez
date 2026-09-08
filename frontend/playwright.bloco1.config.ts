import { defineConfig, devices } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'attendance-chat.spec.ts',
  workers: 1,
  timeout: 240000,
  expect: { timeout: 30000 },
  outputDir: join(tmpdir(), 'dez-bloco1-e2e-results'),
  reporter: [['list']],
  use: { baseURL: 'http://localhost:4175', screenshot: 'only-on-failure', actionTimeout: 60000 },
  webServer: { command: 'node node_modules/vite/bin/vite.js apps/equipe --host localhost --port 4175', port: 4175, reuseExistingServer: false, env: { VITE_USE_MSW: 'false', VITE_API_URL: 'http://localhost:18001' } },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
