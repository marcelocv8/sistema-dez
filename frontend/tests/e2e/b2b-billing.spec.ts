import { expect, test } from '@playwright/test';

test('B2B autoriza sem caixa, fatura no fim do mês e baixa até quitar', async ({ page, browser }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const tag = testInfo.project.name === 'mobile' ? 'M' : 'D';
  const patientName = `Paciente Empresa E2E ${tag}`;
  const patientCpf = testInfo.project.name === 'mobile' ? '123.456.789-09' : '222.555.888-46';
  const companyName = testInfo.project.name === 'mobile' ? 'EMPRESA PILOTO E2E M' : 'EMPRESA PILOTO E2E';
  const offerLabel = `CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · ${companyName}`;
  const next = new Date(); next.setDate(next.getDate() + 1);
  const date = next.toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const competence = date.slice(0, 7);
  const weekday = new Date(`${date}T12:00:00-03:00`).getDay();

  // Admin monta oferta B2B + escala.
  await page.goto('/cadastros');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Cadastros e acessos' })).toBeVisible();
  await page.getByRole('tab', { name: 'Ofertas e horários' }).click();
  await page.getByLabel('Vínculo validado').selectOption({ label: offerLabel });
  await page.getByLabel('Preço vigente de origem').selectOption({ label: 'R$ 150,00 · PRIVATE' });
  await page.getByRole('button', { name: 'Salvar oferta', exact: true }).click();
  await page.getByLabel('Dia da semana').selectOption(String(weekday));
  await page.getByLabel('Início da vigência').fill(date);
  await page.getByLabel('Hora inicial', { exact: true }).fill('08:00');
  await page.getByLabel('Hora final', { exact: true }).fill('10:00');
  await page.getByRole('button', { name: 'Adicionar escala' }).click();
  await expect(page.locator('.schedule-rule').first()).toBeVisible();
  await page.getByRole('button', { name: 'Sair', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Acesso da equipe' })).toBeVisible();

  // Recepção agenda a ficha da empresa e autoriza sem passar pelo caixa.
  await page.goto('/agenda');
  await page.getByLabel('E-mail', { exact: true }).fill('recepcao-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await page.getByRole('button', { name: 'Novo agendamento', exact: true }).click();
  await page.getByLabel('Oferta de atendimento').selectOption({ label: offerLabel });
  await page.getByLabel('Data do agendamento').fill(date);
  await page.getByLabel('Horário disponível').selectOption({ index: 1 });
  await page.getByLabel('CPF do paciente').fill(patientCpf);
  await page.getByLabel('Nome do paciente').fill(patientName);
  await page.getByLabel('Telefone do paciente').fill('85999999999');
  await page.getByLabel('Paciente informado sobre o uso dos dados para o agendamento.').check();
  await page.getByRole('button', { name: 'Confirmar agendamento interno' }).click();
  await expect(page.getByRole('heading', { name: 'Novo agendamento interno' })).not.toBeVisible();
  await page.locator('.mini-calendar').getByRole('button', { name: String(next.getDate()), exact: true }).click();
  const card = page.locator('.appointment').filter({ hasText: patientName }).first();
  await expect(card).toBeVisible();
  await card.click();
  await page.getByLabel('Código de autorização da empresa').fill('AUT-E2E-001');
  await page.getByRole('button', { name: 'Autorizar B2B (fatura no fim do mês)' }).click();
  await expect(page.getByText('Autorizado B2B (fatura no fim do mês)')).toBeVisible();

  // Admin fatura o mês, encaminha e baixa até quitar.
  const billingContext = await browser.newContext({ viewport: page.viewportSize()! });
  const billing = await billingContext.newPage();
  billing.on('pageerror', (error) => errors.push(error.message));
  await billing.goto('/faturamento-b2b');
  await billing.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await billing.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await billing.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(billing.getByRole('heading', { name: 'Faturamento B2B' })).toBeVisible();
  await billing.getByLabel('Empresa (convênio)').selectOption({ label: companyName });
  await billing.getByLabel('Competência').fill(competence);
  await billing.getByRole('button', { name: 'Gerar fatura da competência' }).click();
  const invoiceRow = billing.locator('.catalog-row', { hasText: companyName });
  await expect(invoiceRow).toBeVisible();
  await expect(invoiceRow).toContainText('R$ 150,00');
  await invoiceRow.getByRole('button', { name: 'Encaminhar à empresa' }).click();
  await expect(invoiceRow.getByText('Enviada à empresa')).toBeVisible();
  await invoiceRow.getByRole('button', { name: 'Ver itens' }).click();
  await billing.getByLabel(/Valor da baixa/).fill('150.00');
  await billing.getByLabel('Comprovante (ref.)').fill('BOLETO-E2E-001');
  await invoiceRow.getByRole('button', { name: 'Baixar pagamento' }).click();
  await expect(invoiceRow.getByText('Quitada', { exact: true })).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('b2b', { body: await billing.screenshot(), contentType: 'image/png' });
  await billingContext.close();
});

