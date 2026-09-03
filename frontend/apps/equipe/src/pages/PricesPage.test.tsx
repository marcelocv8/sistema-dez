// @vitest-environment jsdom

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { api } from '@dez/api';
import PricesPage from './PricesPage';

vi.mock('@dez/api', () => ({
  api: {
    priceOptions: vi.fn().mockResolvedValue({
      agreements: [{ id: 'agreement-49', legacy_id: '49', name: 'PARTICULAR M', is_default: true }],
      groups: { CONSULTATION: ['CONSULTA'], EXAM: ['ULTRASSONOGRAFIA'] },
    }),
    prices: vi.fn().mockResolvedValue({ agreement_id: 'agreement-49', query: '', types: [] }),
  },
}));

afterEach(() => {
  document.body.innerHTML = '';
});

describe('PricesPage', () => {
  test('carrega a lista de preços ao entrar, sem exigir busca', async () => {
    (globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
    const host = document.createElement('div');
    document.body.append(host);
    const root = createRoot(host);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    await act(async () => {
      root.render(<QueryClientProvider client={client}><PricesPage /></QueryClientProvider>);
    });
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 50));
    });

    expect(document.querySelector('h1')?.textContent).toBe('Consulta de preços');
    expect(document.querySelector('.price-search input')).not.toBeNull();
    expect(document.body.textContent).toContain('PARTICULAR M');
    expect(document.body.textContent).not.toContain('Comece pela busca');
    expect(api.prices).toHaveBeenCalledWith({ q: '', type: 'CONSULTATION', agreement_id: 'agreement-49' });

    await act(async () => root.unmount());
  });
});
