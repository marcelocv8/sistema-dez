import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { BellRing, Check, UserRoundPlus } from 'lucide-react';
import { api, apiErrorMessage, type Appointment, type CurrentUser, type QueueItem } from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, SectionTitle, SelectField, StatusPill, TextField } from '@dez/ui';

const paymentMeta = (status: Appointment['payment_status'] | null) => status === 'PAID'
  ? { label: 'Pago', tone: 'success' as const }
  : status === 'AUTHORIZED'
    ? { label: 'Autorizado B2B', tone: 'info' as const }
    : { label: 'Aguardando pagamento', tone: 'warning' as const };
const queueStatus: Record<string, { label: string; tone: 'success' | 'warning' | 'purple' | 'info' }> = {
  WAITING: { label: 'Na fila', tone: 'warning' },
  CALLED: { label: 'Chamado', tone: 'info' },
  IN_SERVICE: { label: 'Em atendimento', tone: 'purple' },
  CHECKOUT: { label: 'Em caixa', tone: 'info' },
  FINISHED: { label: 'Concluído', tone: 'success' },
};
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });

export default function ReceptionPage({ user }: { user?: CurrentUser } = {}) {
  const client = useQueryClient();
  const [params, setParams] = useSearchParams();
  const tab = params.get('fila') === '1' ? 'fila' : 'checkin';
  const [date, setDate] = useState(today);
  const [unitId, setUnitId] = useState('');
  const [priorityFor, setPriorityFor] = useState('');
  const [priorityCode, setPriorityCode] = useState('');
  const [priorityReason, setPriorityReason] = useState('');
  const [notice, setNotice] = useState('');
  const canManage = user?.permissions.includes('appointments.manage') ?? true;
  const canCall = user?.permissions.includes('queue.call') ?? true;

  const options = useQuery({ queryKey: ['scheduling-options'], queryFn: () => api.schedulingOptions() });
  const units = options.data?.units ?? [];
  const queue = useQuery({ queryKey: ['queue', date, unitId], queryFn: () => api.queue({ date, unit_id: unitId || undefined }) });
  const day = useQuery({ queryKey: ['appointments', date], queryFn: () => api.appointments(date) });
  const history = useQuery({ queryKey: ['queue-history', date], queryFn: () => api.queueHistory({ date }) });
  const conflicts = useQuery({ queryKey: ['schedule-conflicts'], queryFn: () => api.scheduleConflicts(), enabled: canManage });

  const rows = queue.data?.queue ?? [];
  const priorities = queue.data?.priorities ?? [];
  const fichas = day.data?.data.appointments ?? [];
  const toCheckIn = fichas.filter((item) => item.status === 'CONFIRMED');
  const unpaid = fichas.filter((item) => item.status !== 'CANCELLED' && item.payment_status === 'PENDING').length;
  const waiting = rows.filter((item) => item.status === 'WAITING' || item.status === 'CALLED').length;

  const refresh = () => Promise.all([
    client.invalidateQueries({ queryKey: ['queue'] }),
    client.invalidateQueries({ queryKey: ['appointments'] }),
    client.invalidateQueries({ queryKey: ['queue-history'] }),
  ]);
  const checkIn = useMutation({
    mutationFn: (id: string) => api.checkInAppointment(id),
    onSuccess: async (_data, id) => {
      setNotice(`Check-in registrado para ${fichas.find((item) => item.id === id)?.patient_name ?? 'o paciente'}.`);
      await refresh();
    },
  });
  const call = useMutation({
    mutationFn: (item: QueueItem) => api.callQueue(item.id),
    onSuccess: async (_data, item) => {
      setNotice(`Chamando ${item.ticket} · ${item.patient_name ?? 'paciente'}${item.professional_name ? ` — ${item.professional_name}` : ''}.`);
      await refresh();
    },
  });
  const reorder = useMutation({
    mutationFn: () => api.reorderQueue(priorityFor, { priority_code: priorityCode || null, priority_reason: priorityReason }),
    onSuccess: async () => {
      setPriorityFor('');
      setPriorityCode('');
      setPriorityReason('');
      await refresh();
    },
  });

  return <div className="page reception-page">
    <div className="page-heading"><div><h1>Recepção</h1><p>Check-in, fila de hoje e chamadas — {waiting} aguardando</p></div></div>
    {notice ? <div className="toast" role="status"><Check size={16} />{notice}</div> : null}
    <div className="queue-tabs">
      <button className={tab === 'checkin' ? 'active' : ''} onClick={() => setParams({})}>Check-in</button>
      <button className={tab === 'fila' ? 'active' : ''} onClick={() => setParams({ fila: '1' })}>Fila de hoje</button>
    </div>
    <Panel>
      <div className="catalog-form-grid">
        <TextField label="Data" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
        <SelectField label="Unidade" value={unitId} onChange={(event) => setUnitId(event.target.value)}>
          <option value="">Todas</option>
          {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
        </SelectField>
      </div>
    </Panel>
    {(conflicts.data?.length ?? 0) > 0 ? <Alert tone="warning" title={`${conflicts.data?.length} pendência(s) de escala com agendamentos`}>Mudanças de escala afetaram fichas marcadas. Resolva com o médico ou o paciente na aba Ofertas e horários (pendências de escala).</Alert> : null}
    {tab === 'fila' ? <Panel className="queue-panel">
      <SectionTitle title="Fila de hoje" action={<b className="teal">{waiting} aguardando</b>} />
      {queue.isLoading ? <LoadingState label="Carregando fila" /> : null}
      {queue.isError ? <Alert tone="danger" title="Fila indisponível">{apiErrorMessage(queue.error)}</Alert> : null}
      {!queue.isLoading && rows.length === 0 ? <EmptyState title="Fila vazia" description="Fichas com check-in aparecem aqui na ordem de chegada, com a prioridade legal sugerida." /> : null}
      <div className="queue-list">{rows.map((item) => {
        const payment = paymentMeta(item.payment_status);
        const status = queueStatus[item.status] ?? { label: item.status, tone: 'info' as const };
        return <div className="queue-row" key={item.id}>
          <span className="queue-number">{item.ticket}</span>
          <span><strong>{item.patient_name ?? 'Paciente'}</strong><small>{item.procedure_name} · {item.professional_name ?? 'Sem profissional'} · {item.checked_in_at?.slice(11, 16) ?? '--:--'}</small></span>
          <span><StatusPill tone={payment.tone}>{payment.label}</StatusPill><StatusPill tone={status.tone}>{status.label}</StatusPill>{item.priority_label ? <StatusPill tone="purple">{item.priority_label}</StatusPill> : null}</span>
          <span className="queue-position">#{item.suggested_position}</span>
          <div className="catalog-actions">
            <Button variant="danger" icon={<BellRing size={16} />} disabled={!canCall || call.isPending || (item.status !== 'WAITING' && item.status !== 'CALLED')} onClick={() => call.mutate(item)}>Chamar</Button>
            {canManage ? <Button variant="outline" onClick={() => { setPriorityFor(item.id); setPriorityCode(item.priority_code ?? ''); setPriorityReason(''); }}>Prioridade</Button> : null}
          </div>
          {priorityFor === item.id ? <form className="schedule-form" onSubmit={(event) => { event.preventDefault(); reorder.mutate(); }}>
            <div className="catalog-form-grid">
              <SelectField label="Prioridade legal" value={priorityCode} onChange={(event) => setPriorityCode(event.target.value)}>
                <option value="">Sem prioridade (ordem de chegada)</option>
                {priorities.map((priority) => <option key={priority.code} value={priority.code}>{priority.label}</option>)}
              </SelectField>
              <TextField label="Motivo (auditoria)" value={priorityReason} minLength={3} required onChange={(event) => setPriorityReason(event.target.value)} />
            </div>
            {reorder.isError ? <Alert tone="danger" title="Prioridade não aplicada">{apiErrorMessage(reorder.error)}</Alert> : null}
            <div className="catalog-form-actions"><Button type="button" variant="outline" onClick={() => setPriorityFor('')}>Cancelar</Button><Button type="submit" disabled={reorder.isPending || priorityReason.trim().length < 3}>Confirmar ordem</Button></div>
          </form> : null}
        </div>;
      })}</div>
      {call.isError ? <Alert tone="danger" title="Chamada não registrada">{apiErrorMessage(call.error)}</Alert> : null}
    </Panel> : <Panel className="checkin-panel">
      <SectionTitle title="Fazer check-in" action={<b className="teal">{toCheckIn.length} fichas confirmadas</b>} />
      {day.isLoading ? <LoadingState label="Buscando fichas do dia" /> : null}
      {day.isError ? <Alert tone="danger" title="Fichas indisponíveis">{apiErrorMessage(day.error)}</Alert> : null}
      {!day.isLoading && toCheckIn.length === 0 ? <EmptyState title="Nenhuma ficha para check-in" description="Agendamentos confirmados do dia aparecem aqui para dar entrada na fila." /> : null}
      <div className="queue-list">{toCheckIn.map((item) => {
        const payment = paymentMeta(item.payment_status);
        return <div className="queue-row" key={item.id}>
          <span><strong>{item.patient_name ?? 'Paciente'}</strong><small>{item.scheduled_at.slice(11, 16)} · {item.procedure.name} · {item.professional?.name} · {item.agreement?.name}</small></span>
          <StatusPill tone={payment.tone}>{payment.label}</StatusPill>
          <div className="catalog-actions"><Button variant="success" icon={<UserRoundPlus size={16} />} disabled={!canManage || checkIn.isPending} onClick={() => checkIn.mutate(item.id)}>Fazer check-in</Button></div>
        </div>;
      })}</div>
      {checkIn.isError ? <Alert tone="danger" title="Check-in não registrado">{apiErrorMessage(checkIn.error)}</Alert> : null}
    </Panel>}
    <div className="rooms-stack">
      <Panel><SectionTitle title="Últimas chamadas" />
        {(history.data ?? []).length === 0 ? <p>Nenhuma chamada registrada hoje.</p> : null}
        {(history.data ?? []).slice(0, 5).map((item) => <div className="room-row" key={item.id}><div><strong>{item.ticket}</strong><span>{item.patient_name ?? 'Paciente'} · {item.procedure_name}</span></div><small>{item.called_at?.slice(11, 16)}</small></div>)}
      </Panel>
      <Panel className="reception-alerts"><SectionTitle title="Alertas" />
        {unpaid > 0 ? <Alert tone="danger" title="Fichas sem pagamento">{unpaid} ficha(s) do dia sem pagamento registrado — o atendimento só inicia com caixa ou B2B.</Alert> : <Alert title="Pagamentos em dia">Nenhuma ficha do dia sem pagamento ou autorização.</Alert>}
      </Panel>
    </div>
  </div>;
}
