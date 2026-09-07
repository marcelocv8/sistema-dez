// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import B2bPage from './B2bPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    catalog: vi.fn().mockResolvedValue({ data: [{ id: 'c1', name: 'Empresa Alfa', active: true }], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }),
    b2bInvoices: vi.fn().mockResolvedValue([]),
    generateB2bInvoice: vi.fn(),
    sendB2bInvoice: vi.fn(),
    settleB2bInvoice: vi.fn(),
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

async function waitForText(text: string) {
  for (let attempt = 0; attempt < 40; attempt++) {
    if (document.body.textContent?.includes(text)) return;
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 50)); });
  }
}

test('perfil sem b2b.view vê aviso de sem acesso', async () => {
  const root = await render(<B2bPage user={user(['prices.view'])} />);
  expect(document.body.textContent).toContain('Sem acesso ao faturamento B2B');
  await act(async () => root.unmount());
});

test('fatura vencida aparece com alerta de bloqueio', async () => {
  vi.mocked(api.b2bInvoices).mockResolvedValueOnce([{
    id: 'f1', agreement: { id: 'c1', name: 'Empresa Alfa' }, competence: '2026-08-01',
    status: 'ENVIADA', computed_status: 'VENCIDA', overdue: true,
    subtotal_gross: '300.00', settled_amount: '0.00', remaining_amount: '300.00',
    issued_at: '2026-08-01T10:00:00Z', due_at: '2026-08-11', sent_at: '2026-08-02T10:00:00Z',
    settled_at: null, proof_reference: null, items_count: 2, items: [],
  }] as never);
  const root = await render(<B2bPage user={user(['b2b.view', 'b2b.manage', 'b2b.settle'])} />);
  await waitForText('Vencida em aberto');
  expect(document.body.textContent).toContain('vencida(s) em aberto');
  expect(document.body.textContent).toContain('Vencida em aberto');
  expect(document.body.textContent).toContain('Empresa Alfa');
  await act(async () => root.unmount());
});
