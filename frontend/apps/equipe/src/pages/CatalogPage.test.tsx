// @vitest-environment jsdom

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, test, vi } from 'vitest';
import CatalogPage from './CatalogPage';

vi.mock('@dez/api', () => ({
  api: {
    catalog: vi.fn().mockResolvedValue({
      data: [{ id: 'procedure-1', legacy_id: '145', name: 'US CALCANEO DIREITO', active: true, classification_status: 'ATIVO' }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    }),
  },
}));

afterEach(() => {
  document.body.innerHTML = '';
});

describe('CatalogPage', () => {
  test('recepção consulta cadastros sem controles de alteração', async () => {
    (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
    const host = document.createElement('div');
    document.body.append(host);
    const root = createRoot(host);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    await act(async () => {
      root.render(<QueryClientProvider client={client}><CatalogPage user={{
        id: '1', name: 'Recepção', email: 'recepcao@dez.test', role: 'RECEPTION', roleLabel: 'Recepção',
        permissions: ['prices.view', 'catalog.view', 'catalog-links.view'],
      }} /></QueryClientProvider>);
    });
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 30)); });

    expect(document.querySelector('h1')?.textContent).toBe('Cadastros e acessos');
    expect(document.body.textContent).toContain('US CALCANEO DIREITO');
    expect(document.body.textContent).not.toContain('Salvar cadastro');
    expect(document.body.textContent).not.toContain('Usuários');

    await act(async () => root.unmount());
  });
});
