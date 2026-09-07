import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type B2bComputedStatus, type CurrentUser } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, LoadingState, Panel, SelectField, StatusPill, TextField } from '@dez/ui';

const statusTone: Record<B2bComputedStatus, 'warning' | 'info' | 'success' | 'danger'> = {
  EMITIDA: 'warning',
  ENVIADA: 'info',
  PAGA: 'success',
  VENCIDA: 'danger',
};
const statusLabel: Record<B2bComputedStatus, string> = {
  EMITIDA: 'Emitida',
  ENVIADA: 'Enviada à empresa',
  PAGA: 'Quitada',
  VENCIDA: 'Vencida em aberto',
};
const currentCompetence = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' }).slice(0, 7);

export default function B2bPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [competence, setCompetence] = useState(currentCompetence);
  const [agreementId, setAgreementId] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [expanded, setExpanded] = useState<string | null>(null);
  const [settle, setSettle] = useState<Record<string, { amount: string; proof: string }>>({});
  const canManage = user.permissions.includes('b2b.manage');
  const canSettle = user.permissions.includes('b2b.settle');

  const agreements = useQuery({ queryKey: ['catalog-agreements-b2b'], queryFn: () => api.catalog('agreements', { active: '1' }) });
  const companies = (agreements.data?.data ?? []).filter((item) => item.active && item.is_default === false);
  const invoices = useQuery({
    queryKey: ['b2b-invoices', competence, statusFilter],
    queryFn: () => api.b2bInvoices({ competence, status: statusFilter || undefined }),
  });
  const rows = invoices.data ?? [];
  const overdue = rows.filter((item) => item.overdue);
  const refresh = () => client.invalidateQueries({ queryKey: ['b2b-invoices'] });

  const generate = useMutation({
    mutationFn: () => api.generateB2bInvoice({ agreement_id: agreementId, competence }),
    onSuccess: async () => { setAgreementId(''); await refresh(); },
  });
  const send = useMutation({ mutationFn: (id: string) => api.sendB2bInvoice(id), onSuccess: refresh });
  const settleInvoice = useMutation({
    mutationFn: (id: string) => api.settleB2bInvoice(id, { amount: settle[id]?.amount ?? '0', proof_reference: settle[id]?.proof ?? '' }),
    onSuccess: async (_data, id) => {
      setSettle((prev) => ({ ...prev, [id]: { amount: '', proof: '' } }));
      await refresh();
    },
  });

  if (!user.permissions.includes('b2b.view')) {
    return <div className="page"><Panel><EmptyState title="Sem acesso ao faturamento B2B" description="Seu perfil não tem permissão para ver faturas de empresas." /></Panel></div>;
  }

  return <div className="page b2b-page">
    <div className="page-heading"><div><h1>Faturamento B2B</h1><p>Fatura fim do mês por empresa, baixa manual e filtro de adimplência.</p></div></div>
    {overdue.length > 0 ? <Alert tone="danger" title={`${overdue.length} fatura(s) vencida(s) em aberto`}>Novas autorizações B2B destas empresas ficam bloqueadas até a regularização: {overdue.map((item) => `${item.agreement.name ?? 'Empresa'} (${item.competence.slice(0, 7)})`).join('; ')}.</Alert> : null}
    <Panel>
      <h2>Gerar fatura do mês</h2>
      <div className="catalog-form-grid">
        <SelectField label="Empresa (convênio)" value={agreementId} onChange={(event) => setAgreementId(event.target.value)}>
          <option value="">Selecione</option>
          {companies.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
        </SelectField>
        <TextField label="Competência" type="month" value={competence} onChange={(event) => setCompetence(event.target.value)} />
        <SelectField label="Situação" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
          <option value="">Todas</option>
          <option value="EMITIDA">Emitidas</option>
          <option value="ENVIADA">Enviadas</option>
          <option value="VENCIDA">Vencidas</option>
          <option value="PAGA">Quitadas</option>
        </SelectField>
      </div>
      {generate.isError ? <Alert tone="danger" title="Fatura não gerada">{apiErrorMessage(generate.error)}</Alert> : null}
      {agreements.isError ? <Alert tone="danger" title="Empresas indisponíveis">{apiErrorMessage(agreements.error)}</Alert> : null}
      <div className="catalog-form-actions"><Button disabled={!canManage || !agreementId || !competence || generate.isPending} onClick={() => generate.mutate()}>{generate.isPending ? 'Gerando...' : 'Gerar fatura da competência'}</Button></div>
      {!canManage ? <p>Faturas são geradas por supervisor, gerente ou admin.</p> : null}
    </Panel>
    <Panel>
      <h2>Faturas · {competence}</h2>
      {invoices.isLoading ? <LoadingState label="Buscando faturas" /> : null}
      {invoices.isError ? <Alert tone="danger" title="Faturas indisponíveis">{apiErrorMessage(invoices.error)}</Alert> : null}
      {!invoices.isLoading && rows.length === 0 ? <EmptyState title="Nenhuma fatura nesta competência" description="Gere a fatura da empresa a partir dos atendimentos autorizados no mês." /> : null}
      {rows.map((invoice) => <div className="catalog-row" key={invoice.id}>
        <span>
          <strong>{invoice.agreement.name ?? 'Empresa'} · {invoice.competence.slice(0, 7)}</strong>
          <small>Total {formatMoney(invoice.subtotal_gross)} · Baixado {formatMoney(invoice.settled_amount)} · Restante <b>{formatMoney(invoice.remaining_amount)}</b> · Vence {invoice.due_at.slice(0, 10)}</small>
          <small>{invoice.items_count} atendimento(s){invoice.proof_reference ? ` · Comprovante: ${invoice.proof_reference}` : ''}</small>
        </span>
        <StatusPill tone={statusTone[invoice.computed_status]}>{statusLabel[invoice.computed_status]}</StatusPill>
        <div className="catalog-actions">
          <Button variant="outline" onClick={() => setExpanded((current) => current === invoice.id ? null : invoice.id)}>{expanded === invoice.id ? 'Ocultar itens' : 'Ver itens'}</Button>
          {invoice.status === 'EMITIDA' && canManage ? <Button disabled={send.isPending} onClick={() => send.mutate(invoice.id)}>Encaminhar à empresa</Button> : null}
          <Button variant="outline" onClick={() => window.print()}>Imprimir</Button>
        </div>
        {expanded === invoice.id ? <div className="b2b-items">
          {invoice.items.map((item) => <div className="catalog-row" key={item.id}>
            <span><strong>{item.patient_name}</strong><small>{item.procedure_name} · {item.scheduled_at.slice(0, 10)}</small></span>
            <b>{formatMoney(item.gross_amount)}</b>
          </div>)}
          {invoice.status !== 'PAGA' && canSettle ? <div className="catalog-form-grid">
            <TextField label="Valor da baixa (R$) — parcial ou total" value={settle[invoice.id]?.amount || ''} inputMode="decimal" onChange={(event) => setSettle((prev) => ({ ...prev, [invoice.id]: { amount: event.target.value, proof: prev[invoice.id]?.proof || '' } }))} />
            <TextField label="Comprovante (ref.)" value={settle[invoice.id]?.proof || ''} onChange={(event) => setSettle((prev) => ({ ...prev, [invoice.id]: { amount: prev[invoice.id]?.amount || '', proof: event.target.value } }))} />
          </div> : null}
          {invoice.status !== 'PAGA' && canSettle ? <div className="catalog-form-actions"><Button disabled={settleInvoice.isPending || !(settle[invoice.id]?.amount || '').trim() || (settle[invoice.id]?.proof || '').trim().length < 3} onClick={() => settleInvoice.mutate(invoice.id)}>Baixar pagamento</Button></div> : null}
          {settleInvoice.isError ? <Alert tone="danger" title="Baixa não registrada">{apiErrorMessage(settleInvoice.error)}</Alert> : null}
        </div> : null}
      </div>)}
      {send.isError ? <Alert tone="danger" title="Fatura não encaminhada">{apiErrorMessage(send.error)}</Alert> : null}
    </Panel>
  </div>;
}
