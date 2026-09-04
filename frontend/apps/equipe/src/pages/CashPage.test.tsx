// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import CashPage from './CashPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    schedulingOptions: vi.fn().mockResolvedValue({ units: [{ id: 'unit', name: 'Messejana' }], links: [], prices: [] }),
    currentCashRegister: vi.fn().mockResolvedValue(null),
    cashRegisters: vi.fn().mockResolvedValue([]),
    cashDailyReport: vi.fn().mockResolvedValue({ date: '2026-09-04', unit_id: 'unit', registers: [], totals: { expected_cash: '0.00', counted_cash: '0.00', cash_difference: '0.00', sangria_total: '0.00', void_total: '0.00' }, receipt_gaps: [] }),
    appointments: vi.fn().mockResolvedValue({ data: { date: '2026-09-04', appointments: [] } }),
    openCashRegister: vi.fn(),
    closeCashRegister: vi.fn(),
    closeCashBatch: vi.fn(),
    createSangria: vi.fn(),
    voidAppointmentPayment: vi.fn(),
    checkoutAppointment: vi.fn(),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Operador', email: 'op@example.test', role: 'CASHIER', roleLabel: 'Caixa', permissions } as never;
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

test('recepção sem permissão de caixa vê aviso de sem acesso', async () => {
  const root = await render(<CashPage user={user(['prices.view'])} />);
  expect(document.body.textContent).toContain('Sem acesso ao caixa');
  await act(async () => root.unmount());
});

test('caixa sem registro aberto vê formulário de abertura', async () => {
  const root = await render(<CashPage user={user(['cash.view', 'cash.operate', 'cash.close'])} />);
  expect(document.body.textContent).toContain('Abrir caixa do dia');
  expect(document.body.textContent).toContain('Fundo de troco');
  await act(async () => root.unmount());
});

test('caixa aberto mostra resumo e fichas pendentes', async () => {
  vi.mocked(api.currentCashRegister).mockResolvedValueOnce({
    id: 'r', unit_id: 'unit', shift: 'UNICO', float_amount: '100.00', counted_cash: null, cash_difference: null, close_note: null, status: 'ABERTO',
    opened_at: '2026-09-04T10:00:00Z', closed_at: null, last_receipt_number: 1,
    summary: { receipt_count: 1, by_method: [{ method: 'ESPECIE', name: 'Espécie', count: 1, gross: '150.00', fee: '0.00', net: '150.00' }], float_amount: '100.00', expected_cash: '250.00', especie_in: '150.00', sangria_total: '0.00', void_total: '0.00' },
  } as never);
  vi.mocked(api.appointments).mockResolvedValueOnce({ data: { date: '2026-09-04', appointments: [{
    id: 'a', scheduled_at: '2026-09-04T11:00:00Z', ends_at: '2026-09-04T11:30:00Z', status: 'CONFIRMED',
    patient_name: 'Paciente Caixa', professional: { id: 'p', name: 'Profissional' },
    procedure: { id: 'pr', name: 'Consulta', group_name: null }, price: '150.00',
    payment_status: 'PENDING', paid_at: null, payment_method_code: null, receipt_number: null, care_started_at: null,
  }] } } as never);
  const root = await render(<CashPage user={user(['cash.view', 'cash.operate', 'cash.close'])} />);
  expect(document.body.textContent).toContain('Dinheiro esperado no cofre');
  expect(document.body.textContent).toContain('Paciente Caixa');
  expect(document.body.textContent).toContain('Pendente');
  expect(document.body.textContent).toContain('Dinheiro contado no cofre');
  expect(document.body.textContent).toContain('Sangria em dinheiro');
  expect(document.body.textContent).toContain('Fechamento consolidado');
  expect(document.body.textContent).toContain('Relatório diário');
  await act(async () => root.unmount());
});

test('supervisor vê estorno de fichas pagas', async () => {
  vi.mocked(api.currentCashRegister).mockResolvedValueOnce({
    id: 'r', unit_id: 'unit', shift: 'UNICO', float_amount: '100.00', counted_cash: null, cash_difference: null, close_note: null, status: 'ABERTO',
    opened_at: '2026-09-04T10:00:00Z', closed_at: null, last_receipt_number: 1,
    summary: { receipt_count: 1, by_method: [], float_amount: '100.00', expected_cash: '100.00', especie_in: '0.00', sangria_total: '0.00', void_total: '0.00' },
  } as never);
  vi.mocked(api.appointments).mockResolvedValueOnce({ data: { date: '2026-09-04', appointments: [] } } as never);
  vi.mocked(api.appointments).mockResolvedValueOnce({ data: { date: '2026-09-04', appointments: [{
    id: 'paid-1', scheduled_at: '2026-09-04T11:00:00Z', ends_at: '2026-09-04T11:30:00Z', status: 'CONFIRMED',
    patient_name: 'Paciente Pago', professional: null,
    procedure: { id: 'pr', name: 'Consulta', group_name: null }, price: '150.00',
    payment_status: 'PAID', paid_at: '2026-09-04T11:05:00Z', payment_method_code: 'PIX', receipt_number: 1, care_started_at: null,
  }] } } as never);
  const root = await render(<CashPage user={user(['cash.view', 'cash.operate', 'cash.close', 'discounts.validate'])} />);
  expect(document.body.textContent).toContain('estorno do supervisor');
  expect(document.body.textContent).toContain('Paciente Pago');
  await act(async () => root.unmount());
});
