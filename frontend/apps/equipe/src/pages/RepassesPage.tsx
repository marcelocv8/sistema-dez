import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type Payout } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, LoadingState, Panel, SelectField, StatusPill, TextField } from '@dez/ui';

const statusLabels: Record<Payout['status'], string> = {
  OPEN: 'Aberto',
  CONFIRMED: 'Confirmado pelo médico',
  CONTESTED: 'Contestado',
  PAID: 'Pago',
};
const statusTones: Record<Payout['status'], 'warning' | 'info' | 'danger' | 'success'> = {
  OPEN: 'warning',
  CONFIRMED: 'info',
  CONTESTED: 'danger',
  PAID: 'success',
};
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
const monthStart = () => today().slice(0, 7) + '-01';

function formatDay(value: string): string {
  const parsed = new Date(`${value.slice(0, 10)}T12:00:00Z`);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('pt-BR', { timeZone: 'America/Fortaleza' });
}

function toCsv(payout: Payout): string {
  const rows = [['data', 'procedimento', 'bruto', 'comissao', 'regra']];
  (payout.items ?? []).forEach((item) => rows.push([
    (item.appointment?.scheduled_at ?? '').slice(0, 10),
    item.appointment?.serviceOffering?.procedure?.name ?? '',
    item.gross_amount,
    item.commission_amount,
    item.rule,
  ]));
  rows.push(['', 'TOTAL', payout.subtotal_gross, payout.total_commission, '']);
  return rows.map((row) => row.map((cell) => `"${String(cell).replaceAll('"', '""')}"`).join(';')).join('\r\n');
}

