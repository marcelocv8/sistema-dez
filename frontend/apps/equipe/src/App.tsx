import { lazy, Suspense } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { api, type CurrentUser } from '@dez/api';
import { LoadingState } from '@dez/ui';
import { AppShell } from './components/AppShell';
import { LoginPage } from './pages/LoginPage';

const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const AgendaPage = lazy(() => import('./pages/AgendaPage'));
const ReceptionPage = lazy(() => import('./pages/ReceptionPage'));
const ReportPage = lazy(() => import('./pages/ReportPage'));
const PricesPage = lazy(() => import('./pages/PricesPage'));
const CatalogPage = lazy(() => import('./pages/CatalogPage'));
const CashPage = lazy(() => import('./pages/CashPage'));
const RepassesPage = lazy(() => import('./pages/RepassesPage'));

export function App() {
  const queryClient = useQueryClient();
  const currentUser = useQuery({ queryKey: ['current-user'], queryFn: api.currentUser, retry: false });

  if (currentUser.isLoading) return <LoadingState label="Validando acesso" />;
  if (!currentUser.data) return <LoginPage onAuthenticated={(user: CurrentUser) => queryClient.setQueryData(['current-user'], user)} />;

  return <BrowserRouter><AppShell user={currentUser.data} onLogout={async () => { await api.logout(); queryClient.setQueryData(['current-user'], null); }}><Suspense fallback={<LoadingState label="Carregando módulo" />}><Routes>
    <Route path="/visao-geral" element={<DashboardPage />} />
    <Route path="/agenda" element={<AgendaPage user={currentUser.data} />} />
    <Route path="/recepcao" element={<ReceptionPage />} />
    <Route path="/laudos/:id?" element={<ReportPage />} />
    <Route path="/precos" element={<PricesPage />} />
    <Route path="/cadastros" element={<CatalogPage user={currentUser.data} />} />
    <Route path="/caixa" element={<CashPage user={currentUser.data} />} />
    <Route path="/repasses" element={<RepassesPage user={currentUser.data} />} />
    <Route path="*" element={<Navigate to="/visao-geral" replace />} />
  </Routes></Suspense></AppShell></BrowserRouter>;
}
