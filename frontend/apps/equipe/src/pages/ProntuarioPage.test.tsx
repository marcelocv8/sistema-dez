// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import ProntuarioPage from './ProntuarioPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    chatLookupPatient: vi.fn().mockResolvedValue({ id: 'pt1', name: 'Maria Prontuário' }),
    prontuarioTimeline: vi.fn().mockResolvedValue({
      patient: { id: 'pt1', name: 'Maria Prontuário', birth_date: '1980-03-12' },
      alerts: [
        { id: 'al1', kind: 'ALERGIA', title: 'Penicilina', detail: null, active: true, created_by: { id: 1, name: 'Admin' }, deactivated_by: null, deactivated_at: null, deactivation_reason: null, created_at: '2026-09-07T10:00:00Z' },
        { id: 'al2', kind: 'CONDICAO', title: 'Hipertensão', detail: null, active: false, created_by: { id: 1, name: 'Admin' }, deactivated_by: { id: 2, name: 'Dr. X' }, deactivated_at: '2026-09-06T10:00:00Z', deactivation_reason: 'revisada', created_at: '2026-09-05T10:00:00Z' },
      ],
      notes: [{ id: 'n1', appointment_id: 'a1', kind: 'EVOLUCAO', content_html: '<p>Cefaleia tensional.</p>', doctor: { id: 7, name: 'Dr. Emmanuel' }, created_by: { id: 2, name: 'Dr. Emmanuel' }, updated_by: null, retention_until: '2046-09-07', created_at: '2026-09-07T11:00:00Z', updated_at: null }],
      reports: [{ id: 'r1', procedure: { id: 'p1', name: 'ECOCARDIOGRAMA' }, doctor: { id: 7, name: 'Dr. Emmanuel' }, status: 'FINALIZADO', signed_manual: true, print_count: 2, portal_published: true, finalized_at: '2026-09-07T12:00:00Z', created_at: '2026-09-07T11:00:00Z' }],
      appointments: [{ id: 'a1', scheduled_at: '2026-09-07T12:00:00Z', status: 'DONE', kind: 'NEW', origin: 'INTERNAL', procedure: { id: 'p1', name: 'CLINICA MEDICA' }, professional: { id: 'pr1', name: 'Dr. Emmanuel' }, unit: { id: 'u1', name: 'MESSEJANA' }, payment_status: 'PAID', payment_method: { id: 1, name: 'Espécie' }, receipt_number: 42 }],
    }),
    aiStatus: vi.fn().mockResolvedValue({ enabled: false, model: 'deepseek-chat' }),
    createProntuarioNote: vi.fn(),
    updateProntuarioNote: vi.fn(),
    addProntuarioAlert: vi.fn(),
    deactivateProntuarioAlert: vi.fn(),
    aiSuggest: vi.fn(),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Dr. Emmanuel', email: 'dr@example.test', role: 'DOCTOR', roleLabel: 'Médico', permissions } as never;
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
  throw new Error(`Texto ausente: ${text}\n${document.body.textContent?.slice(0, 400)}`);
}

async function typeCpf(value: string) {
  await act(async () => {
    const input = document.querySelector('input') as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
    setter.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await act(async () => {
    const button = [...document.querySelectorAll('button')].find((element) => element.textContent === 'Localizar');
    button?.click();
  });
}

test('prontuário: alerta de alergia em destaque, notas e trilha preservada', async () => {
  const root = await render(<ProntuarioPage user={user(['prontuario.view', 'prontuario.write'])} />);
  await typeCpf('111.444.777-35');
  await waitForText('Maria Prontuário');
  expect(document.body.textContent).toContain('Penicilina');
  expect(document.body.textContent).toContain('Hipertensão');
  expect(document.body.textContent).toContain('Inativos (1)');
  expect(document.body.textContent).toContain('Cefaleia tensional');
  expect(document.body.textContent).toContain('EVOLUCAO'.replace('EVOLUCAO', 'Evolução'));
  expect(document.body.textContent).toContain('recibo 42');
  expect(document.body.textContent).toContain('assinado');
  await act(async () => root.unmount());
});

test('prontuário: IA oculta sem key e bloqueada para quem não tem permissão', async () => {
  const root = await render(<ProntuarioPage user={user(['prontuario.view'])} />);
  await typeCpf('111.444.777-35');
  await waitForText('Maria Prontuário');
  expect(document.body.textContent).not.toContain('Sugerir conduta (IA)');
  expect(document.body.textContent).not.toContain('Registrar nota');
  await act(async () => root.unmount());
});


test('prontuário: resposta de IA do paciente anterior não aparece nem pode ser inserida', async () => {
  vi.mocked(api.aiStatus).mockResolvedValue({ enabled: true, model: 'test' });
  let finish!: (result: never) => void;
  vi.mocked(api.aiSuggest).mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
  const root = await render(<ProntuarioPage user={user(['prontuario.view', 'prontuario.write', 'ai.use'])} />);
  await typeCpf('11144477735'); await waitForText('Maria Prontuário'); await waitForText('Sugerir conduta (IA)');
  await act(async () => {
    const input = [...document.querySelectorAll('input')].find((input) => input.placeholder.startsWith('Ex.: conduta'))!;
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, 'Pergunta clínica A');
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await act(async () => { [...document.querySelectorAll('button')].find((button) => button.textContent === 'Sugerir conduta (IA)')!.click(); });
  vi.mocked(api.chatLookupPatient).mockResolvedValueOnce({ id: 'pt2', name: 'Paciente B' });
  await typeCpf('52998224725'); await waitForText('Paciente B');
  await act(async () => { finish({ suggestion_html: '<p>Exclusivo de A</p>', ai_request_id: 'ai1', model: 'test' } as never); });
  expect(document.body.textContent).not.toContain('Exclusivo de A');
  expect(document.body.textContent).not.toContain('Inserir no prontuário');
  expect(document.querySelector('[aria-label="Nova nota do prontuário"]')?.innerHTML).toBe('');
  await act(async () => root.unmount());
});
