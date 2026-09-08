import { expect, test } from '@playwright/test';

test('Laudo do STG: cria por modelo, finaliza, imprime e registra assinatura manual', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('/laudos');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Laudos' })).toBeVisible();

  await page.getByLabel('CPF do paciente').fill('111.444.777-35');
  await page.getByRole('button', { name: 'Localizar' }).click();
  await expect(page.getByText('Paciente:')).toBeVisible();
  await expect(page.getByText(/Sem laudos/).or(page.locator('.catalog-row').first())).toBeVisible();

  await page.getByLabel(/Modelo para/).first().selectOption({ label: 'ECO PILOTO E2E · PROFISSIONAL PILOTO E2E' });
  await page.getByRole('button', { name: 'Criar laudo' }).first().click();
  const editorPanel = page.locator('h2', { hasText: 'Laudo (rascunho)' });
  await expect(editorPanel).toBeVisible();
  const editor = page.locator('[aria-label="Conteúdo do laudo"]');
  await expect(editor).toContainText('ECODOPPLERCARDIOGRAMA');
  await expect(editor).toContainText('Paciente Piloto E2E');

  await page.getByRole('button', { name: 'Finalizar' }).click();
  await expect(page.locator('h2', { hasText: 'Laudo (finalizado)' })).toBeVisible();

  await page.getByRole('button', { name: 'Imprimir' }).click();
  await expect(page.getByRole('button', { name: /reimpressão #2/ })).toBeVisible();

  await page.getByRole('button', { name: 'Marcar assinatura manual' }).click();
  await expect(page.getByText('Assinatura manual registrada.')).toBeVisible();
  await expect(page.locator('.catalog-row', { hasText: 'assinado' }).first()).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('laudos', { body: await page.screenshot(), contentType: 'image/png' });
});
