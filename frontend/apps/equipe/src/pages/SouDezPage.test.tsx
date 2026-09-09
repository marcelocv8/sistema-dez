// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import SouDezPage from './SouDezPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    loyaltyPlans: vi.fn().mockResolvedValue([{ id: 'plan1', name: 'SOU DEZ COMPLETO', monthly_fee: '89.90', due_day: 10, active: true, agreement: { id: 'agr1', name: 'SOU DEZ COMPLETO', is_default: false }, active_subscriptions_count: 1 }]),
    loyaltySubscriptions: vi.fn().mockResolvedValue([]),
    loyaltyDues: vi.fn().mockResolvedValue([]),
    loyaltyEligibility: vi.fn(),
    createLoyaltySubscription: vi.fn(),
    updateLoyaltySubscription: vi.fn(),
    collectLoyaltyDue: vi.fn(),
    createLoyaltyPlan: vi.fn(),
    updateLoyaltyPlan: vi.fn(),
    catalog: vi.fn().mockResolvedValue({ data: [{ id: 'agr1', name: 'SOU DEZ COMPLETO', active: true, is_default: false }], meta: {} }),
    schedulingOptions: vi.fn().mockResolvedValue({ units: [{ id: 'unit1', name: 'Messejana', operational_enabled: true }] }),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Recepção', email: 'rec@example.test', role: 'RECEPTION', roleLabel: 'Recepção', permissions } as never;
}

async function render(page: React.ReactElement) {
  (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => root.render(<QueryClientProvider client={client}>{page}</QueryClientProvider>));
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  return root;
}

async function waitForText(text: string) {
  for (let attempt = 0; attempt < 40; attempt++) {
    if (document.body.textContent?.includes(text)) return;
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 50)); });
  }
}

test('sou dez: lista assinantes com dependentes e mensalidade pendente', async () => {
  vi.mocked(api.loyaltySubscriptions).mockResolvedValue([{
    id: 'sub1', status: 'ACTIVE', started_at: '2026-09-08', ended_at: null, notes: null,
    plan: { id: 'plan1', name: 'SOU DEZ COMPLETO', monthly_fee: '89.90', due_day: 10, agreement: { id: 'agr1', name: 'SOU DEZ COMPLETO' } },
    patient: { id: 'pt1', name: 'Maria Sou DEZ', cpf_last_four: '5009' },
    dependents: [{ id: 'dep1', active: true, patient: { id: 'pt2', name: 'João Sou DEZ', cpf_last_four: '7697' } }],
    dues: [{ id: 'due1', competence: '2026-09-01', amount: '89.90', due_date: '2026-09-10', status: 'PENDENTE', overdue: false, paid_at: null }],
    overdue_count: 0,
  }] as never);
  const root = await render(<SouDezPage user={user(['soudez.view', 'soudez.manage'])} />);
  await waitForText('Maria Sou DEZ');
  expect(document.body.textContent).toContain('mensalidade pendente');
  await act(async () => { [...document.querySelectorAll('button')].find((button) => button.textContent === 'Detalhes')!.click(); });
  expect(document.body.textContent).toContain('João Sou DEZ');
  await act(async () => { [...document.querySelectorAll('button')].find((button) => button.textContent === 'Cancelar assinatura')!.click(); });
  expect(api.updateLoyaltySubscription).toHaveBeenCalledWith('sub1', { status: 'CANCELED' });
  await act(async () => root.unmount());
});

test('sou dez: recebe mensalidade em atraso no caixa', async () => {
  vi.mocked(api.loyaltyDues).mockResolvedValue([{
    id: 'due9', competence: '2026-08-01', amount: '89.90', due_date: '2026-08-10', status: 'PENDENTE', overdue: true, paid_at: null,
    subscription: { id: 'sub1', plan_name: 'SOU DEZ COMPLETO', patient: { id: 'pt1', name: 'Maria Sou DEZ', cpf_last_four: '5009' } },
  }] as never);
  const root = await render(<SouDezPage user={user(['soudez.view', 'soudez.manage', 'cash.operate'])} />);
  await act(async () => { [...document.querySelectorAll('button')].find((button) => button.textContent === 'Mensalidades')!.click(); });
  await waitForText('Em atraso');
  expect(document.body.textContent).toContain('Maria Sou DEZ');
  await act(async () => { [...document.querySelectorAll('button')].find((button) => button.textContent === 'Receber no caixa')!.click(); });
  expect(api.collectLoyaltyDue).toHaveBeenCalledWith('due9', { payment_method_code: 'ESPECIE', unit_id: 'unit1' });
  await act(async () => root.unmount());
});
