import { appendFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';

const netLog = (line: string): void => {
  try {
    appendFileSync(join(tmpdir(), 'pw-net.log'), line + '\n');
  } catch {
    /* ignore */
  }
};

test('Medicina do Trabalho: vencimentos, empresas, ASO e relatório por empresa', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => { errors.push(error.message); netLog('PAGEERROR: ' + error.message); });
  page.on('requestfailed', (r) => netLog('FAILED: ' + r.method() + ' ' + r.url() + ' ' + (r.failure()?.errorText ?? '')));
  page.on('request', (r) => { if (r.url().includes('18001')) netLog('REQ: ' + r.method() + ' ' + r.url()); });
  page.on('response', (r) => { if (r.url().includes('18001')) netLog('RESP: ' + r.status() + ' ' + r.request().method() + ' ' + r.url()); });

  await page.goto('/medicina-trabalho');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await expect(page.getByRole('heading', { name: 'Medicina do Trabalho' })).toBeVisible();

  // Vencimentos: funcionário vencido aparece com flag.
  await expect(page.getByText('Funcionario MT Vencido E2E')).toBeVisible();
  await expect(page.locator('.dez-status--danger', { hasText: 'Vencido' }).first()).toBeVisible();

  // ASOs: dois semeados, um vencido e um a vencer.
  await page.getByRole('tab', { name: 'ASOs' }).click();
  await expect(page.getByText('Funcionario MT A Vencer E2E')).toBeVisible();
  await expect(page.locator('tbody tr', { hasText: 'Funcionario MT Vencido E2E' })).toBeVisible();

  // Empresas: setor semeado + criação de um novo setor.
  await page.getByRole('tab', { name: 'Empresas' }).click();
  await expect(page.getByText('PRODUCAO MT')).toBeVisible();
  const empresaSelect = page.getByLabel('Empresa').first();
  await empresaSelect.selectOption({ label: 'EMPRESA MT PILOTO E2E' });
  await page.getByLabel('Novo setor').fill('ADMINISTRATIVO MT');
  await page.getByRole('button', { name: 'Criar setor' }).click();
  await expect(page.getByText('ADMINISTRATIVO MT')).toBeVisible();

  // Relatório por empresa.
  await page.getByRole('tab', { name: 'Relatório empresa' }).click();
  await page.getByLabel('Empresa').selectOption({ label: 'EMPRESA MT PILOTO E2E' });
  await expect(page.getByText('Total de ASOs:')).toBeVisible();
  await expect(page.getByText('2', { exact: true })).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('mt-relatorios', { body: await page.screenshot(), contentType: 'image/png' });
});

test('Relatórios & BI: kpis e exportações visíveis', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('/relatorios');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await expect(page.getByRole('heading', { name: 'Relatórios & BI' })).toBeVisible();

  await expect(page.getByText('Receita (R$)')).toBeVisible();
  await expect(page.getByText('Ticket médio')).toBeVisible();
  await expect(page.getByText('No-show', { exact: true })).toBeVisible();
  await expect(page.getByText('CSV produção')).toBeVisible();
  await expect(page.getByText('CSV B2B em aberto')).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('relatorios', { body: await page.screenshot(), contentType: 'image/png' });
});
