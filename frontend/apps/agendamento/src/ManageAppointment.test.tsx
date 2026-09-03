// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import { publicApi } from '@dez/api';
import { ManageAppointment } from './ManageAppointment';

vi.mock('@dez/api', () => ({ apiErrorMessage: (error: Error) => error.message, publicApi: {
  appointment: vi.fn(), managementAvailability: vi.fn().mockResolvedValue([]), cancel: vi.fn(), reschedule: vi.fn(),
} }));

const booking = { id: 'booking', service: { procedure_name: 'Consulta teste' }, price: '150.00', unit: { name: 'Messejana' }, agreement: { name: 'Particular' }, professional: { name: 'Profissional teste' }, scheduled_at: '2026-09-10T08:00:00-03:00', status: 'CONFIRMED', booking_mode: 'FIXED_SLOT', can_cancel: true, can_reschedule: true };
afterEach(() => { document.body.innerHTML = ''; vi.clearAllMocks(); });

async function mount(token = 'private-token') {
  (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
  const host = document.createElement('div'); document.body.append(host);
  const root = createRoot(host);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const render = async (credential: string) => {
    await act(async () => root.render(<QueryClientProvider client={client}><ManageAppointment id="booking" token={credential} /></QueryClientProvider>));
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  };
  await render(token);
  return { root, client, render };
}

test('link privado exibe reserva e exige confirmação antes de alterar', async () => {
  vi.mocked(publicApi.appointment).mockResolvedValue(booking as never);
  const { root, client } = await mount();
  expect(document.body.textContent).toContain('Gerenciar agendamento');
  expect(document.body.textContent).toContain('Consulta teste');
  const buttons = [...document.querySelectorAll('button')].filter((button) => /Confirmar remarcação|Cancelar agendamento/.test(button.textContent ?? ''));
  expect(buttons).toHaveLength(2);
  expect(buttons.every((button) => button.disabled)).toBe(true);
  expect(JSON.stringify(client.getQueryCache().getAll().map((query) => query.queryKey))).not.toContain('private-token');
  expect(document.body.textContent).not.toContain('private-token');
  await act(async () => root.unmount());
});

test('trocar credencial não reutiliza a reserva do link anterior', async () => {
  vi.mocked(publicApi.appointment).mockResolvedValueOnce(booking as never).mockRejectedValueOnce(new Error('404'));
  const { root, render } = await mount();
  await render('invalid-token');
  expect(document.body.textContent).toContain('Link inválido ou expirado');
  expect(document.body.textContent).not.toContain('Consulta teste');
  expect(document.querySelector('select')).toBeNull();
  await act(async () => root.unmount());
});

test('reserva cancelada não oferece novas alterações', async () => {
  vi.mocked(publicApi.appointment).mockResolvedValue({ ...booking, status: 'CANCELLED', can_cancel: false, can_reschedule: false } as never);
  const { root } = await mount();
  expect(document.body.textContent).toContain('Cancelado');
  expect(document.body.textContent).toContain('não pode mais ser alterado online');
  expect(document.querySelector('input[type="checkbox"]')).toBeNull();
  await act(async () => root.unmount());
});
