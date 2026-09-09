// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import MtPage from './MtPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    mtOptions: vi.fn(),
    mtDue: vi.fn(),
    mtAsos: vi.fn(),
    createMtAso: vi.fn(),
    updateMtAso: vi.fn(),
    cancelMtAso: vi.fn(),
    extendMtAso: vi.fn(),
    emitMtAso: vi.fn(),
    mtBatchSchedule: vi.fn(),
    mtCompanyReport: vi.fn(),
    lookupPatient: vi.fn(),
    serviceOfferings: vi.fn().mockResolvedValue([]),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Supervisora', email: 'sup@example.test', role: 'SUPERVISOR', roleLabel: 'Supervisor', permissions } as never;
}

const options = {
  agreements: [{ id: 'a-1', name: 'Metalúrgica S/A', legacy_id: '49', is_default: false }],
  risks: [{ id: 'r-1', name: 'Ruído' }],
  sectors: [{ id: 's-1', agreement: { id: 'a-1', name: 'Metalúrgica S/A' }, name: 'Produção', active: true }],
  functions: [{ id: 'f-1', name: 'Operador', sector: { id: 's-1', name: 'Produção' }, risk: { id: 'r-1', name: 'Ruído' }, active: true }],
};

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

test('vencimentos marca atrasados e permite prorrogar', async () => {
  vi.mocked(api.mtOptions).mockResolvedValue(options as never);
  vi.mocked(api.mtDue).mockResolvedValue({
    days: 30,
    items: [
      { id: 'aso-1', patient: { id: 'p-1', name: 'João Operador', cpf_last_four: null, birth_date: null }, agreement: { id: 'a-1', name: 'Metalúrgica S/A' }, sector: { id: 's-1', name: 'Produção' }, function: { id: 'f-1', name: 'Operador' }, risks: [], type: 'PERIODICO', type_label: 'Periódico', status: 'EMITIDO', status_label: 'Emitido', realized_at: null, expires_at: '2026-08-01', issued_at: null, appointment: null, report: null, notes: null, legacy_id: null, overdue: true, days_until_due: -20 },
    ],
  } as never);

  await render(<MtPage user={user(['mt.view', 'mt.manage'])} />);
  await waitForText('João Operador');
  expect(document.body.textContent).toContain('Vencido');
  expect(document.body.textContent).toContain('2026-08-01');
  expect(document.body.textContent).toContain('Prorrogar');
});

test('aba empresas lista setores funções e riscos', async () => {
  vi.mocked(api.mtOptions).mockResolvedValue(options as never);
  vi.mocked(api.mtDue).mockResolvedValue({ days: 30, items: [] } as never);

  await render(<MtPage user={user(['mt.view'])} />);
  await waitForText('Empresas');
  const empresas = [...document.querySelectorAll('[role="tab"]')].find((element) => element.textContent === 'Empresas') as HTMLButtonElement;
  await act(async () => empresas.click());
  await waitForText('Produção');
  expect(document.body.textContent).toContain('Operador');
  expect(document.body.textContent).toContain('Ruído');
  expect(document.body.textContent).not.toContain('Criar setor');
});
