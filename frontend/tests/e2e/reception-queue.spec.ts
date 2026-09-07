import { expect, test } from '@playwright/test';

test('Recepção faz check-in, ordena fila com prioridade e chama no painel', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const next = new Date(); next.setDate(next.getDate() + 1);
  const date = next.toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const weekday = new Date(`${date}T12:00:00-03:00`).getDay();

  // Admin monta oferta + escala.
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
  await expect(page.locator('.schedule-rule').first()).toBeVisible();
  await page.getByRole('button', { name: 'Sair', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Acesso da equipe' })).toBeVisible();

  // Recepção agenda duas fichas.
  await page.goto('/agenda');
  await page.getByLabel('E-mail', { exact: true }).fill('recepcao-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  for (const [cpf, name] of [['111.444.777-35', 'Paciente Fila Um'], ['987.654.321-00', 'Paciente Fila Dois']]) {
    await page.getByRole('button', { name: 'Novo agendamento', exact: true }).click();
    await page.getByLabel('Oferta de atendimento').selectOption({ label: 'CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · PARTICULAR PILOTO' });
    await page.getByLabel('Data do agendamento').fill(date);
    await page.getByLabel('Horário disponível').selectOption({ index: 1 });
    await page.getByLabel('CPF do paciente').fill(cpf);
    await page.getByLabel('Nome do paciente').fill(name);
    await page.getByLabel('Telefone do paciente').fill('85999999999');
    await page.getByLabel('Paciente informado sobre o uso dos dados para o agendamento.').check();
    await page.getByRole('button', { name: 'Confirmar agendamento interno' }).click();
    await expect(page.getByRole('heading', { name: 'Novo agendamento interno' })).not.toBeVisible();
  }

  // Check-in pela recepção.
  await page.goto('/recepcao');
  await expect(page.getByRole('heading', { name: 'Recepção' })).toBeVisible();
  await page.getByLabel('Data').fill(date);
  await expect(page.getByText('Paciente Fila Um')).toBeVisible();
  await page.getByRole('button', { name: 'Fazer check-in' }).first().click();
  await expect(page.getByText('Check-in registrado')).toBeVisible();

  // Fila mostra a senha; prioridade legal reordena sem apagar a chegada.
  await page.getByRole('button', { name: 'Fila de hoje' }).click();
  await expect(page.getByText('A001')).toBeVisible();
  await page.getByRole('button', { name: 'Prioridade' }).first().click();
  await page.getByLabel('Prioridade legal').selectOption('GESTANTE');
  await page.getByLabel('Motivo (auditoria)').fill('Gestante com prioridade legal');
  await page.getByRole('button', { name: 'Confirmar ordem' }).click();
  await expect(page.getByText('Gestante').first()).toBeVisible();

  // Chamada alimenta o painel.
  await page.getByRole('button', { name: 'Chamar' }).first().click();
  await expect(page.getByText(/Chamando A00/)).toBeVisible();

  // Display público reflete a chamada, só com o primeiro nome.
  const units = await page.request.get('http://localhost:18001/api/v1/public/units');
  expect(units.ok()).toBeTruthy();
  const unitId = (await units.json()).data.find((unit: { name: string }) => unit.name === 'CLINICA DEZ MESSEJANA').id;
  const display = await page.request.get(`http://localhost:18001/api/v1/public/display?unit_id=${unitId}&date=${date}`);
  expect(display.ok()).toBeTruthy();
  const payload = (await display.json()).data;
  expect(payload.current.ticket).toMatch(/^A\d{3}$/);
  expect(JSON.stringify(payload)).not.toContain('Fila Um');
  expect(payload.next.length).toBeGreaterThanOrEqual(0);

  // Semana e mês agregam as fichas sem quebrar o dia.
  await page.goto('/agenda');
  await page.getByRole('button', { name: 'Semana', exact: true }).click();
  await expect(page.getByText(/ficha\(s\)/).first()).toBeVisible();
  await page.getByRole('button', { name: 'Mês', exact: true }).click();
  await expect(page.locator('.agenda-month')).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('fila', { body: await page.screenshot(), contentType: 'image/png' });
});
