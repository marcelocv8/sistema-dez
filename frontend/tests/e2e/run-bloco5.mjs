import { spawn } from 'node:child_process';
import { appendFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium, devices } from '@playwright/test';

const API_PORT = 18001;
const VITE_PORT = 4175;
const baseUrl = `http://localhost:${VITE_PORT}`;
const apiUrl = `http://localhost:${API_PORT}`;
const results = [];
const netLog = (line) => { try { appendFileSync(join(tmpdir(), 'pw-net.log'), line + '\n'); } catch { /* ignore */ } };

function waitForPort(url, timeoutMs = 30000) {
  const started = Date.now();
  return new Promise((resolve, reject) => {
    const probe = async () => {
      try {
        const response = await fetch(url);
        if (response.ok) return resolve();
      } catch { /* retry */ }
      if (Date.now() - started > timeoutMs) return reject(new Error(`timeout aguardando ${url}`));
      setTimeout(probe, 500);
    };
    probe();
  });
}

const backendEnv = {
  ...process.env,
  DB_CONNECTION: 'pgsql',
  DB_HOST: 'localhost',
  DB_PORT: '5433',
  DB_DATABASE: 'sistema_dez_test',
  DB_USERNAME: 'sistema_dez',
  DB_PASSWORD: 'local_change_me',
  APP_ENV: 'testing',
  SESSION_DRIVER: 'database',
  CACHE_STORE: 'database',
  SANCTUM_STATEFUL_DOMAINS: 'localhost:4175',
  FRONTEND_URLS: `http://localhost:${VITE_PORT}`,
};

const php = join(process.env.LOCALAPPDATA ?? '', 'php', 'php.exe');
const backendDir = join(process.cwd(), '..', 'backend');
const api = spawn(php, ['-S', `127.0.0.1:${API_PORT}`, '-t', 'public'], { cwd: backendDir, env: backendEnv, stdio: 'ignore' });
const apiV6 = spawn(php, ['-S', `[::1]:${API_PORT}`, '-t', 'public'], { cwd: backendDir, env: backendEnv, stdio: 'ignore' });
const vite = spawn('node', ['node_modules/vite/bin/vite.js', 'apps/equipe', '--host', 'localhost', '--port', String(VITE_PORT)], {
  cwd: process.cwd(),
  env: { ...process.env, VITE_API_URL: apiUrl, VITE_USE_MSW: 'false' },
  stdio: 'ignore',
});

async function loginIfNeeded(page, password) {
  await page.goto(`${baseUrl}/relatorios`);
  const email = page.getByLabel('E-mail', { exact: true });
  const alreadyIn = await email.isVisible().catch(() => false);
  if (!alreadyIn) return;
  await email.fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1200);
}

function login(page, password) {
  return async () => {
    await page.goto(`${baseUrl}/medicina-trabalho`);
    await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
    await page.getByLabel('Senha', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Entrar', exact: true }).click();
    await page.waitForURL(`${baseUrl}/medicina-trabalho`, { timeout: 30000 }).catch(() => {});
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
  };
}

async function scenarioMt(page, password, label) {
  await login(page, password)();
  const heading = page.getByRole('heading', { name: 'Medicina do Trabalho' });
  await heading.waitFor({ state: 'visible', timeout: 30000 });

  await page.getByText('Funcionario MT Vencido E2E').waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('.dez-status--danger', { hasText: 'Vencido' }).first().waitFor({ state: 'visible' });

  await page.getByRole('tab', { name: 'ASOs' }).click();
  await page.getByText('Funcionario MT A Vencer E2E').waitFor({ state: 'visible', timeout: 30000 });
  await page.locator('tbody tr', { hasText: 'Funcionario MT Vencido E2E' }).first().waitFor({ state: 'visible' });

  await page.getByRole('tab', { name: 'Empresas' }).click();
  await page.getByText('PRODUCAO MT').waitFor({ state: 'visible', timeout: 30000 });
  await page.getByLabel('Empresa').first().selectOption({ label: 'EMPRESA MT PILOTO E2E' });
  await page.getByLabel('Novo setor').fill(`ADMINISTRATIVO MT ${label}`);
  await page.getByRole('button', { name: 'Criar setor' }).click();
  await page.getByText(`ADMINISTRATIVO MT ${label}`).waitFor({ state: 'visible', timeout: 30000 });

  await page.getByRole('tab', { name: 'Relatório empresa' }).click();
  await page.getByLabel('Empresa').selectOption({ label: 'EMPRESA MT PILOTO E2E' });
  await page.getByText('Total de ASOs:').waitFor({ state: 'visible', timeout: 30000 });
}

async function scenarioRelatorios(page, password, label) {
  await loginIfNeeded(page, password);
  await page.getByRole('heading', { name: 'Relatórios & BI' }).waitFor({ state: 'visible', timeout: 30000 });

  await page.getByText('Receita (R$)').waitFor({ state: 'visible', timeout: 30000 });
  await page.getByText('Ticket médio').waitFor({ state: 'visible' });
  await page.getByText('No-show', { exact: true }).waitFor({ state: 'visible' });
  await page.getByText('CSV produção').waitFor({ state: 'visible' });
  await page.getByText('CSV B2B em aberto').waitFor({ state: 'visible' });
}

try {
  await waitForPort(`${apiUrl}/api/v1/public/services`);
  await waitForPort(baseUrl);
  rmSync(join(tmpdir(), 'pw-net.log'), { force: true });

  const password = process.env.E2E_PASSWORD;
  if (!password || password.length < 12) {
    console.error('Defina E2E_PASSWORD (>= 12 chars).');
    process.exit(2);
  }

  for (const [label, device] of [['desktop', devices['Desktop Chrome']], ['mobile', devices['Pixel 7']]]) {
    const browser = await chromium.launch();
    const context = await browser.newContext({ ...device, viewport: label === 'desktop' ? { width: 1440, height: 1000 } : device.viewport });
    const page = await context.newPage();
    page.on('pageerror', (error) => netLog(`[${label}] PAGEERROR: ${error.message}`));
    page.on('console', (m) => { if (m.type() === 'error') netLog(`[${label}] CONSOLE-ERR: ${m.text().slice(0, 160)}`); });
    page.on('requestfailed', (r) => netLog(`[${label}] FAILED: ${r.method()} ${r.url()} ${r.failure()?.errorText ?? ''}`));
    page.on('request', (r) => { if (r.url().includes('18001')) netLog(`[${label}] REQ: ${r.method()} ${r.url()}`); });
    page.on('response', (r) => { if (r.url().includes('18001')) netLog(`[${label}] RESP: ${r.status()} ${r.request().method()} ${r.url()}`); });

    for (const [name, scenario] of [['mt', scenarioMt], ['relatorios', scenarioRelatorios]]) {
      const started = Date.now();
      try {
        await scenario(page, password, `${label}-${Date.now()}`);
        results.push({ name: `${label}/${name}`, ok: true, ms: Date.now() - started });
      } catch (error) {
        results.push({ name: `${label}/${name}`, ok: false, ms: Date.now() - started, error: String(error).slice(0, 400) });
      }
    }
    await browser.close();
  }
} finally {
  api.kill();
  apiV6.kill();
  vite.kill();
}

console.log('\n=== BLOCO 5 E2E ===');
for (const result of results) {
  console.log(`${result.ok ? 'PASS' : 'FAIL'} ${result.name} (${result.ms}ms)${result.ok ? '' : `\n  ${result.error}`}`);
}
const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
