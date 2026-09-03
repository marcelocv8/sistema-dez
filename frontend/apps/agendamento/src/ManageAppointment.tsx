import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiErrorMessage, publicApi } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, LoadingState, SelectField, TextField } from '@dez/ui';

export function ManageAppointment({ id, token }: { id: string; token: string }) {
  return <PrivateAppointment key={`${id}:${token}`} id={id} token={token} />;
}

function PrivateAppointment({ id, token }: { id: string; token: string }) {
  const client = useQueryClient();
  const [accessKey] = useState(() => crypto.randomUUID());
  const [startsAt, setStartsAt] = useState('');
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [message, setMessage] = useState('');
  const appointment = useQuery({ queryKey: ['patient-appointment', accessKey], queryFn: () => publicApi.appointment(id, token), retry: false, gcTime: 0 });
  const availability = useQuery({ queryKey: ['patient-availability', accessKey], queryFn: () => publicApi.managementAvailability(id, token), enabled: Boolean(appointment.data?.can_reschedule), retry: false, gcTime: 0 });
  const refresh = async (text: string) => { setMessage(text); setConfirmed(false); setStartsAt(''); await Promise.all([client.invalidateQueries({ queryKey: ['patient-appointment', accessKey] }), client.invalidateQueries({ queryKey: ['patient-availability', accessKey] })]); };
  const reschedule = useMutation({ mutationFn: () => publicApi.reschedule(id, token, startsAt, reason), onSuccess: () => refresh('Agendamento remarcado com sucesso.') });
  const cancel = useMutation({ mutationFn: () => publicApi.cancel(id, token, reason), onSuccess: () => refresh('Agendamento cancelado. Sua vaga foi liberada.') });
  const data = appointment.data;
  const busy = reschedule.isPending || cancel.isPending;
  if (appointment.isLoading) return <LoadingState label="Consultando seu agendamento" />;
  if (appointment.isError || !data) return <section><h1>Não foi possível acessar</h1><Alert tone="danger" title="Link inválido ou expirado">Confira o link salvo na confirmação. Se precisar, procure a recepção.</Alert><Button onClick={() => { location.hash = ''; }}>Voltar aos serviços</Button></section>;
  return <section><p className="eyebrow">Acesso privado pelo seu link</p><h1>Gerenciar agendamento</h1>
    <div className="receipt"><div><span>Serviço</span><b>{data.service.procedure_name}</b></div><div><span>Profissional</span><b>{data.professional?.name ?? 'Equipe'}</b></div><div><span>Unidade / convênio</span><b>{data.unit.name} · {data.agreement.name}</b></div><div><span>Data e hora</span><b>{new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short', timeZone: 'America/Fortaleza' }).format(new Date(data.scheduled_at))}</b></div><div><span>Valor reservado</span><b>{formatMoney(data.price)}</b></div><div><span>Situação</span><b>{data.status === 'CANCELLED' ? 'Cancelado' : data.status === 'CONFIRMED' ? 'Confirmado' : 'Em atendimento ou concluído'}</b></div></div>
    {data.booking_mode === 'ARRIVAL_BLOCK' ? <p>Atendimento por ordem de chegada no bloco reservado, não por horário individual.</p> : null}
    {message ? <p role="status">{message}</p> : null}
    {data.can_reschedule ? <><h2>Escolher outro horário</h2><p>A remarcação mantém o serviço e usa o preço vigente da oferta no momento da confirmação.</p><SelectField label="Novo horário" value={startsAt} disabled={availability.isFetching || busy} onChange={(event) => setStartsAt(event.target.value)}><option value="">Selecione</option>{availability.data?.flatMap((row) => row.slots).filter((slot) => slot.starts_at !== data.scheduled_at).map((slot) => <option key={slot.starts_at} value={slot.starts_at}>{new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Fortaleza' }).format(new Date(slot.starts_at))} · {slot.remaining_capacity} vaga(s)</option>)}</SelectField>{availability.isError ? <Alert tone="danger" title="Horários indisponíveis">{apiErrorMessage(availability.error)}</Alert> : null}</> : null}
    {data.can_cancel ? <><TextField label="Motivo da alteração" value={reason} minLength={3} maxLength={1000} onChange={(event) => setReason(event.target.value)} /><label className="consent"><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /><span>Confirmo a alteração ou o cancelamento deste agendamento.</span></label><div className="form-actions">{data.can_reschedule ? <Button disabled={!startsAt || !confirmed || reason.trim().length < 3 || busy} onClick={() => reschedule.mutate()}>Confirmar remarcação</Button> : null}<Button variant="danger" disabled={!confirmed || reason.trim().length < 3 || busy} onClick={() => cancel.mutate()}>Cancelar agendamento</Button></div></> : <p>Este agendamento não pode mais ser alterado online.</p>}
    {reschedule.isError || cancel.isError ? <Alert tone="danger" title="Alteração não concluída">{apiErrorMessage(reschedule.error ?? cancel.error)}</Alert> : null}
    <p className="privacy-note">Não compartilhe este link: ele permite consultar e alterar esta reserva. O acesso expira 24 horas após o horário agendado.</p>
    <Button variant="outline" onClick={() => { location.hash = ''; }}>Voltar aos serviços</Button>
  </section>;
}
