import { expect, test } from '@playwright/test';

test('Caixa 3B: cobra, sangria, fecha exato e mostra relatório sem lacuna', async ({ page, browser }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const next = new Date(); next.setDate(next.getDate() + 1);
  const date = next.toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const weekday = new Date(`${date}T12:00:00-03:00`).getDay();

  // Admin monta oferta + escala (entra no ar sozinha, sem efeito no caixa).
  await page.goto('/cadastros');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Cadastros e acessos' })).toBeVisible();
  await page.getByRole('tab', { name: 'Ofertas e horários' }).click();
  await page.getByLabel('Vínculo validado').selectOption({ label: 'CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · PARTICULAR PILOTO' });
  await page.getByLabel('Preço vigente de origem').selectOption({ label: 'R$ 150,00 · PRIVATE' });
  await page.getByRole('button', { name: 'Salvar oferta', exact: true }).click();
  await page.getByLabel('Dia da semana').selectOption(String(weekday));
  await page.getByLabel('Início da vigência').fill(date);
  await page.getByLabel('Hora inicial', { exact: true }).fill('08:00');
  await page.getByLabel('Hora final', { exact: true }).fill('10:00');
  await page.getByRole('button', { name: 'Adicionar escala' }).click();
  await expect(page.locator('.schedule-rule').filter({ hasText: '08:00–10:00' })).toBeVisible();
  await page.getByRole('button', { name: 'Sair', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Acesso da equipe' })).toBeVisible();

  // Recepção monta a ficha.
  await page.goto('/agenda');
  await page.getByLabel('E-mail', { exact: true }).fill('recepcao-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await page.getByRole('button', { name: 'Novo agendamento', exact: true }).click();
  await page.getByLabel('Oferta de atendimento').selectOption({ label: 'CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · PARTICULAR PILOTO' });
  await page.getByLabel('Data do agendamento').fill(date);
  await page.getByLabel('Horário disponível').selectOption({ label: '08:00 · 1 vaga(s)' });
  await page.getByLabel('CPF do paciente').fill('111.444.777-35');
  await page.getByRole('button', { name: 'Localizar paciente' }).click();
  await page.getByLabel('Nome do paciente').fill('Paciente Fechamento E2E');
  await page.getByLabel('Telefone do paciente').fill('85999999999');
  await page.getByLabel('Paciente informado sobre o uso dos dados para o agendamento.').check();
  await page.getByRole('button', { name: 'Confirmar agendamento interno' }).click();
  await expect(page.getByRole('heading', { name: 'Novo agendamento interno' })).not.toBeVisible();

  // Caixa abre, cobra em espécie, registra sangria e fecha exato.
  const cashContext = await browser.newContext({ viewport: page.viewportSize()! });
  const cash = await cashContext.newPage();
  cash.on('pageerror', (error) => errors.push(error.message));
  await cash.goto('/caixa');
  await cash.getByLabel('E-mail', { exact: true }).fill('caixa-piloto@example.test');
  await cash.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await cash.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(cash.getByRole('heading', { name: 'Caixa', exact: true })).toBeVisible();
  await cash.getByRole('button', { name: 'Abrir caixa' }).click();
  await expect(cash.getByText('Caixa aberto', { exact: false })).toBeVisible();
  await cash.getByLabel('Forma de pagamento').selectOption('ESPECIE');
  await cash.getByLabel('Data das fichas').fill(date);
  await cash.getByRole('button', { name: /Cobrar R\$/ }).click();
  await expect(cash.getByText(/Recibo 1 ·/, { exact: false })).toBeVisible();

  // Sangria de R$ 30: esperado passa a 100 + 150 - 30 = 220.
  await cash.getByLabel('Valor (R$)', { exact: true }).fill('30.00');
  await cash.getByLabel('Motivo', { exact: true }).fill('Pagamento fornecedor E2E');
  await cash.getByLabel('Comprovante (ref.)', { exact: true }).fill('COMP-E2E-001');
  await cash.getByRole('button', { name: 'Registrar sangria', exact: true }).click();
  await expect(cash.getByText('R$ 220,00', { exact: false }).first()).toBeVisible();

  // Fecha exato com o contado.
  await cash.getByLabel('Dinheiro contado no cofre (R$)', { exact: true }).fill('220.00');
  await cash.getByRole('button', { name: 'Fechar caixa do dia', exact: true }).click();
  await expect(cash.getByRole('heading', { name: 'Abrir caixa do dia' })).toBeVisible();
  await cash.screenshot({ path: testInfo.outputPath('caixa-fechado-3b.png'), fullPage: true });

  // Relatório diário sem lacuna.
  await expect(cash.getByText('Sem lacuna de recibo.', { exact: false })).toBeVisible();
  await expect(await cash.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
  await cashContext.close();
});
