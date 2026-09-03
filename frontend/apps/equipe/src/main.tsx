import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { enableMocking } from '@dez/api';
import '@dez/ui/styles.css';
import './styles.css';
import { App } from './App';

const queryClient = new QueryClient({ defaultOptions: { queries: { staleTime: 30_000, retry: 1 } } });

enableMocking().finally(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode><QueryClientProvider client={queryClient}><App /></QueryClientProvider></StrictMode>,
  );
});
