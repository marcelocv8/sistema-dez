import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Link2, Pencil, Plus, Search, ShieldCheck, UserCog } from 'lucide-react';
import {
  api,
  type AuditEntry,
  type CatalogItem,
  type CatalogLinkInput,
  type CatalogResource,
  type CurrentUser,
  type ImportIssue,
  type TeamUser,
} from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, SelectField, StatusPill, TextField } from '@dez/ui';
import { SchedulingSection } from './SchedulingSection';

type Section = 'catalog' | 'links' | 'issues' | 'users' | 'audit' | 'scheduling';
type Field = { key: string; label: string; type?: 'text' | 'number' | 'boolean' | 'select'; options?: Array<[string, string]> };

const resourceLabels: Record<CatalogResource, string> = {
  procedures: 'Procedimentos',
  agreements: 'Convênios',
  professionals: 'Profissionais',
  units: 'Unidades',
};

const resourceFields: Record<CatalogResource, Field[]> = {
  units: [
    { key: 'legacy_id', label: 'Código legado' }, { key: 'name', label: 'Nome' },
    { key: 'timezone', label: 'Fuso horário' }, { key: 'cnpj', label: 'CNPJ (14 dígitos)' },
    { key: 'active', label: 'Ativo', type: 'boolean' },
    { key: 'operational_enabled', label: 'Habilitada para operação', type: 'boolean' },
  ],
  agreements: [
    { key: 'legacy_id', label: 'Código legado' }, { key: 'name', label: 'Nome' },
    { key: 'type', label: 'Tipo', type: 'select', options: [['PRIVATE', 'Particular'], ['AGREEMENT', 'Convênio'], ['B2B', 'Empresa B2B'], ['HEALTH_PLAN', 'Plano de saúde']] },
    { key: 'is_default', label: 'Convênio padrão', type: 'boolean' },
    { key: 'requires_authorization', label: 'Exige guia/autorização (bloqueia online)', type: 'boolean' },
    { key: 'online_enabled', label: 'Habilitado online', type: 'boolean' },
    { key: 'active', label: 'Ativo', type: 'boolean' },
  ],
  procedures: [
    { key: 'legacy_id', label: 'Código legado' }, { key: 'name', label: 'Nome' },
    { key: 'type', label: 'Tipo', type: 'select', options: [['CONSULTATION', 'Consulta'], ['EXAM', 'Exame']] },
    { key: 'group_name', label: 'Grupo' }, { key: 'tuss_code', label: 'Código TUSS' },
    { key: 'duration_minutes', label: 'Duração (minutos)', type: 'number' },
    { key: 'legacy', label: 'Legado', type: 'boolean' },
    { key: 'online_enabled', label: 'Habilitado online', type: 'boolean' },
    { key: 'active', label: 'Ativo', type: 'boolean' },
  ],
  professionals: [
    { key: 'legacy_id', label: 'Código legado' }, { key: 'name', label: 'Nome' },
    { key: 'council_acronym', label: 'Conselho' }, { key: 'council_number', label: 'Número do conselho' },
    { key: 'commission_type', label: 'Comissão', type: 'select', options: [['PERCENT', 'Percentual (%)'], ['FIXED', 'Valor fixo (R$)']] },
    { key: 'commission_value', label: 'Valor da comissão (% ou R$)', type: 'number' },
    { key: 'online_enabled', label: 'Habilitado online', type: 'boolean' },
    { key: 'active', label: 'Ativo', type: 'boolean' },
  ],
};

const defaults: Record<CatalogResource, Record<string, unknown>> = {
  units: { legacy_id: null, name: '', timezone: 'America/Fortaleza', cnpj: null, active: true, operational_enabled: false },
  agreements: { legacy_id: null, name: '', type: 'AGREEMENT', is_default: false, online_enabled: false, requires_authorization: false, active: true },
  procedures: { legacy_id: null, name: '', type: 'EXAM', group_name: null, tuss_code: null, duration_minutes: 30, legacy: false, online_enabled: false, active: true },
  professionals: { legacy_id: null, name: '', council_acronym: null, council_number: null, commission_type: 'PERCENT', commission_value: 0, user_id: null, online_enabled: false, active: true },
};

