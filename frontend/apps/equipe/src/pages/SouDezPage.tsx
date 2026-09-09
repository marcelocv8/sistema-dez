import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type LoyaltyDue, type LoyaltySubscription } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, LoadingState, Panel, SelectField, StatusPill, TextField } from '@dez/ui';

const methods = [
  { code: 'ESPECIE', label: 'Espécie' },
  { code: 'DEBITO', label: 'Débito' },
  { code: 'CREDITO', label: 'Crédito' },
  { code: 'PIX', label: 'PIX' },
];
type Tab = 'assinantes' | 'mensalidades' | 'planos';
const tabs: { id: Tab; label: string }[] = [
  { id: 'assinantes', label: 'Assinantes' },
  { id: 'mensalidades', label: 'Mensalidades' },
  { id: 'planos', label: 'Planos' },
];
const subTone = { ACTIVE: 'success', CANCELED: 'danger', BLOCKED: 'warning' } as const;
const subLabel = { ACTIVE: 'Ativa', CANCELED: 'Cancelada', BLOCKED: 'Bloqueada' } as const;

export default function SouDezPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [tab, setTab] = useState<Tab>('assinantes');
  const [search, setSearch] = useState('');
  const [form, setForm] = useState({ plan_id: '', cpf: '', name: '', dependent_cpf: '', dependent_name: '' });
  const [collect, setCollect] = useState<Record<string, { unit_id: string; method: string }>>({});
  const [planForm, setPlanForm] = useState({ name: '', agreement_id: '', monthly_fee: '', due_day: '10' });
  const canManage = user.permissions.includes('soudez.manage');
  const canOperate = user.permissions.includes('cash.operate');
  const refresh = () => client.invalidateQueries({ queryKey: ['loyalty'] });

  const plans = useQuery({ queryKey: ['loyalty', 'plans'], queryFn: () => api.loyaltyPlans() });
  const activePlans = (plans.data ?? []).filter((item) => item.active);
  const subscriptions = useQuery({
    queryKey: ['loyalty', 'subscriptions', search],
    queryFn: () => api.loyaltySubscriptions({ search: search || undefined }),
  });
  const dues = useQuery({ queryKey: ['loyalty', 'dues'], queryFn: () => api.loyaltyDues({ status: 'PENDENTE' }), enabled: tab === 'mensalidades' });
  const paidDues = useQuery({ queryKey: ['loyalty', 'dues-paid'], queryFn: () => api.loyaltyDues({ status: 'PAGA' }), enabled: tab === 'mensalidades' });
  const options = useQuery({ queryKey: ['scheduling-options'], queryFn: () => api.schedulingOptions(), enabled: tab === 'mensalidades' });
  const units = options.data?.units ?? [];
  const agreements = useQuery({ queryKey: ['catalog-agreements-loyalty'], queryFn: () => api.catalog('agreements', { active: '1' }), enabled: tab === 'planos' });

  const createSubscription = useMutation({
    mutationFn: () => api.createLoyaltySubscription({
      plan_id: form.plan_id,
      patient: { cpf: form.cpf, name: form.name || undefined },
      dependents: form.dependent_cpf ? [{ cpf: form.dependent_cpf, name: form.dependent_name || undefined }] : [],
    }),
    onSuccess: async () => { setForm({ plan_id: '', cpf: '', name: '', dependent_cpf: '', dependent_name: '' }); await refresh(); },
  });
  const changeStatus = useMutation({
    mutationFn: (input: { id: string; status: 'CANCELED' | 'ACTIVE' }) => api.updateLoyaltySubscription(input.id, { status: input.status }),
    onSuccess: refresh,
  });
  const collectDue = useMutation({
    mutationFn: (due: LoyaltyDue) => api.collectLoyaltyDue(due.id, {
      payment_method_code: collect[due.id]?.method || 'ESPECIE',
      unit_id: collect[due.id]?.unit_id || units[0]?.id || '',
    }),
    onSuccess: async () => { await refresh(); },
  });
  const createPlan = useMutation({
    mutationFn: () => api.createLoyaltyPlan({ name: planForm.name, agreement_id: planForm.agreement_id, monthly_fee: planForm.monthly_fee, due_day: Number(planForm.due_day) }),
    onSuccess: async () => { setPlanForm({ name: '', agreement_id: '', monthly_fee: '', due_day: '10' }); await refresh(); },
  });
  const togglePlan = useMutation({
    mutationFn: (plan: { id: string; active: boolean }) => api.updateLoyaltyPlan(plan.id, { active: plan.active }),
    onSuccess: refresh,
  });

  if (!user.permissions.includes('soudez.view')) {
    return <div className="page"><Panel><EmptyState title="Sem acesso ao Sou DEZ" description="Seu perfil não tem permissão para o programa de fidelidade." /></Panel></div>;
  }

  return <div className="page loyalty-page">
    <div className="page-heading"><div><h1>Sou DEZ</h1><p>Programa de fidelidade: assinaturas, mensalidades e planos com convênio próprio.</p></div></div>
    <div className="catalog-tabs" role="tablist" aria-label="Áreas do Sou DEZ">
      {tabs.map((item) => <button key={item.id} role="tab" aria-selected={tab === item.id} className={tab === item.id ? 'active' : ''} onClick={() => setTab(item.id)}>{item.label}</button>)}
    </div>

    {tab === 'assinantes' ? <>
      {canManage ? <Panel>
        <h2>Nova assinatura</h2>
        <div className="catalog-form-grid">
          <SelectField label="Plano" value={form.plan_id} onChange={(event) => setForm((current) => ({ ...current, plan_id: event.target.value }))}>
            <option value="">Selecione</option>
            {activePlans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {formatMoney(plan.monthly_fee)}/mês · venc. dia {plan.due_day}</option>)}
          </SelectField>
          <TextField label="CPF do titular" value={form.cpf} inputMode="numeric" onChange={(event) => setForm((current) => ({ ...current, cpf: event.target.value }))} />
          <TextField label="Nome do titular" value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} />
        </div>
        <div className="catalog-form-grid">
          <TextField label="CPF do dependente (opcional)" value={form.dependent_cpf} inputMode="numeric" onChange={(event) => setForm((current) => ({ ...current, dependent_cpf: event.target.value }))} />
          <TextField label="Nome do dependente" value={form.dependent_name} onChange={(event) => setForm((current) => ({ ...current, dependent_name: event.target.value }))} />
        </div>
        {createSubscription.isError ? <Alert tone="danger" title="Assinatura não criada">{apiErrorMessage(createSubscription.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button disabled={!canManage || !form.plan_id || form.cpf.replace(/\D/g, '').length !== 11 || createSubscription.isPending} onClick={() => createSubscription.mutate()}>{createSubscription.isPending ? 'Criando...' : 'Criar assinatura'}</Button></div>
        <p>Paciente novo é cadastrado com o nome informado; titular já existente é reconhecido pelo CPF.</p>
      </Panel> : null}
      <Panel>
        <h2>Assinaturas</h2>
        <div className="catalog-form-grid">
          <TextField label="Buscar por nome ou CPF" value={search} onChange={(event) => setSearch(event.target.value)} />
        </div>
        {subscriptions.isLoading ? <LoadingState label="Buscando assinaturas" /> : null}
        {subscriptions.isError ? <Alert tone="danger" title="Assinaturas indisponíveis">{apiErrorMessage(subscriptions.error)}</Alert> : null}
        {!subscriptions.isLoading && (subscriptions.data ?? []).length === 0 ? <EmptyState title="Nenhuma assinatura encontrada" description="Crie a primeira assinatura informando o CPF do titular." /> : null}
        {(subscriptions.data ?? []).map((subscription) => <SubscriptionRow key={subscription.id} subscription={subscription} canManage={canManage} canOperate={canOperate} units={units} methods={methods} collect={collect} setCollect={setCollect} collectDue={collectDue} changeStatus={changeStatus} />)}
        {changeStatus.isError ? <Alert tone="danger" title="Ação não concluída">{apiErrorMessage(changeStatus.error)}</Alert> : null}
      </Panel>
    </> : null}

    {tab === 'mensalidades' ? <Panel>
      <h2>Mensalidades pendentes</h2>
      {dues.isLoading ? <LoadingState label="Buscando mensalidades" /> : null}
      {dues.isError ? <Alert tone="danger" title="Mensalidades indisponíveis">{apiErrorMessage(dues.error)}</Alert> : null}
      {!dues.isLoading && (dues.data ?? []).length === 0 ? <EmptyState title="Nenhuma mensalidade pendente" description="Tudo em dia — mensalidades pagas aparecem na lista abaixo." /> : null}
      {(dues.data ?? []).map((due) => <div className="catalog-row" key={due.id}>
        <span>
          <strong>{due.subscription?.patient?.name ?? 'Paciente'} · {due.subscription?.plan_name ?? 'Plano'}</strong>
          <small>Competência {due.competence.slice(0, 7)} · Vence {due.due_date} · <b>{formatMoney(due.amount)}</b></small>
        </span>
        <StatusPill tone={due.overdue ? 'danger' : 'warning'}>{due.overdue ? 'Em atraso' : 'Pendente'}</StatusPill>
        {canManage && canOperate ? <div className="catalog-form-grid">
          <SelectField label="Unidade do caixa" value={collect[due.id]?.unit_id ?? units[0]?.id ?? ''} onChange={(event) => setCollect((prev) => ({ ...prev, [due.id]: { unit_id: event.target.value, method: prev[due.id]?.method || 'ESPECIE' } }))}>
            {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
          </SelectField>
          <SelectField label="Forma de pagamento" value={collect[due.id]?.method ?? 'ESPECIE'} onChange={(event) => setCollect((prev) => ({ ...prev, [due.id]: { unit_id: prev[due.id]?.unit_id || units[0]?.id || '', method: event.target.value } }))}>
            {methods.map((method) => <option key={method.code} value={method.code}>{method.label}</option>)}
          </SelectField>
        </div> : null}
        {canManage && canOperate ? <div className="catalog-actions"><Button disabled={collectDue.isPending || !units.length} onClick={() => collectDue.mutate(due)}>{collectDue.isPending ? 'Recebendo...' : 'Receber no caixa'}</Button></div> : null}
      </div>)}
      {collectDue.isError ? <Alert tone="danger" title="Recebimento não registrado">{apiErrorMessage(collectDue.error)}</Alert> : null}
      <h2>Mensalidades pagas</h2>
      {paidDues.isLoading ? <LoadingState label="Buscando pagas" /> : null}
      {(paidDues.data ?? []).length === 0 && !paidDues.isLoading ? <p>Nenhuma mensalidade paga ainda.</p> : null}
      {(paidDues.data ?? []).map((due) => <div className="catalog-row" key={due.id}>
        <span><strong>{due.subscription?.patient?.name ?? 'Paciente'} · {due.subscription?.plan_name ?? 'Plano'}</strong><small>Competência {due.competence.slice(0, 7)} · {formatMoney(due.amount)}</small></span>
        <StatusPill tone="success">Paga</StatusPill>
        <span />
      </div>)}
    </Panel> : null}

    {tab === 'planos' ? <>
      {canManage ? <Panel>
        <h2>Novo plano</h2>
        <div className="catalog-form-grid">
          <TextField label="Nome do plano" value={planForm.name} onChange={(event) => setPlanForm((current) => ({ ...current, name: event.target.value }))} />
          <SelectField label="Convênio do plano" value={planForm.agreement_id} onChange={(event) => setPlanForm((current) => ({ ...current, agreement_id: event.target.value }))}>
            <option value="">Selecione</option>
            {(agreements.data?.data ?? []).filter((item) => item.active && item.is_default === false).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </SelectField>
          <TextField label="Mensalidade (R$)" value={planForm.monthly_fee} inputMode="decimal" onChange={(event) => setPlanForm((current) => ({ ...current, monthly_fee: event.target.value }))} />
          <TextField label="Dia de vencimento" type="number" min={1} max={28} value={planForm.due_day} onChange={(event) => setPlanForm((current) => ({ ...current, due_day: event.target.value }))} />
        </div>
        {createPlan.isError ? <Alert tone="danger" title="Plano não criado">{apiErrorMessage(createPlan.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button disabled={!canManage || !planForm.name || !planForm.agreement_id || !planForm.monthly_fee || createPlan.isPending} onClick={() => createPlan.mutate()}>{createPlan.isPending ? 'Criando...' : 'Criar plano'}</Button></div>
      </Panel> : null}
      <Panel>
        <h2>Planos</h2>
        {plans.isLoading ? <LoadingState label="Buscando planos" /> : null}
        {plans.isError ? <Alert tone="danger" title="Planos indisponíveis">{apiErrorMessage(plans.error)}</Alert> : null}
        {(plans.data ?? []).map((plan) => <div className="catalog-row" key={plan.id}>
          <span>
            <strong>{plan.name}</strong>
            <small>{plan.agreement.name ?? 'Convênio'} · {formatMoney(plan.monthly_fee)}/mês · venc. dia {plan.due_day} · {plan.active_subscriptions_count} assinatura(s) ativa(s)</small>
          </span>
          <StatusPill tone={plan.active ? 'success' : 'neutral'}>{plan.active ? 'Ativo' : 'Inativo'}</StatusPill>
          {canManage ? <div className="catalog-actions"><Button variant="outline" disabled={togglePlan.isPending} onClick={() => togglePlan.mutate({ id: plan.id, active: !plan.active })}>{plan.active ? 'Desativar' : 'Ativar'}</Button></div> : null}
        </div>)}
        {togglePlan.isError ? <Alert tone="danger" title="Plano não atualizado">{apiErrorMessage(togglePlan.error)}</Alert> : null}
      </Panel>
    </> : null}
  </div>;
}

type CollectState = Record<string, { unit_id: string; method: string }>;

function SubscriptionRow({ subscription, canManage, canOperate, units, methods, collect, setCollect, collectDue, changeStatus }: {
  subscription: LoyaltySubscription;
  canManage: boolean;
  canOperate: boolean;
  units: { id: string; name: string }[];
  methods: { code: string; label: string }[];
  collect: CollectState;
  setCollect: (updater: (prev: CollectState) => CollectState) => void;
  collectDue: { mutate: (due: LoyaltyDue) => void; isPending: boolean };
  changeStatus: { mutate: (input: { id: string; status: 'CANCELED' | 'ACTIVE' }) => void; isPending: boolean };
}) {
  const [expanded, setExpanded] = useState(false);
  const pendingDue = subscription.dues.find((due) => due.status === 'PENDENTE');
  return <div className="catalog-row">
    <span>
      <strong>{subscription.patient?.name ?? 'Paciente'} · {subscription.plan?.name ?? 'Plano'}</strong>
      <small>Titular CPF ***.{subscription.patient?.cpf_last_four ?? '----'} · {subscription.dependents.filter((dependent) => dependent.active).length} dependente(s){pendingDue ? ` · mensalidade ${pendingDue.overdue ? 'em atraso' : 'pendente'} (${formatMoney(pendingDue.amount)})` : ''}</small>
    </span>
    <StatusPill tone={subTone[subscription.status]}>{subLabel[subscription.status]}{subscription.overdue_count > 0 ? ` · ${subscription.overdue_count} em atraso` : ''}</StatusPill>
    <div className="catalog-actions">
      <Button variant="outline" onClick={() => setExpanded((current) => !current)}>{expanded ? 'Ocultar' : 'Detalhes'}</Button>
      {canManage && subscription.status === 'ACTIVE' ? <Button variant="danger" disabled={changeStatus.isPending} onClick={() => changeStatus.mutate({ id: subscription.id, status: 'CANCELED' })}>Cancelar assinatura</Button> : null}
      {canManage && subscription.status !== 'ACTIVE' ? <Button disabled={changeStatus.isPending} onClick={() => changeStatus.mutate({ id: subscription.id, status: 'ACTIVE' })}>Reativar</Button> : null}
    </div>
    {expanded ? <div className="b2b-items">
      {subscription.dependents.map((dependent) => <div className="catalog-row" key={dependent.id}>
        <span><strong>{dependent.patient?.name ?? 'Dependente'}</strong><small>Dependente · CPF ***.{dependent.patient?.cpf_last_four ?? '----'}{dependent.active ? '' : ' · removido'}</small></span>
        <StatusPill tone={dependent.active ? 'info' : 'neutral'}>{dependent.active ? 'Vinculado' : 'Removido'}</StatusPill>
        <span />
      </div>)}
      {subscription.dues.map((due) => <div className="catalog-row" key={due.id}>
        <span><strong>Mensalidade {due.competence.slice(0, 7)}</strong><small>Vence {due.due_date} · {formatMoney(due.amount)}{due.paid_at ? ` · paga em ${due.paid_at.slice(0, 10)}` : ''}</small></span>
        <StatusPill tone={due.status === 'PAGA' ? 'success' : due.overdue ? 'danger' : 'warning'}>{due.status === 'PAGA' ? 'Paga' : due.overdue ? 'Em atraso' : 'Pendente'}</StatusPill>
        {canManage && canOperate && due.status === 'PENDENTE' ? <div className="catalog-actions">
          <SelectField label="Unidade" value={collect[due.id]?.unit_id ?? units[0]?.id ?? ''} onChange={(event) => setCollect((prev) => ({ ...prev, [due.id]: { unit_id: event.target.value, method: prev[due.id]?.method || 'ESPECIE' } }))}>
            {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
          </SelectField>
          <Button disabled={collectDue.isPending || !units.length} onClick={() => collectDue.mutate({ ...due, subscription: null })}>Receber no caixa</Button>
        </div> : <span />}
      </div>)}
    </div> : null}
  </div>;
}
