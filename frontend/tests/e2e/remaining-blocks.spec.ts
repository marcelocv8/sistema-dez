import {expect,test} from '@playwright/test';
import fs from 'node:fs/promises';

test.beforeEach(() => { test.skip(!process.env.E2E_PASSWORD, 'Use credencial temporária e banco exclusivo de testes.'); });

test('CPF alterado exige nova identificação; ASO vincula atendimento e emite',async({page},testInfo)=>{
  await page.goto('/medicina-trabalho');
  await page.getByLabel('E-mail',{exact:true}).fill('admin-piloto@example.test');
  await page.getByLabel('Senha',{exact:true}).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button',{name:'Entrar',exact:true}).click();
  await page.getByRole('tab',{name:'ASOs',exact:true}).click();
  await page.getByLabel('CPF do funcionário').fill('11144477735');
  await page.getByRole('button',{name:'Localizar',exact:true}).click();
  await expect(page.getByText('Paciente localizado: Paciente Piloto E2E')).toBeVisible();
  await page.getByLabel('CPF do funcionário').fill('16899535009');
  await expect(page.getByText('Paciente localizado: Paciente Piloto E2E')).toHaveCount(0);
  await page.getByLabel('Empresa (convênio)').selectOption({label:'EMPRESA MT PILOTO E2E'});
  await expect(page.getByRole('button',{name:'Cadastrar ASO',exact:true})).toBeDisabled();
  await page.getByLabel('CPF do funcionário').fill('11144477735');
  await expect(page.getByRole('button',{name:'Cadastrar ASO',exact:true})).toBeDisabled();
  await page.getByRole('button',{name:'Localizar',exact:true}).click();
  await expect(page.getByText('Paciente localizado: Paciente Piloto E2E')).toBeVisible();
  await page.getByRole('button',{name:'Cadastrar ASO',exact:true}).click();
  const row=page.locator('tbody tr',{hasText:'Paciente Piloto E2E'}).first();
  await expect(row).toBeVisible();
  await expect(row.getByRole('button',{name:'Emitir laudo',exact:true})).toBeDisabled();
  await row.getByRole('button',{name:'Vincular atendimento',exact:true}).click();
  const select=page.getByRole('combobox',{name:'Atendimento do funcionário',exact:true});
  await expect(select.locator('option')).toHaveCount(2);
  await select.selectOption({index:1});
  await page.getByRole('button',{name:'Confirmar vínculo',exact:true}).click();
  await expect(row.getByRole('button',{name:'Emitir laudo',exact:true})).toBeEnabled();
  await row.getByRole('button',{name:'Emitir laudo',exact:true}).click();
  await expect(row).toContainText('RASCUNHO');
  await expect(row.getByRole('button',{name:'Emitir laudo',exact:true})).toBeDisabled();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBe(true);
  await testInfo.attach('aso-corrigido',{body:await page.screenshot(),contentType:'image/png'});
});

test('CSV baixa conteúdo autenticado da API e transmite os filtros',async({page})=>{
  await page.goto('/relatorios');
  await page.getByLabel('E-mail',{exact:true}).fill('admin-piloto@example.test');
  await page.getByLabel('Senha',{exact:true}).fill(process.env.E2E_PASSWORD!);
  await page.getByRole('button',{name:'Entrar',exact:true}).click();
  const received=page.waitForResponse(r=>r.url().includes('/api/v1/team/analytics/export?'));
  const downloaded=page.waitForEvent('download');
  await page.getByRole('button',{name:'CSV produção',exact:true}).click();
  const response=await received;
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('text/csv');
  expect(new URL(response.url()).searchParams.has('from')).toBe(true);
  const download=await downloaded;
  expect(download.suggestedFilename()).toBe('dez-production.csv');
  const content=await fs.readFile((await download.path())!,'utf8');
  expect(content).toContain('medico,atendimentos,receita');
  expect(content).not.toContain('<!doctype html>');
});
