import type { ReactNode } from 'react';
import { useState } from 'react';
import { NavLink } from 'react-router-dom';
import { Activity, Banknote, Bell, CalendarDays, ChevronLeft, ClipboardList, FileText, LayoutDashboard, LogOut, Menu, MessageCircle, Search, Settings, Tags, Users, WalletCards, X } from 'lucide-react';
import type { CurrentUser } from '@dez/api';
import { BrandLogo, IconButton } from '@dez/ui';

const sections = [
  { label: 'Operação', items: [
    { to: '/visao-geral', label: 'Visão geral', icon: LayoutDashboard },
    { to: '/agenda', label: 'Agenda', icon: CalendarDays },
    { to: '/recepcao', label: 'Recepção', icon: Users },
    { to: '/precos', label: 'Consulta de preços', icon: Tags, permission: 'prices.view' },
    { to: '/caixa', label: 'Caixa', icon: Banknote, permission: 'cash.view' },
    { to: '/recepcao?fila=1', label: 'Fila de espera', icon: ClipboardList },
  ] },
  { label: 'Clínico', items: [
    { to: '/laudos/1', label: 'Laudos', icon: FileText },
    { to: '/laudos/1', label: 'Atendimentos', icon: Activity },
  ] },
  { label: 'Gestão', items: [
    { to: '/cadastros', label: 'Cadastros e acessos', icon: Settings, permission: 'catalog.view' },
    { to: '/repasses', label: 'Repasses médicos', icon: WalletCards, permission: 'payouts.view' },
    { to: '/visao-geral?financeiro=1', label: 'Financeiro', icon: WalletCards },
    { to: '/visao-geral?mensagens=1', label: 'Bate-papo', icon: MessageCircle },
    { to: '/visao-geral?config=1', label: 'Configurações', icon: Settings },
  ] },
];

export function AppShell({ children, user, onLogout }: { children: ReactNode; user: CurrentUser; onLogout: () => Promise<void> }) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  return <div className={`app-shell ${collapsed ? 'app-shell--collapsed' : ''}`}>
    <aside className={`app-sidebar ${mobileOpen ? 'app-sidebar--open' : ''}`}>
      <div className="app-brand-row"><BrandLogo /><IconButton label="Fechar menu" className="mobile-only" onClick={() => setMobileOpen(false)}><X size={18} /></IconButton></div>
      <nav aria-label="Navegação principal">{sections.map(section => <div className="app-nav-section" key={section.label}><span className="app-nav-label">{section.label}</span>{section.items.filter((item) => !('permission' in item) || user.permissions.includes(item.permission as never)).map(({ to, label, icon: Icon }) => <NavLink key={`${label}-${to}`} to={to} className={({ isActive }) => `app-nav-link ${isActive && !to.includes('?') ? 'app-nav-link--active' : ''}`} onClick={() => setMobileOpen(false)}><Icon size={18} aria-hidden="true" /><span>{label}</span></NavLink>)}</div>)}</nav>
      <button className="app-collapse" onClick={() => setCollapsed(value => !value)}><ChevronLeft size={17} /><span>Recolher menu</span></button>
    </aside>
    {mobileOpen ? <button className="app-backdrop" aria-label="Fechar menu" onClick={() => setMobileOpen(false)} /> : null}
    <div className="app-workspace">
      <header className="app-topbar">
        <IconButton label="Abrir menu" className="mobile-only" onClick={() => setMobileOpen(true)}><Menu size={19} /></IconButton>
        <div className="app-search"><Search size={17} /><input aria-label="Busca global" placeholder="Buscar pacientes, agendamentos, prontuários..." /><kbd>Ctrl K</kbd></div>
        <div className="app-top-actions"><IconButton label="Notificações"><Bell size={18} /><span className="app-notification-dot">3</span></IconButton><div className="app-user"><span className="app-avatar">{user.name.split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</span><span><strong>{user.name}</strong><small>{user.roleLabel}</small></span></div><IconButton label="Sair" onClick={() => void onLogout()}><LogOut size={18} /></IconButton></div>
      </header>
      <main className="app-content">{children}</main>
    </div>
  </div>;
}
