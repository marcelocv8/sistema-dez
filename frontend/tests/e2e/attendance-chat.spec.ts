import { expect, test } from '@playwright/test';

test('Ponto registra logins com relogin e bate-papo flui entre equipe', async ({ page }, testInfo) => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const tag = testInfo.project.name === 'mobile' ? 'M' : 'D';
  const chatMessage = `Paciente X chegou, sala 3 (${tag})`;

  // Admin loga (entrada 1) e configura horário de saída.
  await page.goto('/ponto');
  await page.getByLabel('E-mail', { exact: true }).fill('admin-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Controle de ponto' })).toBeVisible();
  await page.getByLabel('Novo horário de saída (HH:MM)').fill('17:00');
  await page.getByRole('button', { name: 'Salvar', exact: true }).click();
  await expect(page.getByText('Horário de saída atualizado para 17:00')).toBeVisible();
  await expect(page.getByText('Entrada única')).toBeVisible();
  await expect(page.getByText('Exportar CSV')).toBeVisible();

  // Bate-papo: admin abre conversa 1:1 com a recepção e envia mensagem.
  await page.goto('/batepapo');
  await page.getByRole('button', { name: 'Nova conversa' }).click();
  await page.locator('.chat-people').getByRole('button', { name: /Recepção do piloto/ }).click();
  await page.getByRole('button', { name: 'Abrir conversa 1:1' }).click();
  await page.getByLabel('Mensagem').fill(chatMessage);
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.getByText(chatMessage)).toBeVisible();
  await page.getByRole('button', { name: 'Sair', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Acesso da equipe' })).toBeVisible();

  // Recepção loga (entrada dela), lê a mensagem, responde e vê o próprio ponto bloqueado.
  await page.goto('/batepapo');
  await page.getByLabel('E-mail', { exact: true }).fill('recepcao-piloto@example.test');
  await page.getByLabel('Senha', { exact: true }).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Admin do piloto de testes/ })).toBeVisible();
  await page.locator('.chat-row', { hasText: 'Admin do piloto' }).first().click();
  await expect(page.getByText(chatMessage)).toBeVisible();
  await page.getByLabel('Mensagem').fill('Pode mandar entrar');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.getByText('Pode mandar entrar')).toBeVisible();

  await page.goto('/ponto');
  await expect(page.getByText('exclusivo de Admin e Supervisor')).toBeVisible();

  expect(errors).toEqual([]);
  await testInfo.attach('bloco1', { body: await page.screenshot(), contentType: 'image/png' });
});