export default function CatalogPage({ user }: { user: CurrentUser }) {
  const sections = useMemo(() => [
    { id: 'catalog' as const, label: 'Cadastros', permission: 'catalog.view' },
    { id: 'links' as const, label: 'Vínculos', permission: 'catalog-links.view' },
    { id: 'scheduling' as const, label: 'Ofertas e horários', permission: 'schedule.manage' },
    { id: 'issues' as const, label: 'Pendências STG', permission: 'imports.view' },
    { id: 'users' as const, label: 'Usuários', permission: 'team.manage' },
    { id: 'audit' as const, label: 'Auditoria', permission: 'audit.view' },
  ].filter((item) => user.permissions.includes(item.permission as never)), [user.permissions]);
  const [section, setSection] = useState<Section>(sections[0]?.id ?? 'catalog');

  return <div className="page catalog-page">
    <div className="page-heading"><div><h1>Cadastros e acessos</h1><p>Catálogo operacional, vínculos, reconciliação e governança da equipe.</p></div></div>
    <div className="catalog-tabs" role="tablist" aria-label="Áreas administrativas">
      {sections.map((item) => <button key={item.id} role="tab" aria-selected={section === item.id} className={section === item.id ? 'active' : ''} onClick={() => setSection(item.id)}>{item.label}</button>)}
    </div>
    {section === 'catalog' ? <CatalogSection canManage={user.permissions.includes('catalog.manage')} /> : null}
    {section === 'links' ? <LinksSection canManage={user.permissions.includes('catalog-links.manage')} /> : null}
    {section === 'scheduling' ? <SchedulingSection /> : null}
    {section === 'issues' ? <IssuesSection canResolve={user.permissions.includes('imports.resolve')} /> : null}
    {section === 'users' ? <UsersSection /> : null}
    {section === 'audit' ? <AuditSection /> : null}
  </div>;
}

function CatalogSection({ canManage }: { canManage: boolean }) {
  const queryClient = useQueryClient();
  const [resource, setResource] = useState<CatalogResource>('procedures');
  const [query, setQuery] = useState('');
  const [active, setActive] = useState('1');
  const [editing, setEditing] = useState<CatalogItem | null>(null);
  const [form, setForm] = useState<Record<string, unknown>>(defaults.procedures);
  const list = useQuery({ queryKey: ['catalog', resource, query, active], queryFn: () => api.catalog(resource, { q: query || undefined, active: active || undefined }) });
  const save = useMutation({
    mutationFn: () => api.saveCatalog(resource, normalizeForm(form), editing?.id),
    onSuccess: async () => { setEditing(null); setForm(defaults[resource]); await queryClient.invalidateQueries({ queryKey: ['catalog', resource] }); },
  });
  const deactivate = useMutation({ mutationFn: (id: string) => api.deactivateCatalog(resource, id), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['catalog', resource] }) });

  const chooseResource = (next: CatalogResource) => { setResource(next); setEditing(null); setForm(defaults[next]); };
  const edit = (item: CatalogItem) => { setEditing(item); setForm(Object.fromEntries(resourceFields[resource].map((field) => [field.key, item[field.key] ?? '']))); };

  return <div className={`catalog-layout ${canManage ? '' : 'catalog-layout--readonly'}`}>
    <Panel className="catalog-list-panel">
      <div className="catalog-toolbar">
        <div className="catalog-resource-tabs">{(Object.keys(resourceLabels) as CatalogResource[]).map((item) => <button key={item} className={resource === item ? 'active' : ''} onClick={() => chooseResource(item)}>{resourceLabels[item]}</button>)}</div>
        <div className="catalog-filter"><Search size={17} /><input aria-label="Buscar cadastro" placeholder="Buscar por nome ou código" value={query} onChange={(event) => setQuery(event.target.value)} /></div>
        <SelectField label="Situação" value={active} onChange={(event) => setActive(event.target.value)}><option value="">Todos</option><option value="1">Ativos</option><option value="0">Inativos</option></SelectField>
      </div>
      {list.isLoading ? <LoadingState label="Carregando cadastros" /> : null}
      {list.isError ? <Alert tone="danger" title="Falha ao carregar">Não foi possível consultar os cadastros.</Alert> : null}
      {list.data?.data.length === 0 ? <EmptyState title="Nenhum cadastro encontrado" description="Revise os filtros ou crie um novo registro." /> : null}
      <div className="catalog-table">{list.data?.data.map((item) => <div className="catalog-row" key={item.id}>
        <span><strong>{item.name}</strong><small>{item.legacy_id ? `Código ${item.legacy_id}` : 'Cadastro manual'}{item.classification_status ? ` · ${String(item.classification_status)}` : ''}</small></span>
        <StatusPill tone={item.active ? 'success' : 'neutral'}>{item.active ? 'Ativo' : 'Inativo'}</StatusPill>
        {canManage ? <div className="catalog-actions"><Button variant="outline" icon={<Pencil size={15} />} onClick={() => edit(item)}>Editar</Button>{item.active ? <Button variant="danger" onClick={() => deactivate.mutate(item.id)}>Desativar</Button> : null}</div> : null}
      </div>)}</div>
      {list.data ? <small className="catalog-total">{list.data.meta.total} registros encontrados</small> : null}
    </Panel>
    {canManage ? <Panel className="catalog-form-panel">
      <h2>{editing ? 'Editar cadastro' : `Novo ${resourceLabels[resource].toLowerCase().replace(/s$/, '')}`}</h2>
      <p>Cadastros manuais ficam rastreados na auditoria.</p>
      <div className="catalog-form-grid">{resourceFields[resource].map((field) => <CatalogField key={field.key} field={field} value={form[field.key]} onChange={(value) => setForm((current) => ({ ...current, [field.key]: value }))} />)}</div>
      {save.isError ? <Alert tone="danger" title="Não foi possível salvar">Revise os campos obrigatórios e duplicidades.</Alert> : null}
      <div className="catalog-form-actions"><Button variant="outline" onClick={() => { setEditing(null); setForm(defaults[resource]); }}>Limpar</Button><Button icon={<Check size={16} />} disabled={save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Salvando...' : 'Salvar cadastro'}</Button></div>
    </Panel> : null}
    {canManage && resource === 'professionals' && editing ? <ProfessionalExtras professionalId={editing.id} /> : null}
  </div>;
}

