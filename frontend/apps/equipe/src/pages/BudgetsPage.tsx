import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type Budget, type BudgetItemInput, type BudgetStatus, type CurrentUser } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, LoadingState, Panel, SectionTitle, SelectField, StatusPill, TextField } from '@dez/ui';

const statusTone: Record<BudgetStatus, 'warning' | 'info' | 'success' | 'danger' | 'neutral'> = {
  RASCUNHO: 'warning', ENVIADO: 'info', ACEITO: 'success', RECUSADO: 'danger', PERDIDO: 'neutral',
};
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });

export default function BudgetsPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [statusFilter, setStatusFilter] = useState('');
  const [patientCpf, setPatientCpf] = useState('');
  const [patient, setPatient] = useState<{ id: string; name: string } | null>(null);
  const [agreementId, setAgreementId] = useState('');
  const [search, setSearch] = useState('');
  const [items, setItems] = useState<Array<{ procedure_id: string; name: string; amount: string; quantity: number }>>([]);
  const [prescription, setPrescription] = useState<File | undefined>();
  const [resolveFor, setResolveFor] = useState('');
  const [outcome, setOutcome] = useState<'ACEITO' | 'RECUSADO' | 'PERDIDO'>('ACEITO');
  const [note, setNote] = useState('');
  const canManage = user.permissions.includes('budgets.manage');

  const agreements = useQuery({ queryKey: ['catalog-agreements-b2b'], queryFn: () => api.catalog('agreements', { active: '1' }) });
  const budgets = useQuery({ queryKey: ['budgets', statusFilter], queryFn: () => api.budgets({ status: statusFilter || undefined }) });
  const options = useQuery({
    queryKey: ['budget-options', agreementId, search],
    queryFn: () => api.budgetPriceOptions(agreementId, search || undefined),
    enabled: Boolean(agreementId) && search.length >= 3,
  });
  const rows = budgets.data ?? [];
  const total = items.reduce((sum, item) => sum + Number(item.amount) * item.quantity, 0);
  const refresh = () => Promise.all([
    client.invalidateQueries({ queryKey: ['budgets'] }), client.invalidateQueries({ queryKey: ['routine-*'] }),
  ]);

  const lookup = useMutation({
    mutationFn: () => api.chatLookupPatient(patientCpf.replace(/\D/g, '')),
    onSuccess: (found) => setPatient(found ?? null),
  });
  const create = useMutation({
    mutationFn: () => api.createBudget({
      patient_id: patient!.id, agreement_id: agreementId || undefined,
      items: items.map<BudgetItemInput>((item) => ({ procedure_id: item.procedure_id, amount: item.amount, quantity: item.quantity })),
      prescription,
    }),
    onSuccess: async () => {
      setItems([]); setPrescription(undefined); setSearch(''); setPatient(null); setPatientCpf('');
      await refresh();
    },
  });
  const send = useMutation({ mutationFn: (id: string) => api.sendBudget(id), onSuccess: refresh });
  const resolve = useMutation({
    mutationFn: () => api.resolveBudget(resolveFor, { outcome, note }),
    onSuccess: async () => { setResolveFor(''); setNote(''); await refresh(); },
  });

  if (!user.permissions.includes('budgets.view')) {
    return <div className="page"><Panel><EmptyState title="Sem acesso aos orçamentos" description="Seu perfil não tem permissão." /></Panel></div>;
  }

  return <div className="page budgets-page">
    <div className="page-heading"><div><h1>Orçamentos por prescrição</h1><p>Prescrição → itens → orçamento validado → resolução obrigatória (sim/não).</p></div></div>
    {canManage ? <Panel>
      <h2>Novo orçamento</h2>
      <div className="catalog-form-grid">
        <TextField label="CPF do paciente" value={patientCpf} onChange={(event) => { setPatientCpf(event.target.value); setPatient(null); }} />
        <Button type="button" variant="outline" disabled={patientCpf.replace(/\D/g, '').length !== 11 || lookup.isPending} onClick={() => lookup.mutate()}>Localizar</Button>
        <SelectField label="Convênio da tabela" value={agreementId} onChange={(event) => { setAgreementId(event.target.value); setSearch(''); }}>
          <option value="">Selecione</option>
          {(agreements.data?.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
        </SelectField>
      </div>
      {lookup.isSuccess && !patient ? <Alert tone="warning" title="Paciente não encontrado">Cadastre o paciente na agenda antes do orçamento.</Alert> : null}
      {patient ? <p role="status">Paciente: <strong>{patient.name}</strong></p> : null}
      <div className="catalog-form-grid">
        <TextField label="Buscar procedimento (3+ letras/TUSS)" value={search} onChange={(event) => setSearch(event.target.value)} />
      </div>
      {options.isFetching ? <LoadingState label="Buscando preços" /> : null}
      {(options.data ?? []).slice(0, 6).map((option) => <div className="catalog-row" key={option.procedure_id}>
        <span><strong>{option.procedure_name}</strong><small>{option.type}</small></span>
        <b>{formatMoney(option.amount)}</b>
        <div className="catalog-actions"><Button disabled={!patient || !agreementId} onClick={() => setItems((current) => current.some((item) => item.procedure_id === option.procedure_id) ? current : [...current, { procedure_id: option.procedure_id, name: option.procedure_name ?? '', amount: option.amount, quantity: 1 }])}>Incluir</Button></div>
      </div>)}
      {items.length > 0 ? <>
        <SectionTitle title={`Itens do orçamento · ${formatMoney(total)}`} />
        {items.map((item, index) => <div className="catalog-row" key={item.procedure_id}>
          <span><strong>{item.name}</strong><small>{formatMoney(item.amount)} × {item.quantity}</small></span>
          <TextField label="Qtd" inputMode="numeric" value={String(item.quantity)} onChange={(event) => setItems((current) => current.map((it, i) => i === index ? { ...it, quantity: Math.max(1, Number(event.target.value) || 1) } : it))} />
          <div className="catalog-actions"><Button variant="danger" onClick={() => setItems((current) => current.filter((_, i) => i !== index))}>Remover</Button></div>
        </div>)}
        <div className="catalog-form-grid">
          <label className="catalog-check"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={(event) => setPrescription(event.target.files?.[0])} />Anexar prescrição (foto/PDF, opcional mas recomendado)</label>
        </div>
        {create.isError ? <Alert tone="danger" title="Orçamento não criado">{apiErrorMessage(create.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button disabled={!patient || !agreementId || items.length === 0 || create.isPending} onClick={() => create.mutate()}>{create.isPending ? 'Criando...' : `Criar orçamento (${formatMoney(total)})`}</Button></div>
      </> : null}
    </Panel> : null}
    <Panel>
      <SectionTitle title="Orçamentos" action={<b className="teal">{rows.length}</b>} />
      <SelectField label="Situação" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
        <option value="">Todas</option><option value="RASCUNHO">Rascunho</option><option value="ENVIADO">Enviados (sem resposta)</option><option value="ACEITO">Aceitos</option><option value="RECUSADO">Recusados</option><option value="PERDIDO">Perdidos</option>
      </SelectField>
      {budgets.isLoading ? <LoadingState label="Buscando orçamentos" /> : null}
      {budgets.isError ? <Alert tone="danger" title="Falha ao buscar">{apiErrorMessage(budgets.error)}</Alert> : null}
      {!budgets.isLoading && rows.length === 0 ? <EmptyState title="Nenhum orçamento" description="Crie o primeiro a partir da prescrição do paciente." /> : null}
      {rows.map((budget: Budget) => <div className="catalog-row" key={budget.id}>
        <span><strong>{budget.patient?.name}</strong><small>{budget.agreement?.name} · {formatMoney(budget.subtotal_gross)} · {budget.items.length} item(ns){budget.has_prescription ? ' · 📎 prescrição' : ''}</small>
          {budget.resolution_note ? <small>Resolução: {budget.resolution_note}</small> : null}</span>
        <StatusPill tone={statusTone[budget.status]}>{budget.status}</StatusPill>
        <div className="catalog-actions">
          {budget.has_prescription ? <a className="dez-button dez-button--outline" href={api.budgetPrescriptionUrl(budget.id)} target="_blank" rel="noreferrer">Prescrição</a> : null}
          {budget.status === 'RASCUNHO' && canManage ? <Button disabled={send.isPending} onClick={() => send.mutate(budget.id)}>Enviar ao paciente</Button> : null}
          {budget.status === 'ENVIADO' && canManage ? <Button variant="outline" onClick={() => { setResolveFor(budget.id); setOutcome('ACEITO'); setNote(''); }}>Resolver sim/não</Button> : null}
        </div>
        {resolveFor === budget.id ? <div className="catalog-form-grid">
          <SelectField label="Resultado" value={outcome} onChange={(event) => setOutcome(event.target.value as typeof outcome)}>
            <option value="ACEITO">Aceito</option><option value="RECUSADO">Recusado</option><option value="PERDIDO">Perdido/sem contato</option>
          </SelectField>
          <TextField label="Como foi resolvido" value={note} minLength={3} onChange={(event) => setNote(event.target.value)} />
        </div> : null}
        {resolveFor === budget.id ? <div className="catalog-form-actions"><Button disabled={note.trim().length < 3 || resolve.isPending} onClick={() => resolve.mutate()}>Registrar resolução</Button></div> : null}
      </div>)}
      {send.isError ? <Alert tone="danger" title="Envio falhou">{apiErrorMessage(send.error)}</Alert> : null}
      {resolve.isError ? <Alert tone="danger" title="Resolução falhou">{apiErrorMessage(resolve.error)}</Alert> : null}
    </Panel>
  </div>;
}
