// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import RelatoriosPage from './RelatoriosPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    analytics: vi.fn(),
    analyticsExport: vi.fn(),
    mtOptions: vi.fn().mockResolvedValue({ agreements: [], risks: [], sectors: [], functions: [] }),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

const summary = {
  period: { from: '2026-08-09', to: '2026-09-07' },
  appointments: { scheduled: 12, done: 9, cancelled: 2, no_show: 1, no_show_rate: 10 },
  revenue: '1250.00',
  ticket_avg: '138.89',
  payment_mix: [{ method: 'PIX', total: 800, count: 6 }],
  production_by_doctor: [{ doctor_id: 'd-1', doctor: 'Dr. Emmanuel', appointments: 5, revenue: 700 }],
  revenue_by_agreement: [{ agreement_id: 'a-1', agreement: 'PARTICULAR', total: 1250, count: 9 }],
  cash: { break_total: '-12.50', sangrias: '300.00', payouts_paid: '150.00', payouts_open: '90.00' },
  b2b_open: { count: 2, aging: { a_vencer: 300, vencida_30: 150, vencida_60: 0, vencida_90: 0, vencida_90_mais: 0 } },
  queue: { avg_care_minutes: 34 },
  asos_due: 4,
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

test('relatórios mostra kpis produção mix e aging b2b com csv', async () => {
  vi.mocked(api.analytics).mockResolvedValue(summary as never);

  await render(<RelatoriosPage />);
  await waitForText('Produção por médico');
  expect(document.body.textContent).toContain('1250.00');
  expect(document.body.textContent).toContain('Dr. Emmanuel');
  expect(document.body.textContent).toContain('10%');
  expect(document.body.textContent).toContain('PIX');
  expect(document.body.textContent).toContain('Vencida até 30d');
  expect(document.body.textContent).toContain('ASOs a vencer');
  expect([...document.querySelectorAll('button')].filter(button => button.textContent?.startsWith('CSV '))).toHaveLength(4);
  expect(vi.mocked(api.analytics)).toHaveBeenCalled();
});

test('exportação transmite filtros e mostra falha da API', async () => {
  vi.mocked(api.analytics).mockResolvedValue(summary as never);
  vi.mocked(api.mtOptions).mockResolvedValue({ agreements: [{ id: 'a-1', name: 'Empresa teste' }], sectors: [], functions: [], risks: [] } as never);
  vi.mocked(api.analyticsExport).mockRejectedValueOnce(new Error('Sessão expirada'));
  const root = await render(<RelatoriosPage />);
  await act(async () => {
    const select = document.querySelector('select')!;
    select.value = 'a-1'; select.dispatchEvent(new Event('change', { bubbles: true }));
  });
  const dates = [...document.querySelectorAll<HTMLInputElement>('input[type="date"]')].map(input => input.value);
  await act(async () => [...document.querySelectorAll('button')].find(button => button.textContent === 'CSV receita')!.click());
  await waitForText('Sessão expirada');
  expect(api.analyticsExport).toHaveBeenCalledWith('revenue', { from: dates[0], to: dates[1], agreement_id: 'a-1' });
  expect(document.body.textContent).toContain('Sessão expirada');
  await act(async () => root.unmount());
});
