import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { join } from 'node:path';

test.beforeEach(() => {
  test.skip(!process.env.E2E_PASSWORD, 'Use somente credencial temporária de sistema_dez_test.');
  execSync(
    '& "$env:LOCALAPPDATA\\php\\php.exe" artisan migrate:fresh --force; & "$env:LOCALAPPDATA\\php\\php.exe" artisan db:seed --class=SchedulingE2ESeeder --force',
    {
      cwd: join(import.meta.dirname, '..', '..', '..', 'backend'),
      env: {
        ...process.env,
        DB_CONNECTION: 'pgsql',
        DB_HOST: 'localhost',
        DB_PORT: '5433',
        DB_USERNAME: 'sistema_dez',
        DB_PASSWORD: 'local_change_me',
        DB_DATABASE: 'sistema_dez_test_bloco6',
        E2E_DB_NAME: 'sistema_dez_test_bloco6',
        APP_ENV: 'testing',
      },
      shell: 'powershell.exe',
      stdio: 'ignore',
      timeout: 120000,
    },
  );
});

test('Sou DEZ: assinatura com dependente, bloqueio de não-assinante e mensalidade no caixa', async ({ page }, testInfo) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const next = new Date(); next.setDate(next.getDate() + 1);
  const date = next.toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const upcoming = new Date(); upcoming.setDate(1); upcoming.setMonth(upcoming.getMonth() + 1);
  const nextCompetence = `${upcoming.getFullYear()}-${String(upcoming.getMonth() + 1).padStart(2, '0')}`;

  await page.goto('/sou-dez');
  await page.getByLabel('E-mail').fill('admin-piloto@example.test');
  await page.getByLabel('Senha').fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Sou DEZ' })).toBeVisible();

  const planSelect = page.getByLabel('Plano');
  await expect(planSelect.locator('option').nth(1)).toHaveCount(1);
  await planSelect.selectOption({ index: 1 });
  await page.getByLabel('CPF do titular').fill('168.995.350-09');
  await page.getByLabel('Nome do titular').fill('Assinante Sou DEZ E2E');
  await page.getByLabel('CPF do dependente (opcional)').fill('351.722.676-97');
  await page.getByLabel('Nome do dependente').fill('Dependente Sou DEZ E2E');
  await page.getByRole('button', { name: 'Criar assinatura' }).click();
  await expect(page.getByText('Assinante Sou DEZ E2E · SOU DEZ COMPLETO E2E')).toBeVisible();
  await expect(page.getByText('Ativa', { exact: false }).first()).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('assinatura.png'), fullPage: true });

  await page.goto('/agenda');
  await page.getByRole('button', { name: 'Novo agendamento', exact: true }).click();
  await page.getByLabel('Oferta de atendimento').selectOption({ label: 'CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · SOU DEZ PILOTO E2E' });
  await page.getByLabel('Data do agendamento').fill(date);
  await page.getByLabel('Horário disponível').selectOption({ label: '08:00 · 1 vaga(s)' });
  await page.getByLabel('CPF do paciente').fill('168.995.350-09');
  await page.getByRole('button', { name: 'Localizar paciente' }).click();
  await expect(page.getByRole('status')).toContainText('Paciente encontrado');
  await page.getByLabel('Paciente informado sobre o uso dos dados para o agendamento.').check();
  await page.getByRole('button', { name: 'Confirmar agendamento interno' }).click();
  await expect(page.getByRole('heading', { name: 'Novo agendamento interno' })).not.toBeVisible();

  await page.getByRole('button', { name: 'Novo agendamento', exact: true }).click();
  await page.getByLabel('Oferta de atendimento').selectOption({ label: 'CONSULTA PILOTO E2E · PROFISSIONAL PILOTO E2E · SOU DEZ PILOTO E2E' });
  await page.getByLabel('Data do agendamento').fill(date);
  await page.getByLabel('Horário disponível').selectOption({ label: '08:30 · 1 vaga(s)' });
  await page.getByLabel('CPF do paciente').fill('111.444.777-35');
  await page.getByRole('button', { name: 'Localizar paciente' }).click();
  await page.getByLabel('Nome do paciente').fill('Paciente Comun E2E');
  await page.getByLabel('Telefone do paciente').fill('85999999999');
  await page.getByLabel('Paciente informado sobre o uso dos dados para o agendamento.').check();
  await page.getByRole('button', { name: 'Confirmar agendamento interno' }).click();
  await expect(page.getByText('não possui assinatura Sou DEZ ativa', { exact: false })).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('bloqueio.png'), fullPage: true });
  await page.getByRole('button', { name: 'Fechar', exact: true }).click();

  await page.goto('/caixa');
  await page.getByRole('button', { name: 'Abrir caixa' }).click();
  await expect(page.getByRole('heading', { name: /Caixa aberto · turno/ })).toBeVisible({ timeout: 30000 });

  await page.goto('/sou-dez');
  await page.getByRole('tab', { name: 'Mensalidades' }).click();
  await expect(page.getByText(/Competência \d{4}-\d{2}/)).toBeVisible();
  await page.getByRole('button', { name: 'Receber no caixa' }).click();
  await expect(page.getByText(`Competência ${nextCompetence}`, { exact: false })).toBeVisible();
  await expect(page.getByText('Paga', { exact: true }).first()).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('mensalidade-paga.png'), fullPage: true });

  expect(errors).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