function ProfessionalExtras({ professionalId }: { professionalId: string }) {
  const queryClient = useQueryClient();
  const users = useQuery({ queryKey: ['team-users'], queryFn: api.teamUsers });
  const commissions = useQuery({ queryKey: ['professional-commissions', professionalId], queryFn: () => api.professionalCommissions(professionalId) });
  const options = useQuery({ queryKey: ['catalog-link-options'], queryFn: api.catalogLinkOptions });
  const [userId, setUserId] = useState('');
  const [procedureId, setProcedureId] = useState('');
  const [ctype, setCtype] = useState<'PERCENT' | 'FIXED'>('PERCENT');
  const [cvalue, setCvalue] = useState('10');
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['professional-commissions', professionalId] });
  const saveUser = useMutation({
    mutationFn: () => api.saveCatalog('professionals', { user_id: userId || null } as never, professionalId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['catalog'] }),
  });
  const saveCommission = useMutation({
    mutationFn: () => api.saveProfessionalCommission(professionalId, { procedure_id: procedureId, commission_type: ctype, commission_value: cvalue }),
    onSuccess: async () => { setProcedureId(''); await refresh(); },
  });
  const removeCommission = useMutation({ mutationFn: (id: string) => api.deleteProfessionalCommission(professionalId, id), onSuccess: () => refresh() });

  return <Panel className="catalog-form-panel">
    <h2>Repasse do profissional</h2>
    <p>Vincule o login do médico (confirmação) e os overrides por procedimento.</p>
    <SelectField label="Login do médico (perfil DOCTOR)" value={userId} onChange={(event) => setUserId(event.target.value)}>
      <option value="">Sem vínculo</option>
      {(users.data ?? []).filter((item) => item.role === 'DOCTOR').map((item) => <option key={item.id} value={item.id}>{item.name} · {item.email}</option>)}
    </SelectField>
    {saveUser.isError ? <Alert tone="danger" title="Vínculo não salvo">Verifique se o usuário já está vinculado a outro profissional.</Alert> : null}
    <div className="catalog-form-actions"><Button disabled={saveUser.isPending} onClick={() => saveUser.mutate()}>Vincular login</Button></div>
    <h3>Overrides por procedimento</h3>
    {commissions.isLoading ? <LoadingState label="Carregando overrides" /> : null}
    {(commissions.data ?? []).map((item) => <div className="catalog-row" key={item.id}>
      <span><strong>{item.procedure?.name ?? item.procedure_id}</strong><small>{item.commission_type === 'PERCENT' ? `${item.commission_value}%` : `R$ ${item.commission_value}`}</small></span>
      <div className="catalog-actions"><Button variant="danger" disabled={removeCommission.isPending} onClick={() => removeCommission.mutate(item.id)}>Remover</Button></div>
    </div>)}
    {(commissions.data ?? []).length === 0 && !commissions.isLoading ? <EmptyState title="Sem overrides" description="Vale a comissão padrão do cadastro." /> : null}
    <div className="catalog-form-grid">
      <SelectField label="Procedimento" value={procedureId} onChange={(event) => setProcedureId(event.target.value)}>
        <option value="">Selecione</option>
        {options.data?.procedures.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
      </SelectField>
      <SelectField label="Tipo" value={ctype} onChange={(event) => setCtype(event.target.value as 'PERCENT' | 'FIXED')}><option value="PERCENT">Percentual (%)</option><option value="FIXED">Valor fixo (R$)</option></SelectField>
      <TextField label="Valor" value={cvalue} inputMode="decimal" onChange={(event) => setCvalue(event.target.value)} />
    </div>
    {saveCommission.isError ? <Alert tone="danger" title="Override inválido">Revise procedimento, tipo e valor.</Alert> : null}
    <div className="catalog-form-actions"><Button disabled={!procedureId || !cvalue || saveCommission.isPending} onClick={() => saveCommission.mutate()}>Salvar override</Button></div>
  </Panel>;
}

