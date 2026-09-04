import { expect, test } from '@playwright/test';

test('Repasses 3C: gera, médico confirma, caixa paga em espécie e abate o cofre', async ({ page, browser }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  test.setTimeout(540000);
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const next = new Date(); next.setDate(next.getDate() + 1);
  const date = next.toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const weekday = new Date(`${date}T12:00:00-03:00`).getDay();

  // Admin: comissão 20% no profissional piloto + oferta + escala.
  await page.goto('/cadastros');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Cadastros e acessos' })).toBeVisible();
  await page.getByRole('button', { name: 'Profissionais', exact: true }).click();
  await page.locator('.catalog-row').filter({ hasText: 'PROFISSIONAL PILOTO E2E' }).getByRole('button', { name: 'Editar' }).click();
  await page.getByLabel('Valor da comissão (% ou R$)').fill('20');
  await page.getByRole('button', { name: 'Salvar cadastro', exact: true }).click();
  await page.getByRole('tab', { name: 'Ofertas e horários' }).click();
  await page.getByLabel('Vínculo validado').selectOption({ label: 'CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · PARTICULAR PILOTO' });
  await page.getByLabel('Preço vigente de origem').selectOption({ label: 'R$ 150,00 · PRIVATE' });
  await page.getByRole('button', { name: 'Salvar oferta', exact: true }).click();
  await page.getByLabel('Dia da semana').selectOption(String(weekday));
  await page.getByLabel('Início da vigência').fill(date);
  await page.getByLabel('Hora inicial', { exact: true }).fill('08:00');
  await page.getByLabel('Hora final', { exact: true }).fill('10:00');
  await page.getByRole('button', { name: 'Adicionar escala' }).click();
  await expect(page.locator('.schedule-rule').filter({ hasText: '08:00–10:00' })).toBeVisible({ timeout: 120000 });
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
  await page.getByLabel('Nome do paciente').fill('Paciente Repasse E2E');
  await page.getByLabel('Telefone do paciente').fill('85999999999');
  await page.getByLabel('Paciente informado sobre o uso dos dados para o agendamento.').check();
  await page.getByRole('button', { name: 'Confirmar agendamento interno' }).click();
  await expect(page.getByRole('heading', { name: 'Novo agendamento interno' })).not.toBeVisible({ timeout: 180000 });

  // Caixa abre e cobra em espécie (150 + fundo 100 = 250 esperado).
  const cashContext = await browser.newContext({ viewport: page.viewportSize()! });
  const cash = await cashContext.newPage();
  cash.on('pageerror', (error) => errors.push(error.message));
  await cash.goto('/caixa');
  await cash.getByLabel('E-mail', { exact: true }).fill('caixa-piloto@example.test');
  await cash.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await cash.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(cash.getByRole('heading', { name: 'Caixa', exact: true })).toBeVisible();
  await cash.getByRole('button', { name: 'Abrir caixa' }).click();
  await expect(cash.getByText('Caixa aberto', { exact: false })).toBeVisible({ timeout: 120000 });
  await cash.getByLabel('Forma de pagamento').selectOption('ESPECIE');
  await cash.getByLabel('Data das fichas').fill(date);
  await cash.getByRole('button', { name: /Cobrar R\$/ }).click();
  await expect(cash.getByText(/Recibo 1 ·/, { exact: false })).toBeVisible({ timeout: 120000 });

  // Médico confirma o próprio repasse (20% de 150 = 30).
  const doctorContext = await browser.newContext({ viewport: page.viewportSize()! });
  const doctor = await doctorContext.newPage();
  doctor.on('pageerror', (error) => errors.push(error.message));
  await doctor.goto('/repasses');
  await doctor.getByLabel('E-mail', { exact: true }).fill('medico-piloto@example.test');
  await doctor.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await doctor.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(doctor.getByRole('heading', { name: 'Repasses médicos' })).toBeVisible();
  await doctor.getByLabel('De', { exact: true }).fill(date);
  await doctor.getByLabel('Até', { exact: true }).fill(date);
  await expect(doctor.getByText('Profissional: PROFISSIONAL PILOTO E2E', { exact: false })).toBeVisible({ timeout: 60000 });
  await doctor.getByRole('button', { name: 'Gerar repasse do período', exact: true }).click();
  await expect(doctor.getByText('R$ 30,00', { exact: false }).first()).toBeVisible({ timeout: 120000 });
  await doctor.getByRole('button', { name: 'Confirmar repasse', exact: true }).click();
  await expect(doctor.getByText('Confirmado pelo médico', { exact: false }).first()).toBeVisible({ timeout: 120000 });
  await doctor.screenshot({ path: testInfo.outputPath('repasse-confirmado.png'), fullPage: true });

  // Caixa paga em espécie: 250 − 30 = 220 no cofre.
  await cash.getByLabel('Data das fichas').fill(date);
  await cash.goto('/repasses');
  await expect(cash.getByRole('heading', { name: 'Repasses médicos' })).toBeVisible();
  await cash.getByLabel('De', { exact: true }).fill(date);
  await cash.getByLabel('Até', { exact: true }).fill(date);
  await cash.getByRole('button', { name: 'Abrir', exact: true }).click();
  await expect(cash.getByLabel('Meio', { exact: true })).toHaveValue('ESPECIE');
  await cash.getByLabel('Caixa aberto', { exact: true }).selectOption({ index: 1 }, { timeout: 120000 });
  await cash.getByRole('button', { name: /Pagar R\$/ }).click();
  await expect(cash.getByText('Repasse pago', { exact: false })).toBeVisible({ timeout: 120000 });
  await cash.goto('/caixa');
  await expect(cash.getByText('R$ 220,00', { exact: false }).first()).toBeVisible({ timeout: 120000 });
  await expect(await cash.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(errors).toEqual([]);
  await cashContext.close();
  await doctorContext.close();
});
