// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import AttendancePage from './AttendancePage';
import ChatPage from './ChatPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    attendanceReport: vi.fn(),
    attendanceExport: vi.fn(),
    attendanceExitHour: vi.fn(),
    saveAttendanceExitHour: vi.fn(),
    teamUsers: vi.fn().mockResolvedValue([]),
    chatConversations: vi.fn(),
    chatParticipants: vi.fn().mockResolvedValue([]),
    chatMessages: vi.fn().mockResolvedValue([]),
    sendChatMessage: vi.fn(),
    markChatRead: vi.fn(),
    chatUnread: vi.fn().mockResolvedValue({ unread: 3 }),
    createChatConversation: vi.fn(),
    chatLookupPatient: vi.fn(),
  },
}));

afterEach(() => { document.body.innerHTML = ''; });

function user(permissions: string[]) {
  return { id: 'u', name: 'Supervisor', email: 'sup@example.test', role: 'SUPERVISOR', roleLabel: 'Supervisor', permissions } as never;
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

test('ponto mostra relogin sinalizado e exportação', async () => {
  vi.mocked(api.attendanceReport).mockResolvedValue({
    exit_hour: '17:00',
    days: [{ user_id: 1, user_name: 'Maria Recepção', roles: ['Recepção'], date: '2026-09-07', logins: ['07:00', '12:30', '17:10'], first_login: '07:00', last_login: '17:10', relogins: 2, relogin_after_exit: true, exit_hour: '17:00' }],
  } as never);
  const root = await render(<AttendancePage user={user(['attendance.view'])} />);
  await waitForText('Maria Recepção');
  expect(document.body.textContent).toContain('Relogin após a saída (2)');
  expect(document.body.textContent).toContain('Exportar CSV');
  await act(async () => root.unmount());
});

test('ponto sem permissão vê aviso exclusivo', async () => {
  const root = await render(<AttendancePage user={user(['schedule.view'])} />);
  expect(document.body.textContent).toContain('exclusivo de Admin e Supervisor');
  await act(async () => root.unmount());
});

test('bate-papo lista conversas com badge de não lidas e thread', async () => {
  vi.mocked(api.chatConversations).mockResolvedValue([{
    id: 'c1', type: 'DIRECT', title: null,
    participants: [{ id: 1, name: 'Maria Recepção', role: 'RECEPTION' }, { id: 2, name: 'Dr. Caio', role: 'DOCTOR' }],
    last_message_at: '2026-09-07T10:30:00Z', unread: 2,
  }] as never);
  vi.mocked(api.chatMessages).mockResolvedValue([
    { id: 'm1', author: { id: 1, name: 'Maria Recepção' }, body: 'Paciente X chegou, sala 3', created_at: '2026-09-07T10:29:00Z', patient: { id: 'p1', name: 'Paciente X' }, mine: false },
    { id: 'm2', author: { id: 2, name: 'Dr. Caio' }, body: 'Pode mandar entrar', created_at: '2026-09-07T10:30:00Z', patient: null, mine: true },
  ] as never);
  const root = await render(<ChatPage />);
  await waitForText('Paciente X chegou, sala 3');
  expect(document.body.textContent).toContain('Pode mandar entrar');
  expect(document.body.textContent).toContain('🩺 Paciente X');
  await act(async () => root.unmount());
});