function CatalogField({ field, value, onChange }: { field: Field; value: unknown; onChange: (value: unknown) => void }) {
  if (field.type === 'boolean') return <label className="catalog-check"><input type="checkbox" checked={Boolean(value)} onChange={(event) => onChange(event.target.checked)} /><span>{field.label}</span></label>;
  if (field.type === 'select') return <SelectField label={field.label} value={String(value ?? '')} onChange={(event) => onChange(event.target.value)}>{field.options?.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</SelectField>;
  return <TextField label={field.label} type={field.type ?? 'text'} value={String(value ?? '')} onChange={(event) => onChange(field.type === 'number' ? Number(event.target.value) : event.target.value)} />;
}

function LinksSection({ canManage }: { canManage: boolean }) {
  const queryClient = useQueryClient();
  const [query, setQuery] = useState('');
  const options = useQuery({ queryKey: ['catalog-link-options'], queryFn: api.catalogLinkOptions });
  const links = useQuery({ queryKey: ['catalog-links', query], queryFn: () => api.catalogLinks({ q: query || undefined }) });
  const [form, setForm] = useState<CatalogLinkInput>({ unit_id: '', procedure_id: '', professional_id: '', agreement_id: '', active: true });
  const save = useMutation({ mutationFn: () => api.saveCatalogLink(form), onSuccess: async () => { setForm({ unit_id: '', procedure_id: '', professional_id: '', agreement_id: '', active: true }); await queryClient.invalidateQueries({ queryKey: ['catalog-links'] }); } });
  const deactivate = useMutation({ mutationFn: api.deactivateCatalogLink, onSuccess: () => queryClient.invalidateQueries({ queryKey: ['catalog-links'] }) });
  const optionFields: Array<[keyof CatalogLinkInput, CatalogResource, string]> = [['unit_id', 'units', 'Unidade'], ['procedure_id', 'procedures', 'Procedimento'], ['professional_id', 'professionals', 'Profissional'], ['agreement_id', 'agreements', 'Convênio']];

  return <div className="catalog-layout">
    <Panel className="catalog-list-panel">
      <div className="catalog-toolbar"><div className="catalog-filter"><Search size={17} /><input aria-label="Buscar vínculo" placeholder="Procedimento ou profissional" value={query} onChange={(event) => setQuery(event.target.value)} /></div></div>
      {links.isLoading ? <LoadingState label="Carregando vínculos" /> : null}
      {links.data?.data.map((link) => <div className="catalog-row catalog-link-row" key={link.id}><span><strong>{link.procedure.name}</strong><small>{link.professional.name} · {link.agreement.name} · {link.unit.name}</small></span><StatusPill tone={link.active ? 'success' : 'neutral'}>{link.active ? 'Ativo' : 'Inativo'}</StatusPill>{canManage && link.active ? <Button variant="danger" onClick={() => deactivate.mutate(link.id)}>Desativar</Button> : null}</div>)}
      {links.data?.data.length === 0 ? <EmptyState title="Nenhum vínculo validado" description="Crie o primeiro vínculo operacional do catálogo." /> : null}
    </Panel>
    {canManage ? <Panel className="catalog-form-panel"><h2>Novo vínculo</h2><p>Este vínculo não publica agenda nem preço online.</p><div className="catalog-form-grid">
      {optionFields.map(([key, resource, label]) => <SelectField key={key} label={label} value={String(form[key])} onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}><option value="">Selecione</option>{options.data?.[resource].map((item) => <option key={item.id} value={item.id}>{item.name}{item.legacy_id ? ` · ${item.legacy_id}` : ''}</option>)}</SelectField>)}
    </div>{save.isError ? <Alert tone="danger" title="Vínculo inválido">Verifique se as entidades estão ativas e se o vínculo já existe.</Alert> : null}<Button icon={<Link2 size={16} />} disabled={Object.values(form).some((value) => value === '') || save.isPending} onClick={() => save.mutate()}>Validar vínculo</Button></Panel> : null}
  </div>;
}

