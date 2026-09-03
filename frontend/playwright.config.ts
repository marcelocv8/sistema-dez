import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  reporter: [['html', { open: 'never' }]],
  use: { baseURL: 'http://127.0.0.1:4173', trace: 'on-first-retry' },
  webServer: [
    { command: 'node node_modules/vite/bin/vite.js apps/equipe --host 127.0.0.1 --port 4173', port: 4173, reuseExistingServer: true, stdout: 'ignore', stderr: 'ignore', env: { VITE_USE_MSW: 'true' } },
    { command: 'node node_modules/vite/bin/vite.js apps/agendamento --host 127.0.0.1 --port 4174', port: 4174, reuseExistingServer: true, stdout: 'ignore', stderr: 'ignore', env: { VITE_USE_MSW: 'true' } },
  ],
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1536, height: 960 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
});
