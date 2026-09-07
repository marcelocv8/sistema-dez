// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, test, vi } from 'vitest';
import ReceptionPage from './ReceptionPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    schedulingOptions: vi.fn().mockResolvedValue({ units: [{ id: 'unit', name: 'Messejana' }], links: [], prices: [] }),
    queue: vi.fn().mockResolvedValue({ date: '2026-09-04', queue: [], priorities: [{ code: 'GESTANTE', label: 'Gestante' }] }),
    appointments: vi.fn().mockResolvedValue({ data: { date: '2026-09-04', appointments: [] } }),
    queueHistory: vi.fn().mockResolvedValue([]),
    scheduleConflicts: vi.fn().mockResolvedValue([]),
    checkInAppointment: vi.fn(),
    callQueue: vi.fn(),
    reorderQueue: vi.fn(),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Recepcionista', email: 'rec@example.test', role: 'RECEPTION', roleLabel: 'Recepção', permissions } as never;
}

async function render(page: React.ReactElement, initialEntries = ['/recepcao?fila=1']) {
  (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => root.render(<QueryClientProvider client={client}><MemoryRouter initialEntries={initialEntries}>{page}</MemoryRouter></QueryClientProvider>));
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  return root;
}

async function waitForText(text: string) {
  for (let attempt = 0; attempt < 40; attempt++) {
    if (document.body.textContent?.includes(text)) return;
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 50)); });
  }
}

test('fila real mostra senha, prioridade sugerida e botão chamar', async () => {
  vi.mocked(api.queue).mockResolvedValueOnce({
    date: '2026-09-04',
    priorities: [{ code: 'GESTANTE', label: 'Gestante' }],
    queue: [{
      id: 'q1', appointment_id: 'a1', ticket: 'A002', suggested_position: 1, original_sequence: 2,
      priority_code: 'GESTANTE', priority_label: 'Gestante', priority_rank: 2, priority_reason: 'Gestante',
      status: 'WAITING', checked_in_at: '2026-09-04T11:00:00Z', called_at: null,
      patient_name: 'Maria Fila', payment_status: 'PENDING', payment_method: null,
      procedure_name: 'Clínica Médica', professional_name: 'Dr. Teste', unit_name: 'Messejana',
    }],
  } as never);
  const root = await render(<ReceptionPage user={user(['schedule.view', 'appointments.manage', 'queue.call'])} />);
  await waitForText('A002');
  expect(document.body.textContent).toContain('A002');
  expect(document.body.textContent).toContain('Maria Fila');
  expect(document.body.textContent).toContain('Gestante');
  expect(document.body.textContent).toContain('Aguardando pagamento');
  expect(document.body.textContent).toContain('Chamar');
  await act(async () => root.unmount());
});

test('aba check-in lista fichas confirmadas para dar entrada', async () => {
  vi.mocked(api.appointments).mockResolvedValueOnce({ data: { date: '2026-09-04', appointments: [{
    id: 'a9', scheduled_at: '2026-09-04T11:00:00Z', ends_at: '2026-09-04T11:30:00Z', status: 'CONFIRMED',
    patient_name: 'João Entrada', professional: { id: 'p', name: 'Dra. Teste' },
    procedure: { id: 'pr', name: 'Consulta', group_name: null }, price: '150.00',
    payment_status: 'PENDING', paid_at: null, payment_method_code: null, receipt_number: null, care_started_at: null,
  }] } } as never);
  const root = await render(<ReceptionPage user={user(['schedule.view', 'appointments.manage', 'queue.call'])} />, ['/recepcao']);
  await waitForText('João Entrada');
  expect(document.body.textContent).toContain('João Entrada');
  expect(document.body.textContent).toContain('Fazer check-in');
  await act(async () => root.unmount());
});
