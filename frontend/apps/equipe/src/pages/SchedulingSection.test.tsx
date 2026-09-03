// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import { SchedulingSection } from './SchedulingSection';
import { AppointmentEditor } from './AppointmentEditor';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    serviceOfferings: vi.fn().mockResolvedValue([]),
    schedulingOptions: vi.fn().mockResolvedValue({ units: [], links: [], prices: [] }),
    teamAvailability: vi.fn().mockResolvedValue([]),
    setOfferingVisibility: vi.fn(),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

test('pausar uma oferta fora do ar exige só um clique, sem formulário de publicação', async () => {
  vi.mocked(api.serviceOfferings).mockResolvedValueOnce([{ id: 'offer', procedure: { name: 'Consulta teste' }, professional: { name: 'Profissional' }, agreement: { name: 'Particular' }, unit: { name: 'Messejana' }, price: '150.00', active: true, online_enabled: false, online_paused: false, updated_at: '2026-09-03T10:00:00-03:00', booking_mode: 'FIXED_SLOT', schedule_windows: [], schedule_exceptions: [], catalog_link_id: '', procedure_price_id: '' }] as never);
  (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => root.render(<QueryClientProvider client={client}><SchedulingSection /></QueryClientProvider>));
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  await act(async () => [...document.querySelectorAll('button')].find((button) => button.textContent === 'Configurar')!.click());
  expect(document.body.textContent).toContain('Oferta fora do site');
  expect([...document.querySelectorAll('button')].find((button) => button.textContent === 'Pausar no site')).toBeTruthy();
  expect(api.setOfferingVisibility).not.toHaveBeenCalled();
  await act(async () => root.unmount());
});

test('ofertas visíveis mostram botão de pausar e preço atualizado', async () => {
  vi.mocked(api.serviceOfferings).mockResolvedValueOnce([{ id: 'offer', procedure: { name: 'Consulta teste' }, professional: { name: 'Profissional' }, agreement: { name: 'Particular' }, unit: { name: 'Messejana' }, price: '150.00', active: true, online_enabled: true, online_paused: false, updated_at: '2026-09-03T10:00:00-03:00', booking_mode: 'FIXED_SLOT', schedule_windows: [], schedule_exceptions: [], catalog_link_id: '', procedure_price_id: '' }] as never);
  (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => root.render(<QueryClientProvider client={client}><SchedulingSection /></QueryClientProvider>));
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  await act(async () => [...document.querySelectorAll('button')].find((button) => button.textContent === 'Configurar')!.click());
  expect(document.body.textContent).toContain('Visível no site');
  expect([...document.querySelectorAll('button')].find((button) => button.textContent === 'Pausar no site')).toBeTruthy();
  await act(async () => root.unmount());
});

test('ofertas vazias orientam o Admin a validar vínculo e não publicam online', async () => {
  (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => root.render(<QueryClientProvider client={client}><SchedulingSection /></QueryClientProvider>));
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  expect(document.body.textContent).toContain('Nenhuma oferta configurada');
  expect(document.body.textContent).toContain('aparecem sozinhas no site');
  expect((document.querySelector('button[type="submit"]') as HTMLButtonElement).disabled).toBe(true);
  await act(async () => root.unmount());
});

test('novo agendamento exige oferta, vaga e informação de privacidade', async () => {
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  await act(async () => root.render(<QueryClientProvider client={client}><AppointmentEditor initialDate="2026-09-07" offerings={[]} onClose={() => undefined} onSaved={async () => undefined} /></QueryClientProvider>));
  expect(document.body.textContent).toContain('Novo agendamento interno');
  expect(document.body.textContent).toContain('Localizar paciente');
  expect((document.querySelector('button[type="submit"]') as HTMLButtonElement).disabled).toBe(true);
  expect(document.querySelector('input[type="checkbox"]')?.hasAttribute('required')).toBe(true);
  await act(async () => root.unmount());
});
