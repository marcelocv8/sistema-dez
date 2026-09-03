import { expect, test } from '@playwright/test';

test('dashboard e recepção preservam o fluxo operacional', async ({ page }) => {
  await page.goto('/visao-geral');
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible();
  await expect(page.getByText('R$ 24.560,00')).toBeVisible();
  await page.goto('/recepcao');
  await page.getByRole('button', { name: /Maria Aparecida Silva/ }).click();
  await page.getByRole('button', { name: 'Fazer check-in' }).last().click();
  await expect(page.getByRole('status')).toContainText('entrou na fila');
  await page.getByRole('button', { name: 'Chamar próxima' }).click();
  await expect(page.getByRole('status')).toContainText('Chamando');
  await expect(page.getByText('5 aguardando')).toBeVisible();
});

test('recepção consulta preço por nome, TUSS e convênio', async ({ page }) => {
  await page.goto('/precos');
  await page.getByLabel('Procedimento, TUSS ou código').fill('consulta');
  await expect(page.getByText('CONSULTA CLÍNICA GERAL')).toBeVisible();
  await page.getByLabel('Procedimento, TUSS ou código').fill('10101012');
  await expect(page.getByText(/TUSS 10101012/)).toBeVisible();
  await page.getByLabel('Convênio').selectOption({ label: 'SOU DEZ COMPLETO' });
  await expect(page.getByText('R$ 69,00')).toBeVisible();
});

test('admin consulta catálogo, vínculos e pendências reconciliáveis', async ({ page }) => {
  await page.goto('/cadastros');
  await expect(page.getByRole('heading', { name: 'Cadastros e acessos' })).toBeVisible();
  await expect(page.getByText('US CALCANEO DIREITO')).toBeVisible();
  await expect(page.getByText('Salvar cadastro')).toBeVisible();
  await page.getByRole('tab', { name: 'Vínculos' }).click();
  await expect(page.getByText('Este vínculo não publica agenda nem preço online.')).toBeVisible();
  await page.getByRole('tab', { name: 'Pendências STG' }).click();
  await page.getByRole('button', { name: /LEGACY_ID_NOT_FOUND/ }).click();
  await expect(page.getByText('PROCEDIMENTO SEM ID')).toBeVisible();
});

test('agendamento público confirma horário fixo', async ({ page }) => {
  await page.goto('http://127.0.0.1:4174/');
  await page.getByRole('button', { name: /Clínica Geral/ }).click();
  await page.locator('.date-list button').first().click();
  await page.locator('.slot-list button').first().click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.getByLabel('Nome completo').fill('Paciente Teste');
  await page.getByLabel('CPF').fill('12345678901');
  await page.getByLabel('WhatsApp').fill('85999999999');
  await page.getByRole('button', { name: 'Revisar agendamento' }).click();
  await page.getByRole('button', { name: 'Confirmar agendamento' }).click();
  await expect(page.getByRole('heading', { name: 'Tudo certo, Paciente!' })).toBeVisible();
});

test('agendamento não cria rolagem horizontal em celular', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile');
  await page.goto('http://127.0.0.1:4174/');
  await expect(page.getByRole('heading', { name: 'Como podemos cuidar de você?' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
});
