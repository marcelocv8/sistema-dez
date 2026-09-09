// @vitest-environment jsdom
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, expect, test, vi } from 'vitest';
import WhatsAppPage from './WhatsAppPage';
import { api } from '@dez/api';

vi.mock('@dez/api', () => ({
  apiErrorMessage: (error: { message: string }) => error.message,
  api: {
    whatsappLines: vi.fn(),
    selectWhatsappLine: vi.fn(),
  },
}));

afterEach(() => { document.body.innerHTML = ''; vi.clearAllMocks(); });

function user(permissions: string[]) {
  return { id: 'u', name: 'Admin', email: 'admin@example.test', role: 'ADMIN', roleLabel: 'Administrador', permissions } as never;
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

test('desligado: painel explica e não lista linhas', async () => {
  vi.mocked(api.whatsappLines).mockResolvedValue({ enabled: false, lines: [], selected: null, error: null } as never);
  await render(<WhatsAppPage user={user(['team.manage'])} />);
  await waitForText('Integração desligada');
  expect(document.body.textContent).toContain('WHATSAPP_ENABLED=true');
  expect(document.body.textContent).not.toContain('Linha de envio');
});

test('linha conectada pode ser selecionada e reflete a escolha', async () => {
  let selectedLine: number | null = null;
  const lines = [
    { id: 7, numero: '5585888800010', status_saude: 'conectado', provedor: 'baileys' },
    { id: 8, numero: '5585888800011', status_saude: 'aguardando_pareamento', provedor: 'baileys' },
  ];
  vi.mocked(api.whatsappLines).mockImplementation(async () => ({ enabled: true, lines, selected: selectedLine, error: null } as never));
  vi.mocked(api.selectWhatsappLine).mockImplementation(async (id) => {
    selectedLine = id;
    return { selected: id } as never;
  });

  await render(<WhatsAppPage user={user(['team.manage'])} />);
  await waitForText('5585888800010');
  const useButtons = Array.from(document.body.querySelectorAll('button')).filter((button) => button.textContent === 'Usar esta linha');
  expect(useButtons).toHaveLength(2);
  expect((useButtons[1] as HTMLButtonElement).disabled).toBe(true);

  await act(async () => { (useButtons[0] as HTMLButtonElement).click(); });
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 40)); });
  expect(vi.mocked(api.selectWhatsappLine)).toHaveBeenCalledWith(7);
  await waitForText('Linha em uso');
});

test('appchat fora do ar: aviso amigável e sem botões de seleção', async () => {
  vi.mocked(api.whatsappLines).mockResolvedValue({ enabled: true, lines: [], selected: null, error: 'AppChat indisponível (500).' } as never);
  await render(<WhatsAppPage user={user(['team.manage'])} />);
  await waitForText('AppChat indisponível');
  expect(document.body.textContent).not.toContain('Usar esta linha');
});
