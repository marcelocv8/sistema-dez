// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import RepassesPage from './RepassesPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    catalogLinkOptions: vi.fn().mockResolvedValue({ units: [], links: [], prices: [], procedures: [], professionals: [{ id: 'prof', name: 'Dr. Teste', legacy_id: null }] }),
    myProfessional: vi.fn().mockResolvedValue({ id: 'prof', name: 'Dr. Teste', commission_type: 'PERCENT', commission_value: '30.00' }),
    payouts: vi.fn().mockResolvedValue([]),
    payout: vi.fn(),
    generatePayout: vi.fn(),
    confirmPayout: vi.fn(),
    contestPayout: vi.fn(),
    adjustPayoutItem: vi.fn(),
    payPayout: vi.fn(),
    cashRegisters: vi.fn().mockResolvedValue([]),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Operador', email: 'op@example.test', role: 'MANAGER', roleLabel: 'Gerente', permissions } as never;
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

test('sem permissão de repasse vê aviso de sem acesso', async () => {
  const root = await render(<RepassesPage user={user(['prices.view'])} />);
  expect(document.body.textContent).toContain('Sem acesso aos repasses');
  await act(async () => root.unmount());
});

test('lista repasses e abre detalhe com confirmar e pagar', async () => {
  vi.mocked(api.payouts).mockResolvedValueOnce([{
    id: 'pay-1', professional_id: 'prof', professional: { id: 'prof', name: 'Dr. Teste' },
    period_from: '2026-09-01', period_to: '2026-09-04', status: 'CONFIRMED',
    subtotal_gross: '300.00', total_commission: '90.00', contest_note: null, proof_reference: null, items_count: 2,
  }] as never);
  vi.mocked(api.payout).mockResolvedValueOnce({
    id: 'pay-1', professional_id: 'prof', professional: { id: 'prof', name: 'Dr. Teste' },
    period_from: '2026-09-01', period_to: '2026-09-04', status: 'CONFIRMED',
    subtotal_gross: '300.00', total_commission: '90.00', contest_note: null, proof_reference: null,
    items: [{ id: 'it-1', appointment_id: 'a', gross_amount: '150.00', commission_amount: '45.00', rule: 'DEFAULT_PERCENT', appointment: { id: 'a', scheduled_at: '2026-09-04T11:00:00Z', serviceOffering: { procedure: { id: 'p', name: 'Consulta' } } } }],
  } as never);
  const root = await render(<RepassesPage user={user(['payouts.view', 'payouts.manage', 'payouts.confirm', 'payouts.pay'])} />);
  expect(document.body.textContent).toContain('Dr. Teste');
  expect(document.body.textContent).toContain('Gerar repasse do período');
  await act(async () => {
    (document.body.querySelector('.catalog-actions button') as HTMLButtonElement)?.click();
    for (let attempt = 0; attempt < 20 && !document.body.textContent?.includes('Exportar CSV'); attempt++) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
  });
  expect(document.body.textContent).toContain('Pagar repasse');
  expect(document.body.textContent).toContain('Exportar CSV');
  await act(async () => root.unmount());
});