export default function RepassesPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [professionalId, setProfessionalId] = useState('');
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(today);
  const [status, setStatus] = useState('');
  const [selectedId, setSelectedId] = useState('');
  const [contestNote, setContestNote] = useState('');
  const [adjustments, setAdjustments] = useState<Record<string, string>>({});
  const [payMethod, setPayMethod] = useState<'ESPECIE' | 'PIX'>('ESPECIE');
  const [payRegister, setPayRegister] = useState('');
  const [payProof, setPayProof] = useState('');
  const canManage = user.permissions.includes('payouts.manage');
  const canConfirm = user.permissions.includes('payouts.confirm');
  const canPay = user.permissions.includes('payouts.pay');

  const options = useQuery({ queryKey: ['payout-professionals'], queryFn: api.catalogLinkOptions, retry: 3 });
  const optionsError = options.isError ? apiErrorMessage(options.error) : null;
  const mine = useQuery({ queryKey: ['my-professional'], queryFn: api.myProfessional, enabled: user.role === 'DOCTOR', retry: 3 });
  useEffect(() => {
    if (mine.data) setProfessionalId(mine.data.id);
  }, [mine.data]);
  const professionals = options.data?.professionals ?? [];
  const list = useQuery({
    queryKey: ['payouts', professionalId, from, to, status],
    queryFn: () => api.payouts({ professional_id: professionalId || undefined, from: from || undefined, to: to || undefined, status: (status || undefined) as Payout['status'] | undefined }),
  });
  const detail = useQuery({ queryKey: ['payout', selectedId], queryFn: () => api.payout(selectedId), enabled: Boolean(selectedId) });
  const openRegisters = useQuery({ queryKey: ['cash-open-registers'], queryFn: () => api.cashRegisters(undefined, { status: 'ABERTO' }), enabled: canPay });
  const payout = detail.data;
  const refresh = () => Promise.all([
    client.invalidateQueries({ queryKey: ['payouts'] }),
    client.invalidateQueries({ queryKey: ['payout'] }),
    client.invalidateQueries({ queryKey: ['cash-current'] }),
    client.invalidateQueries({ queryKey: ['cash-open-registers'] }),
    client.invalidateQueries({ queryKey: ['cash-report'] }),
  ]);
  const generate = useMutation({ mutationFn: () => api.generatePayout({ professional_id: professionalId, from, to }), onSuccess: async (data) => { setSelectedId(data.id); await refresh(); } });
  const confirm = useMutation({ mutationFn: () => api.confirmPayout(selectedId), onSuccess: () => refresh() });
  const contest = useMutation({ mutationFn: () => api.contestPayout(selectedId, contestNote), onSuccess: async () => { setContestNote(''); await refresh(); } });
  const adjust = useMutation({ mutationFn: ({ id, value }: { id: string; value: string }) => api.adjustPayoutItem(id, value), onSuccess: () => refresh() });
  const pay = useMutation({
    mutationFn: () => api.payPayout(selectedId, { payment_method_code: payMethod, cash_register_id: payMethod === 'ESPECIE' ? payRegister || undefined : undefined, proof_reference: payProof || undefined }),
    onSuccess: async () => { setPayProof(''); await refresh(); },
  });

  if (!user.permissions.includes('payouts.view')) {
    return <div className="page"><Panel><EmptyState title="Sem acesso aos repasses" description="Seu perfil não tem permissão para ver valores de repasse." /></Panel></div>;
  }

  return <div className="page">
    <div className="page-heading"><div><h1>Repasses médicos</h1><p>Produção do médico, confirmação auditável e pagamento manual.</p></div></div>
    <Panel>
      {mine.isError && user.role === 'DOCTOR' ? <Alert tone="danger" title="Médico sem vínculo">Não há profissional vinculado ao seu login; procure o administrador.</Alert> : null}
      <div className="catalog-form-grid">
        {user.role === 'DOCTOR' && mine.data ? <p role="status">Profissional: <strong>{mine.data.name}</strong></p> : <SelectField label="Profissional" value={professionalId} onChange={(event) => setProfessionalId(event.target.value)}>
          <option value="">Todos</option>
          {professionals.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
        </SelectField>}
        <TextField label="De" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
        <TextField label="Até" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
        <SelectField label="Situação" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Todas</option>
          <option value="OPEN">Aberto</option><option value="CONFIRMED">Confirmado</option>
          <option value="CONTESTED">Contestado</option><option value="PAID">Pago</option>
        </SelectField>
      </div>
      {optionsError ? <Alert tone="danger" title="Lista de profissionais indisponível">{optionsError} <Button variant="outline" onClick={() => options.refetch()}>Tentar novamente</Button></Alert> : null}
      {canManage || canConfirm ? <>
        {generate.isError ? <Alert tone="danger" title="Repasse não gerado">{apiErrorMessage(generate.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button disabled={!professionalId || !from || !to || generate.isPending} onClick={() => generate.mutate()}>Gerar repasse do período</Button></div>
        {!professionalId ? <p>Escolha o profissional para gerar o repasse.</p> : null}
      </> : null}
    </Panel>
    <Panel>
      <h2>Repasses</h2>
      {list.isLoading ? <LoadingState label="Buscando repasses" /> : null}
      {list.isError ? <Alert tone="danger" title="Repasses indisponíveis">{apiErrorMessage(list.error)}</Alert> : null}
      {!list.isLoading && (list.data ?? []).length === 0 ? <EmptyState title="Nenhum repasse" description="Gere o primeiro repasse do período acima." /> : null}
      {(list.data ?? []).map((item) => <div className="catalog-row" key={item.id}>
        <span><strong>{item.professional?.name ?? 'Profissional'}</strong><small>{formatDay(item.period_from)} a {formatDay(item.period_to)} · bruto {formatMoney(item.subtotal_gross)} · comissão {formatMoney(item.total_commission)} · {(item.items_count ?? 0) === 1 ? '1 ficha' : `${item.items_count ?? '?'} fichas`}</small></span>
        <StatusPill tone={statusTones[item.status]}>{statusLabels[item.status]}</StatusPill>
        <div className="catalog-actions"><Button variant={selectedId === item.id ? 'danger' : 'outline'} onClick={() => setSelectedId(selectedId === item.id ? '' : item.id)}>{selectedId === item.id ? 'Fechar' : 'Abrir'}</Button></div>
      </div>)}
    </Panel>
    {payout ? <Panel>
      <h2>Repasse · {payout.professional?.name}</h2>
      <p role="status">{formatDay(payout.period_from)} a {formatDay(payout.period_to)} · bruto {formatMoney(payout.subtotal_gross)} · <strong>comissão {formatMoney(payout.total_commission)}</strong></p>
      <StatusPill tone={statusTones[payout.status]}>{statusLabels[payout.status]}</StatusPill>
      {payout.contest_note ? <Alert tone="danger" title="Contestação do médico">{payout.contest_note}</Alert> : null}
      {(payout.items ?? []).map((item) => <div className="catalog-row" key={item.id}>
        <span><strong>{item.appointment?.serviceOffering?.procedure?.name ?? 'Atendimento'}</strong><small>{(item.appointment?.scheduled_at ?? '').slice(0, 10)} · bruto {formatMoney(item.gross_amount)} · {item.rule}</small></span>
        <span><strong>{formatMoney(item.commission_amount)}</strong></span>
        {canManage && (payout.status === 'OPEN' || payout.status === 'CONTESTED') ? <>
          <TextField label="Ajustar comissão (R$)" value={adjustments[item.id] ?? String(item.commission_amount)} inputMode="decimal" onChange={(event) => setAdjustments((prev) => ({ ...prev, [item.id]: event.target.value }))} />
          <div className="catalog-actions"><Button disabled={adjust.isPending} onClick={() => adjust.mutate({ id: item.id, value: adjustments[item.id] ?? String(item.commission_amount) })}>Ajustar</Button></div>
        </> : null}
      </div>)}
      {adjust.isError ? <Alert tone="danger" title="Ajuste não salvo">{apiErrorMessage(adjust.error)}</Alert> : null}
      <div className="catalog-form-actions">
        <Button variant="outline" onClick={() => {
          const blob = new Blob([toCsv(payout)], { type: 'text/csv;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url; link.download = `repasse-${payout.period_from}-${payout.period_to}.csv`; link.click();
          URL.revokeObjectURL(url);
        }}>Exportar CSV</Button>
      </div>
      {canConfirm && (payout.status === 'OPEN' || payout.status === 'CONTESTED' || payout.status === 'CONFIRMED') ? <div className="catalog-form-grid">
        <TextField label="Motivo da contestação" value={contestNote} onChange={(event) => setContestNote(event.target.value)} />
      </div> : null}
      {confirm.isError ? <Alert tone="danger" title="Confirmação não registrada">{apiErrorMessage(confirm.error)}</Alert> : null}
      {contest.isError ? <Alert tone="danger" title="Contestação não registrada">{apiErrorMessage(contest.error)}</Alert> : null}
      <div className="catalog-form-actions">
        {canConfirm && (payout.status === 'OPEN' || payout.status === 'CONTESTED') ? <Button variant="success" disabled={confirm.isPending} onClick={() => confirm.mutate()}>Confirmar repasse</Button> : null}
        {canConfirm && (payout.status === 'OPEN' || payout.status === 'CONFIRMED') ? <Button variant="warning" disabled={!contestNote.trim() || contest.isPending} onClick={() => contest.mutate()}>Contestar</Button> : null}
      </div>
      {canPay && payout.status === 'CONFIRMED' ? <>
        <h3>Pagar repasse</h3>
        <div className="catalog-form-grid">
          <SelectField label="Meio" value={payMethod} onChange={(event) => setPayMethod(event.target.value as 'ESPECIE' | 'PIX')}><option value="ESPECIE">Espécie (abate o caixa)</option><option value="PIX">PIX (não abate o caixa)</option></SelectField>
          {payMethod === 'ESPECIE' ? <SelectField label="Caixa aberto" value={payRegister} onChange={(event) => setPayRegister(event.target.value)}>
            <option value="">Selecione</option>
            {(openRegisters.data ?? []).map((item) => <option key={item.id} value={item.id}>{item.unit?.name ?? 'Unidade'} · turno {item.shift}</option>)}
          </SelectField> : null}
          <TextField label={payMethod === 'PIX' ? 'Comprovante PIX (obrigatório)' : 'Comprovante (opcional)'} value={payProof} onChange={(event) => setPayProof(event.target.value)} />
        </div>
        {pay.isError ? <Alert tone="danger" title="Pagamento não registrado">{apiErrorMessage(pay.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button variant="danger" disabled={pay.isPending || (payMethod === 'ESPECIE' && !payRegister) || (payMethod === 'PIX' && payProof.trim().length < 3)} onClick={() => pay.mutate()}>Pagar {formatMoney(payout.total_commission)}</Button></div>
      </> : null}
      {payout.status === 'PAID' ? <p role="status">Repasse pago{payout.payment_method ? ` via ${payout.payment_method.name}` : ''}{payout.proof_reference ? ` · comprovante ${payout.proof_reference}` : ''}.</p> : null}
    </Panel> : null}
  </div>;
}
