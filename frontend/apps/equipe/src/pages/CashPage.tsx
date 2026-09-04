import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, LoadingState, Panel, SelectField, StatusPill, TextField } from '@dez/ui';

const methods = [
  { code: 'ESPECIE', label: 'Espécie' },
  { code: 'DEBITO', label: 'Débito' },
  { code: 'CREDITO', label: 'Crédito' },
  { code: 'PIX', label: 'PIX' },
];
const shifts = ['UNICO', 'MANHA', 'TARDE', 'NOITE'];
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });

export default function CashPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [unitId, setUnitId] = useState('');
  const [shift, setShift] = useState('UNICO');
  const [floatAmount, setFloatAmount] = useState('100.00');
  const [method, setMethod] = useState('ESPECIE');
  const [date, setDate] = useState(today);
  const [receipt, setReceipt] = useState('');
  const [countedCash, setCountedCash] = useState('');
  const [closeNote, setCloseNote] = useState('');
  const [batchCounts, setBatchCounts] = useState<Record<string, string>>({});
  const [batchNotes, setBatchNotes] = useState<Record<string, string>>({});
  const [batchSelected, setBatchSelected] = useState<string[]>([]);
  const [batchResult, setBatchResult] = useState('');
  const [sangriaAmount, setSangriaAmount] = useState('');
  const [sangriaDesc, setSangriaDesc] = useState('');
  const [sangriaProof, setSangriaProof] = useState('');
  const [voidReason, setVoidReason] = useState<Record<string, string>>({});
  const canOperate = user.permissions.includes('cash.operate');
  const canClose = user.permissions.includes('cash.close');
  const canValidate = user.permissions.includes('discounts.validate');

  const options = useQuery({ queryKey: ['scheduling-options'], queryFn: () => api.schedulingOptions() });
  const units = options.data?.units ?? [];
  const activeUnit = unitId || units[0]?.id || '';
  const current = useQuery({ queryKey: ['cash-current', activeUnit], queryFn: () => api.currentCashRegister(activeUnit || undefined), enabled: Boolean(activeUnit) });
  const register = current.data;
  const openRegisters = useQuery({ queryKey: ['cash-open-registers'], queryFn: () => api.cashRegisters(undefined, { status: 'ABERTO' }) });
  const report = useQuery({
    queryKey: ['cash-report', activeUnit, date],
    queryFn: () => api.cashDailyReport(activeUnit, date),
    enabled: Boolean(activeUnit && date),
  });
  const pending = useQuery({
    queryKey: ['cash-pending', activeUnit, date],
    queryFn: () => api.appointments(date, { unit_id: activeUnit || undefined, payment_status: 'PENDING' }),
    enabled: Boolean(register && activeUnit),
  });
  const paid = useQuery({
    queryKey: ['cash-paid', activeUnit, date],
    queryFn: () => api.appointments(date, { unit_id: activeUnit || undefined, payment_status: 'PAID' }),
    enabled: Boolean(register && activeUnit && canValidate),
  });
  const pendingRows = (pending.data?.data.appointments ?? []).filter((item) => item.status !== 'CANCELLED');
  const paidRows = (paid.data?.data.appointments ?? []).filter((item) => item.status !== 'CANCELLED');
  const refresh = () => Promise.all([
    client.invalidateQueries({ queryKey: ['cash-current'] }),
    client.invalidateQueries({ queryKey: ['cash-open-registers'] }),
    client.invalidateQueries({ queryKey: ['cash-report'] }),
    client.invalidateQueries({ queryKey: ['cash-pending'] }),
    client.invalidateQueries({ queryKey: ['cash-paid'] }),
    client.invalidateQueries({ queryKey: ['appointments'] }),
  ]);
  const open = useMutation({
    mutationFn: () => api.openCashRegister({ unit_id: activeUnit, shift, float_amount: floatAmount }),
    onSuccess: () => refresh(),
  });
  const charge = useMutation({
    mutationFn: (id: string) => api.checkoutAppointment(id, method),
    onSuccess: async (data) => {
      setReceipt(`Recibo ${data.receipt_number} · ${formatMoney(data.net_amount)} (${data.method.name}).`);
      await refresh();
    },
  });
  const close = useMutation({
    mutationFn: () => api.closeCashRegister(register!.id, { counted_cash: countedCash, note: closeNote || undefined }),
    onSuccess: async () => {
      setCountedCash('');
      setCloseNote('');
      await refresh();
    },
  });
  const closeBatch = useMutation({
    mutationFn: () => api.closeCashBatch(batchSelected.map((id) => ({ id, counted_cash: batchCounts[id] || '0', note: batchNotes[id] || undefined }))),
    onSuccess: async (data) => {
      setBatchResult(`${data.closed.length} fechado(s), ${data.failed.length} falha(s).`);
      setBatchSelected([]);
      await refresh();
    },
  });
  const sangria = useMutation({
    mutationFn: () => api.createSangria(register!.id, { amount: sangriaAmount, description: sangriaDesc, proof_reference: sangriaProof || undefined }),
    onSuccess: async () => {
      setSangriaAmount('');
      setSangriaDesc('');
      setSangriaProof('');
      await refresh();
    },
  });
  const voidPayment = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => api.voidAppointmentPayment(id, reason),
    onSuccess: () => refresh(),
  });

  if (!user.permissions.includes('cash.view')) {
    return <div className="page"><Panel><EmptyState title="Sem acesso ao caixa" description="Seu perfil não tem permissão para ver valores financeiros." /></Panel></div>;
  }

  return <div className="page">
    <div className="page-heading"><div><h1>Caixa</h1><p>Cobrança das fichas da recepção, com recibo sequencial.</p></div></div>
    {options.isLoading ? <LoadingState label="Carregando unidades" /> : null}
    <Panel>
      <SelectField label="Unidade" value={activeUnit} onChange={(event) => setUnitId(event.target.value)}>
        {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
      </SelectField>
    </Panel>
    {current.isLoading ? <LoadingState label="Consultando caixa" /> : null}
    {current.isError ? <Alert tone="danger" title="Caixa indisponível">{apiErrorMessage(current.error)}</Alert> : null}
    {!current.isLoading && !register ? <Panel>
      <h2>Abrir caixa do dia</h2>
      <div className="catalog-form-grid">
        <SelectField label="Turno" value={shift} onChange={(event) => setShift(event.target.value)}>{shifts.map((item) => <option key={item} value={item}>{item}</option>)}</SelectField>
        <TextField label="Fundo de troco (R$)" value={floatAmount} inputMode="decimal" onChange={(event) => setFloatAmount(event.target.value)} />
      </div>
      {open.isError ? <Alert tone="danger" title="Caixa não aberto">{apiErrorMessage(open.error)}</Alert> : null}
      <div className="catalog-form-actions"><Button disabled={!canOperate || !activeUnit || open.isPending} onClick={() => open.mutate()}>Abrir caixa</Button></div>
      {!canOperate ? <p>Seu perfil pode ver o caixa, mas não operá-lo.</p> : null}
    </Panel> : null}
    {register ? <>
      <Panel>
        <h2>Caixa aberto · turno {register.shift}</h2>
        <p role="status">Fundo de troco: {formatMoney(register.float_amount)} · Recibos emitidos: {register.summary.receipt_count} · Dinheiro esperado no cofre: <strong>{formatMoney(register.summary.expected_cash)}</strong></p>
        <div className="flow-stats">{register.summary.by_method.map((row) => <span key={row.method}><small>{row.name}</small><b>{formatMoney(row.net)} ({row.count})</b></span>)}</div>
        {register.summary.sangria_total !== '0.00' ? <p>Sangrias: {formatMoney(register.summary.sangria_total)} · Estornos: {formatMoney(register.summary.void_total)}</p> : null}
        <div className="catalog-form-grid">
          <TextField label="Dinheiro contado no cofre (R$)" value={countedCash} inputMode="decimal" onChange={(event) => setCountedCash(event.target.value)} />
          <TextField label="Justificativa da quebra (se houver diferença)" value={closeNote} onChange={(event) => setCloseNote(event.target.value)} />
        </div>
        {!canValidate ? <p>Qualquer diferença no fechamento exige supervisor.</p> : null}
        {close.isError ? <Alert tone="danger" title="Caixa não fechado">{apiErrorMessage(close.error)}</Alert> : null}
        <div className="catalog-form-actions">{canClose ? <Button variant="danger" disabled={close.isPending || !countedCash} onClick={() => close.mutate()}>Fechar caixa do dia</Button> : null}</div>
      </Panel>
      <Panel>
        <h2>Sangria em dinheiro</h2>
        <div className="catalog-form-grid">
          <TextField label="Valor (R$)" value={sangriaAmount} inputMode="decimal" onChange={(event) => setSangriaAmount(event.target.value)} />
          <TextField label="Motivo" value={sangriaDesc} onChange={(event) => setSangriaDesc(event.target.value)} />
          <TextField label="Comprovante (ref.)" value={sangriaProof} onChange={(event) => setSangriaProof(event.target.value)} />
        </div>
        {sangria.isError ? <Alert tone="danger" title="Sangria não registrada">{apiErrorMessage(sangria.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button disabled={!canOperate || !sangriaAmount || !sangriaDesc || sangria.isPending} onClick={() => sangria.mutate()}>Registrar sangria</Button></div>
      </Panel>
      <Panel>
        <h2>Fichas aguardando pagamento</h2>
        <div className="catalog-form-grid">
          <TextField label="Data das fichas" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
          <SelectField label="Forma de pagamento" value={method} onChange={(event) => setMethod(event.target.value)}>{methods.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}</SelectField>
        </div>
        {receipt ? <p role="status">{receipt}</p> : null}
        {pending.isLoading ? <LoadingState label="Buscando fichas" /> : null}
        {pending.isError ? <Alert tone="danger" title="Fichas indisponíveis">{apiErrorMessage(pending.error)}</Alert> : null}
        {!pending.isLoading && pendingRows.length === 0 ? <EmptyState title="Nenhuma ficha pendente" description="Novas fichas da recepção aparecem aqui para cobrança." /> : null}
        {pendingRows.map((item) => <div className="catalog-row" key={item.id}>
          <span><strong>{item.patient_name ?? 'Paciente'}</strong><small>{item.procedure.name} · {item.professional?.name} · {item.scheduled_at.slice(11, 16)}</small><small>Preço congelado: {formatMoney(item.price)} · {item.status === 'CHECKED_IN' ? 'Check-in feito' : 'Agendado'}</small></span>
          <StatusPill tone={item.payment_status === 'PAID' ? 'success' : item.payment_status === 'AUTHORIZED' ? 'info' : 'warning'}>{item.payment_status === 'PAID' ? 'Pago' : item.payment_status === 'AUTHORIZED' ? 'Autorizado B2B' : 'Pendente'}</StatusPill>
          <div className="catalog-actions"><Button disabled={!canOperate || charge.isPending} onClick={() => charge.mutate(item.id)}>Cobrar {formatMoney(item.price)}</Button></div>
        </div>)}
        {charge.isError ? <Alert tone="danger" title="Cobrança não concluída">{apiErrorMessage(charge.error)}</Alert> : null}
      </Panel>
      {canValidate ? <Panel>
        <h2>Fichas pagas · estorno do supervisor</h2>
        {paid.isLoading ? <LoadingState label="Buscando fichas pagas" /> : null}
        {!paid.isLoading && paidRows.length === 0 ? <EmptyState title="Nenhuma ficha paga no dia" description="Estornos aparecem aqui quando houver cobrança." /> : null}
        {paidRows.map((item) => <div className="catalog-row" key={item.id}>
          <span><strong>{item.patient_name ?? 'Paciente'}</strong><small>Recibo {item.receipt_number} · {formatMoney(item.price)}</small></span>
          <TextField label="Motivo do estorno" value={voidReason[item.id] || ''} onChange={(event) => setVoidReason((prev) => ({ ...prev, [item.id]: event.target.value }))} />
          <div className="catalog-actions"><Button variant="danger" disabled={voidPayment.isPending || !(voidReason[item.id] || '').trim()} onClick={() => voidPayment.mutate({ id: item.id, reason: voidReason[item.id] })}>Estornar</Button></div>
        </div>)}
        {voidPayment.isError ? <Alert tone="danger" title="Estorno não concluído">{apiErrorMessage(voidPayment.error)}</Alert> : null}
      </Panel> : null}
    </> : null}
    <Panel>
      <h2>Fechamento consolidado (7–15 dias)</h2>
      {openRegisters.isLoading ? <LoadingState label="Buscando caixas abertos" /> : null}
      {(openRegisters.data ?? []).length === 0 && !openRegisters.isLoading ? <EmptyState title="Nenhum caixa aberto" description="Caixas abertos de todas as unidades aparecem aqui para fechar em lote." /> : null}
      {(openRegisters.data ?? []).map((item) => <div className="catalog-row" key={item.id}>
        <span><strong>{item.unit?.name ?? 'Unidade'}</strong><small>Turno {item.shift} · aberto em {item.opened_at.slice(0, 10)}</small></span>
        <input type="checkbox" aria-label={`Selecionar caixa ${item.shift}`} checked={batchSelected.includes(item.id)} onChange={(event) => setBatchSelected((prev) => event.target.checked ? [...prev, item.id] : prev.filter((id) => id !== item.id))} />
        <TextField label="Contado (R$)" value={batchCounts[item.id] || ''} inputMode="decimal" onChange={(event) => setBatchCounts((prev) => ({ ...prev, [item.id]: event.target.value }))} />
        <TextField label="Justificativa (se quebra)" value={batchNotes[item.id] || ''} onChange={(event) => setBatchNotes((prev) => ({ ...prev, [item.id]: event.target.value }))} />
      </div>)}
      {batchResult ? <p role="status">{batchResult}</p> : null}
      {closeBatch.isError ? <Alert tone="danger" title="Lote não fechado">{apiErrorMessage(closeBatch.error)}</Alert> : null}
      <div className="catalog-form-actions">{canClose ? <Button variant="danger" disabled={closeBatch.isPending || batchSelected.length === 0 || batchSelected.some((id) => !batchCounts[id])} onClick={() => closeBatch.mutate()}>Fechar lote ({batchSelected.length})</Button> : null}</div>
    </Panel>
    <Panel>
      <h2>Relatório diário</h2>
      {report.isLoading ? <LoadingState label="Gerando relatório" /> : null}
      {report.isError ? <Alert tone="danger" title="Relatório indisponível">{apiErrorMessage(report.error)}</Alert> : null}
      {report.data ? <div>
        <p role="status">Esperado: {formatMoney(report.data.totals.expected_cash)} · Contado: {formatMoney(report.data.totals.counted_cash)} · Diferença: {formatMoney(report.data.totals.cash_difference)}</p>
        <p>Sangrias: {formatMoney(report.data.totals.sangria_total)} · Estornos: {formatMoney(report.data.totals.void_total)} · Caixas: {report.data.registers.length}</p>
        {report.data.receipt_gaps.length > 0 ? <Alert tone="danger" title="Lacuna de recibo detectada">{report.data.receipt_gaps.map((gap) => gap.missing_receipts.join(', ')).join('; ')}</Alert> : <p>Sem lacuna de recibo.</p>}
      </div> : null}
    </Panel>
  </div>;
}
