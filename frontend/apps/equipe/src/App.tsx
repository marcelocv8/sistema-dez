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
const PricesPage = lazy(() => import('./pages/PricesPage'));
const CatalogPage = lazy(() => import('./pages/CatalogPage'));
const CashPage = lazy(() => import('./pages/CashPage'));
const B2bPage = lazy(() => import('./pages/B2bPage'));
const AttendancePage = lazy(() => import('./pages/AttendancePage'));
const ChatPage = lazy(() => import('./pages/ChatPage'));
const LaudosPage = lazy(() => import('./pages/LaudosPage'));
const ProntuarioPage = lazy(() => import('./pages/ProntuarioPage'));
const BudgetsPage = lazy(() => import('./pages/BudgetsPage'));
const RoutinesPage = lazy(() => import('./pages/RoutinesPage'));
const RepassesPage = lazy(() => import('./pages/RepassesPage'));
const SouDezPage = lazy(() => import('./pages/SouDezPage'));
const MtPage = lazy(() => import('./pages/MtPage'));
const RelatoriosPage = lazy(() => import('./pages/RelatoriosPage'));

export function App() {
  const queryClient = useQueryClient();
  const currentUser = useQuery({ queryKey: ['current-user'], queryFn: api.currentUser, retry: false });

  if (currentUser.isLoading) return <LoadingState label="Validando acesso" />;
  if (!currentUser.data) return <LoginPage onAuthenticated={(user: CurrentUser) => queryClient.setQueryData(['current-user'], user)} />;

  return <BrowserRouter><AppShell user={currentUser.data} onLogout={async () => { await api.logout(); queryClient.setQueryData(['current-user'], null); }}><Suspense fallback={<LoadingState label="Carregando módulo" />}><Routes>
    <Route path="/visao-geral" element={<DashboardPage />} />
    <Route path="/agenda" element={<AgendaPage user={currentUser.data} />} />
    <Route path="/recepcao" element={<ReceptionPage user={currentUser.data} />} />
    <Route path="/laudos" element={<LaudosPage user={currentUser.data} />} />
    <Route path="/prontuario" element={<ProntuarioPage user={currentUser.data} />} />
    <Route path="/precos" element={<PricesPage />} />
    <Route path="/cadastros" element={<CatalogPage user={currentUser.data} />} />
    <Route path="/caixa" element={<CashPage user={currentUser.data} />} />
    <Route path="/faturamento-b2b" element={<B2bPage user={currentUser.data} />} />
    <Route path="/repasses" element={<RepassesPage user={currentUser.data} />} />
    <Route path="/ponto" element={<AttendancePage user={currentUser.data} />} />
    <Route path="/batepapo" element={<ChatPage />} />
    <Route path="/orcamentos" element={<BudgetsPage user={currentUser.data} />} />
    <Route path="/rotinas" element={<RoutinesPage user={currentUser.data} />} />
    <Route path="/sou-dez" element={<SouDezPage user={currentUser.data} />} />
    <Route path="/medicina-trabalho" element={<MtPage user={currentUser.data} />} />
    <Route path="/relatorios" element={<RelatoriosPage />} />
    <Route path="*" element={<Navigate to="/visao-geral" replace />} />
  </Routes></Suspense></AppShell></BrowserRouter>;
}
