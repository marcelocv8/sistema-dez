// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import BudgetsPage from './BudgetsPage';
import RoutinesPage from './RoutinesPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    catalog: vi.fn().mockResolvedValue({ data: [{ id: 'agr1', name: 'PARTICULAR M', active: true }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }),
    budgets: vi.fn().mockResolvedValue([]),
    budgetPriceOptions: vi.fn().mockResolvedValue([{ procedure_id: 'p1', procedure_name: 'CLINICA MEDICA', type: 'CONSULTATION', amount: '130.00' }]),
    createBudget: vi.fn(),
    sendBudget: vi.fn(),
    resolveBudget: vi.fn(),
    budgetPrescriptionUrl: vi.fn().mockReturnValue('http://x/prescription'),
    chatLookupPatient: vi.fn(),
    routines: vi.fn().mockResolvedValue({ date: '2026-09-08', scope: 'all', tasks: [] }),
    generateRoutines: vi.fn(),
    tickRoutine: vi.fn(),
    reopenRoutine: vi.fn(),
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

test('orçamentos: lista enviados com ação de resolver', async () => {
  vi.mocked(api.budgets).mockResolvedValue([{
    id: 'b9', patient: { id: 'pt1', name: 'Maria Orçamento' }, agreement_id: 'agr1', agreement: { id: 'agr1', name: 'PARTICULAR M' },
    status: 'ENVIADO', subtotal_gross: '260.00', has_prescription: true, sent_at: '2026-09-06T12:00:00Z',
    resolved_at: null, resolution_note: null, created_at: '2026-09-05T12:00:00Z',
    items: [{ id: 'i1', procedure_id: 'p1', procedure_name: 'CLINICA MEDICA', amount: '130.00', quantity: 2 }],
  }] as never);
  const root = await render(<BudgetsPage user={user(['budgets.view', 'budgets.manage'])} />);
  await waitForText('Maria Orçamento');
  expect(document.body.textContent).toContain('ENVIADO');
  expect(document.body.textContent).toContain('R$\u00a0260,00');
  expect(document.body.textContent).toContain('Resolver sim/não');
  expect(document.body.textContent).toContain('Prescrição');
  await act(async () => root.unmount());
});

test('rotinas: pendentes e concluídas com tick e reabrir', async () => {
  vi.mocked(api.routines).mockResolvedValue({
    date: '2026-09-08', scope: 'all',
    tasks: [
      { id: 't1', type: 'CONFIRMACAO', title: 'Ligar: confirmar consulta de amanhã: Maria Rotina', detail: 'Consulta de 08:00 de amanhã.', ref_type: 'appointments', ref_id: 'a1', assigned_to: null, assignee_name: null, due_date: '2026-09-08', status: 'PENDENTE', note: null, done_by: null, done_by_name: null, done_at: null },
      { id: 't2', type: 'ORCAMENTO_RETORNO', title: 'Orçamento sem resposta: buscar o sim/não: João', detail: null, ref_type: 'budgets', ref_id: 'b1', assigned_to: null, assignee_name: 'Caio', due_date: '2026-09-08', status: 'FEITO', note: 'Aceito por ligação', done_by: 2, done_by_name: 'Supervisor', done_at: '2026-09-08T12:00:00Z' },
    ],
  } as never);
  const root = await render(<RoutinesPage user={user(['routine.view', 'routine.tick', 'routine.manage'])} />);
  await waitForText('Ligar: confirmar consulta');
  expect(document.body.textContent).toContain('Pendentes (1)');
  expect(document.body.textContent).toContain('Concluídas (1)');
  expect(document.body.textContent).toContain('Crítica: Aceito por ligação');
  expect(document.body.textContent).toContain('Reabrir');
  await act(async () => root.unmount());
});