function IssuesSection({ canResolve }: { canResolve: boolean }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<ImportIssue['resolution_status']>('OPEN');
  const [selected, setSelected] = useState<ImportIssue | null>(null);
  const [note, setNote] = useState('');
  const issues = useQuery({ queryKey: ['import-issues', status], queryFn: () => api.importIssues(status) });
  const resolve = useMutation({ mutationFn: (resolution_status: ImportIssue['resolution_status']) => api.resolveImportIssue(selected!.id, { resolution_status, resolution_note: note }), onSuccess: async () => { setSelected(null); setNote(''); await queryClient.invalidateQueries({ queryKey: ['import-issues'] }); } });

  return <div className="catalog-layout"><Panel className="catalog-list-panel"><div className="catalog-toolbar"><SelectField label="Situação" value={status} onChange={(event) => setStatus(event.target.value as ImportIssue['resolution_status'])}><option value="OPEN">Abertas</option><option value="RESOLVED">Resolvidas</option><option value="IGNORED">Ignoradas</option><option value="UNMATCHED">Sem correspondência</option></SelectField></div>
    {issues.isLoading ? <LoadingState label="Carregando pendências" /> : null}{issues.data?.data.map((issue) => <button className={`issue-row ${selected?.id === issue.id ? 'active' : ''}`} key={issue.id} onClick={() => { setSelected(issue); setNote(issue.resolution_note ?? ''); }}><span><strong>{issue.code}</strong><small>{issue.entity_type} · {issue.legacy_id ?? 'sem ID'} · {issue.source_file}</small></span><StatusPill tone={issue.resolution_status === 'OPEN' ? 'warning' : issue.resolution_status === 'UNMATCHED' ? 'info' : 'success'}>{issue.resolution_status}</StatusPill></button>)}
    {issues.data?.data.length === 0 ? <EmptyState title="Nenhuma pendência nesta situação" description="A reconciliação está limpa para este filtro." /> : null}</Panel>
    {selected ? <Panel className="catalog-form-panel"><h2>Tratar pendência</h2><p><strong>{selected.code}</strong> · {selected.entity_type}</p><pre className="issue-payload">{JSON.stringify(selected.payload, null, 2)}</pre>{canResolve ? <><label className="dez-field"><span>Nota de resolução</span><textarea value={note} onChange={(event) => setNote(event.target.value)} rows={5} /></label><div className="catalog-form-actions"><Button variant="warning" disabled={!note.trim()} onClick={() => resolve.mutate('IGNORED')}>Ignorar com justificativa</Button><Button variant="success" disabled={!note.trim()} onClick={() => resolve.mutate('RESOLVED')}>Marcar resolvida</Button></div></> : null}</Panel> : null}</div>;
}

