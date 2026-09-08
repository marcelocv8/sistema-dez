// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import LaudosPage from './LaudosPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    chatLookupPatient: vi.fn().mockResolvedValue({ id: 'pt1', name: 'Maria Laudo' }),
    reportHistory: vi.fn().mockResolvedValue([{
      id: 'r1', appointment_id: 'a1', patient_id: 'pt1', patient: { id: 'pt1', name: 'Maria Laudo', birth_date: '1990-05-10' },
      procedure: { id: 'p1', name: 'ECOCARDIOGRAMA' }, doctor: { id: 7, name: 'Dr. Emmanuel' }, template_id: null,
      status: 'FINALIZADO', content_html: '<p>ECO normal.</p>', adendo: null, finalized_at: '2026-09-07T12:00:00Z',
      retention_until: '2031-09-07', signed_manual: false, signed_manual_at: null, print_count: 1,
      last_printed_at: '2026-09-07T12:30:00Z', created_at: '2026-09-07T11:00:00Z', events: [],
    }]),
    appointments: vi.fn().mockResolvedValue({ date: '2026-09-08', appointments: [] }),
    reportTemplates: vi.fn().mockImplementation(async (filters: { legacy_type?: string }) => filters.legacy_type === 'LINHA'
      ? [{ id: 'lt1', legacy_type: 'LINHA', nome: 'BETA- HCG', doctor: null, procedure: null, legacy_procedure: null, preview: 'RESULTADO', content_html: '<p>RESULTADO</p>' }]
      : [{ id: 'tp1', legacy_type: 'LAUDO', nome: 'ECO PADRAO', doctor: null, procedure: null, legacy_procedure: null, preview: 'ECODOPPLER', content_html: null }]),
    createReport: vi.fn(),
    saveReport: vi.fn(),
    finalizeReport: vi.fn(),
    appendReport: vi.fn(),
    signReportManual: vi.fn(),
    printReport: vi.fn().mockResolvedValue({ print_html: '<html>ok</html>', print_count: 2 }),
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
  throw new Error(`Texto ausente: ${text}\n${document.body.textContent?.slice(0, 500)}`);
}

test('laudos: histórico do paciente por CPF com status e trilha', async () => {
  const root = await render(<LaudosPage user={user(['reports.view', 'reports.write'])} />);
  await act(async () => {
    const input = document.querySelector('input') as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
    setter.call(input, '111.444.777-35');
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await act(async () => {
    const button = [...document.querySelectorAll('button')].find((element) => element.textContent === 'Localizar');
    button?.click();
  });
  await waitForText('Maria Laudo');
  expect(document.body.textContent).toContain('ECOCARDIOGRAMA');
  expect(document.body.textContent).toContain('FINALIZADO');
  expect(document.body.textContent).toContain('1 impressão(ões)');

  await act(async () => {
    const open = [...document.querySelectorAll('button')].find((element) => element.textContent === 'Abrir');
    open?.click();
  });
  await waitForText('Laudo (finalizado)');
  expect(document.body.textContent).toContain('Imprimir (reimpressão #2)');
  expect(document.body.textContent).toContain('Adicionar adendo');
  await act(async () => root.unmount());
});
