import { expect, test } from '@playwright/test';

test('Prontuário: alerta, nota e laudo publicado no portal (folha de acesso)', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('/prontuario');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Prontuário' })).toBeVisible();

  await page.getByLabel('CPF do paciente').fill('111.444.777-35');
  await page.getByRole('button', { name: 'Localizar' }).click();
  await expect(page.getByText('Paciente:')).toBeVisible();
  await expect(page.getByText('Sem alertas ativos.').or(page.locator('.alert-chip').first())).toBeVisible();

  await page.getByLabel('Novo alerta').fill('Penicilina');
  await page.getByRole('button', { name: 'Incluir alerta' }).click();
  await expect(page.locator('.alert-chip', { hasText: 'Penicilina' }).first()).toBeVisible();

  const editor = page.locator('[aria-label="Nova nota do prontuário"]');
  await expect(editor).toBeVisible();
  await editor.click();
  await page.keyboard.type('Paciente relata cefaleia há 3 dias; orientado hidratação e retorno.');
  await page.getByRole('button', { name: 'Registrar nota' }).click();
  await expect(page.getByText('Nota registrada no prontuário.')).toBeVisible();
  await expect(page.getByText('Paciente relata cefaleia há 3 dias; orientado hidratação e retorno.', { exact: true }).last()).toBeVisible();

  // Laudo finalizado publicado no portal com folha de acesso.
  await page.goto('/laudos');
  await page.getByLabel('CPF do paciente').fill('111.444.777-35');
  await page.getByRole('button', { name: 'Localizar' }).click();
  await expect(page.getByText('Paciente:')).toBeVisible();
  await page.getByLabel(/Modelo para/).first().selectOption({ label: 'ECO PILOTO E2E · PROFISSIONAL PILOTO E2E' });
  await page.getByRole('button', { name: 'Criar laudo' }).first().click();
  await expect(page.locator('h2', { hasText: 'Laudo (rascunho)' })).toBeVisible();
  await page.getByRole('button', { name: 'Finalizar' }).click();
  await expect(page.locator('h2', { hasText: 'Laudo (finalizado)' })).toBeVisible();
  await page.getByRole('button', { name: 'Publicar resultado no portal' }).click();
  await expect(page.getByRole('button', { name: 'Folha de acesso' })).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('prontuario-portal', { body: await page.screenshot(), contentType: 'image/png' });
});
