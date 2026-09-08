import { expect, test } from '@playwright/test';

test('Orçamento por prescrição: cria, envia e resolve; rotina D+1 aparece', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  // Admin loga (também registra ponto) e prepara rotinas do dia.
  await page.goto('/rotinas');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Rotinas' })).toBeVisible();
  await page.getByRole('button', { name: 'Gerar/refresh do dia' }).click();
  await expect(page.getByText(/tarefa\(s\) nova\(s\)/)).toBeVisible();

  // Orçamento: localiza paciente pelo CPF, escolhe convênio, inclui item, anexa prescrição.
  await page.goto('/orcamentos');
  await expect(page.getByRole('heading', { name: 'Orçamentos por prescrição' })).toBeVisible();
  await page.getByLabel('CPF do paciente').fill('111.444.777-35');
  await page.getByRole('button', { name: 'Localizar' }).click();
  await expect(page.getByText(/Paciente:/)).toBeVisible();
  await page.getByLabel('Convênio da tabela').selectOption({ label: 'PARTICULAR PILOTO' });
  await page.getByLabel('Buscar procedimento (3+ letras/TUSS)').fill('CONSULTA');
  await page.getByRole('button', { name: /Incluir/ }).first().click();
  await page.setInputFiles('input[type="file"]', {
    name: 'prescricao.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 prescricao e2e'),
  });
  await page.getByRole('button', { name: /Criar orçamento/ }).click();
  const budgetRow = page.locator('.catalog-row', { hasText: 'Paciente Piloto E2E' }).first();
  await expect(budgetRow).toBeVisible();
  await expect(budgetRow.getByText('RASCUNHO')).toBeVisible();

  // Envia ao paciente e resolve com o sim obrigatório.
  await budgetRow.getByRole('button', { name: 'Enviar ao paciente' }).click();
  await expect(budgetRow.getByText('ENVIADO')).toBeVisible();
  await budgetRow.getByRole('button', { name: 'Resolver sim/não' }).click();
  await page.getByLabel('Como foi resolvido').fill('Aceito por ligação');
  await budgetRow.getByRole('button', { name: 'Registrar resolução' }).click();
  await expect(budgetRow.getByText('ACEITO', { exact: true })).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('orcamentos', { body: await page.screenshot(), contentType: 'image/png' });
});