function UsersSection() {
  const queryClient = useQueryClient();
  const users = useQuery({ queryKey: ['team-users'], queryFn: api.teamUsers });
  const [editing, setEditing] = useState<TeamUser | null>(null);
  const [form, setForm] = useState<Record<string, unknown>>({ name: '', email: '', role: 'RECEPTION', active: true, password: '' });
  const save = useMutation({ mutationFn: () => api.saveTeamUser(normalizeForm(form), editing?.id), onSuccess: async () => { setEditing(null); setForm({ name: '', email: '', role: 'RECEPTION', active: true, password: '' }); await queryClient.invalidateQueries({ queryKey: ['team-users'] }); } });
  const edit = (user: TeamUser) => { setEditing(user); setForm({ name: user.name, email: user.email, role: user.role, active: user.active, password: '' }); };

  return <div className="catalog-layout"><Panel className="catalog-list-panel">{users.isLoading ? <LoadingState label="Carregando usuários" /> : null}{users.data?.map((user) => <div className="catalog-row" key={user.id}><span><strong>{user.name}</strong><small>{user.email} · {user.role_label}</small></span><StatusPill tone={user.active ? 'success' : 'neutral'}>{user.active ? 'Ativo' : 'Inativo'}</StatusPill><Button variant="outline" icon={<Pencil size={15} />} onClick={() => edit(user)}>Editar</Button></div>)}</Panel>
    <Panel className="catalog-form-panel"><h2>{editing ? 'Editar usuário' : 'Novo usuário'}</h2><div className="catalog-form-grid"><TextField label="Nome" value={String(form.name)} onChange={(event) => setForm((value) => ({ ...value, name: event.target.value }))} /><TextField label="E-mail" type="email" value={String(form.email)} onChange={(event) => setForm((value) => ({ ...value, email: event.target.value }))} /><SelectField label="Perfil" value={String(form.role)} onChange={(event) => setForm((value) => ({ ...value, role: event.target.value }))}><option value="RECEPTION">Recepção</option><option value="CASHIER">Caixa</option><option value="DOCTOR">Médico</option><option value="SUPERVISOR">Supervisor</option><option value="MANAGER">Gerente</option><option value="ADMIN">Administrador</option></SelectField><TextField label={editing ? 'Nova senha (opcional)' : 'Senha inicial (mín. 12 caracteres)'} type="password" value={String(form.password)} onChange={(event) => setForm((value) => ({ ...value, password: event.target.value }))} /><label className="catalog-check"><input type="checkbox" checked={Boolean(form.active)} onChange={(event) => setForm((value) => ({ ...value, active: event.target.checked }))} /><span>Usuário ativo</span></label></div>{save.isError ? <Alert tone="danger" title="Não foi possível salvar">Verifique senha, e-mail e a continuidade do acesso administrativo.</Alert> : null}<Button icon={<UserCog size={16} />} disabled={save.isPending} onClick={() => save.mutate()}>Salvar usuário</Button></Panel></div>;
}

function AuditSection() {
  const logs = useQuery({ queryKey: ['audit-logs'], queryFn: api.auditLogs });
  return <Panel className="catalog-list-panel">{logs.isLoading ? <LoadingState label="Carregando auditoria" /> : null}{logs.data?.data.map((entry: AuditEntry) => <div className="audit-row" key={entry.id}><ShieldCheck size={17} /><span><strong>{entry.action}</strong><small>{entry.entity_type} · {entry.entity_key ?? 'sem chave'} · operador {entry.actor_id ?? 'sistema'}</small></span><time>{new Date(entry.created_at).toLocaleString('pt-BR')}</time></div>)}{logs.data?.data.length === 0 ? <EmptyState title="Auditoria vazia" description="As alterações administrativas aparecerão aqui." /> : null}</Panel>;
}

function normalizeForm(form: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' && !['name', 'email', 'password'].includes(key) ? null : value]));
}
