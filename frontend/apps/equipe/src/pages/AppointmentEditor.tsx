import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { api, apiErrorMessage, type Appointment, type ServiceOffering } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, Panel, SelectField, TextField } from '@dez/ui';

export function AppointmentEditor({ appointment, initialDate, offerings, onClose, onSaved }: { appointment?: Appointment; initialDate: string; offerings: ServiceOffering[]; onClose: () => void; onSaved: () => Promise<unknown> }) {
  const [offeringId, setOfferingId] = useState(appointment?.offering_id ?? '');
  const [date, setDate] = useState(initialDate);
  const [startsAt, setStartsAt] = useState('');
  const [reason, setReason] = useState('');
  const [authCode, setAuthCode] = useState('');
  const [consent, setConsent] = useState(false);
  const [idempotencyKey] = useState(() => crypto.randomUUID());
  const [patient, setPatient] = useState({ name: '', cpf: '', phone: '', birth_date: '', email: '' });
  const offering = offerings.find((item) => item.id === offeringId);
  const availability = useQuery({ queryKey: ['team-availability', offeringId, date, appointment?.id], queryFn: () => api.teamAvailability({ offering_id: offeringId, from: date, to: date, exclude_appointment_id: appointment?.id }), enabled: Boolean(offeringId && date) });
  const slots = (availability.data ?? []).flatMap((row) => row.slots).filter((slot) => new Date(slot.starts_at).getTime() > Date.now());
  const lookup = useMutation({ mutationFn: () => api.lookupPatient(patient.cpf), onSuccess: (found) => { if (found) setPatient((current) => ({ ...current, name: found.name, phone: found.phone ?? '', email: found.email ?? '', birth_date: found.birth_date?.slice(0, 10) ?? '' })); } });
  const save = useMutation({ mutationFn: () => appointment
    ? api.rescheduleAppointment(appointment.id, { offering_id: offeringId, starts_at: startsAt, reason })
    : api.bookInternal({ idempotency_key: idempotencyKey, offering_id: offeringId, starts_at: startsAt, patient: { ...patient, birth_date: patient.birth_date || undefined, email: patient.email || undefined }, privacy_consent: true }),
  onSuccess: async () => { await onSaved(); onClose(); } });
  const cancel = useMutation({ mutationFn: () => api.cancelAppointment(appointment!.id, reason), onSuccess: async () => { await onSaved(); onClose(); } });
  const checkIn = useMutation({ mutationFn: () => api.checkInAppointment(appointment!.id), onSuccess: () => onSaved() });
  const startCare = useMutation({ mutationFn: () => api.startCareAppointment(appointment!.id), onSuccess: () => onSaved() });
  const authorizeB2B = useMutation({ mutationFn: () => api.authorizeB2BAppointment(appointment!.id, authCode.trim()), onSuccess: () => onSaved() });
  const isB2B = Boolean(appointment?.agreement && appointment.agreement.is_default === false);
  const canAuthorizeB2B = Boolean(appointment && isB2B && appointment.payment_status === 'PENDING' && (appointment.status === 'CONFIRMED' || appointment.status === 'CHECKED_IN'));
  const editable = !appointment || appointment.status === 'CONFIRMED';
  const canStartCare = appointment?.status === 'CHECKED_IN' && (appointment.payment_status === 'PAID' || appointment.payment_status === 'AUTHORIZED');

  return <Panel className="appointment-editor"><div className="schedule-editor-heading"><h2>{appointment ? `Agendamento de ${appointment.patient_name ?? 'paciente'}` : 'Novo agendamento interno'}</h2><Button variant="outline" onClick={onClose}>Fechar</Button></div>
    {appointment ? <div className="visit-flow">
      <p role="status">Pagamento: <strong>{appointment.payment_status === 'PAID' ? `Pago${appointment.receipt_number ? ` (recibo ${appointment.receipt_number})` : ''}` : appointment.payment_status === 'AUTHORIZED' ? 'Autorizado B2B (fatura no fim do mês)' : 'Pendente — cobrar no caixa antes do atendimento'}</strong>{appointment.care_started_at ? ' · Em atendimento' : ''}</p>
      {appointment.status === 'CONFIRMED' ? <div className="catalog-form-actions"><Button disabled={checkIn.isPending} onClick={() => checkIn.mutate()}>{checkIn.isPending ? 'Registrando...' : 'Fazer check-in (chegada)'}</Button></div> : null}
      {appointment.status === 'CHECKED_IN' && !appointment.care_started_at ? <div className="catalog-form-actions"><Button disabled={!canStartCare || startCare.isPending} onClick={() => startCare.mutate()}>{startCare.isPending ? 'Iniciando...' : 'Iniciar atendimento'}</Button></div> : null}
      {appointment.status === 'CHECKED_IN' && !canStartCare && !appointment.care_started_at ? <p>O atendimento só inicia com pagamento confirmado no caixa ou autorização B2B.</p> : null}
      {canAuthorizeB2B ? <div className="catalog-form-actions">
        <TextField label="Código de autorização da empresa" value={authCode} minLength={3} required onChange={(event) => setAuthCode(event.target.value)} />
        <Button disabled={authCode.trim().length < 3 || authorizeB2B.isPending} onClick={() => authorizeB2B.mutate()}>{authorizeB2B.isPending ? 'Autorizando...' : 'Autorizar B2B (fatura no fim do mês)'}</Button>
      </div> : null}
      {isB2B && appointment?.payment_status === 'PENDING' && !canAuthorizeB2B ? <p>Autorização B2B disponível após a confirmação da ficha.</p> : null}
      {checkIn.isError || startCare.isError || authorizeB2B.isError ? <Alert tone="danger" title="Ação não concluída">{apiErrorMessage(checkIn.error ?? startCare.error ?? authorizeB2B.error)}</Alert> : null}
    </div> : null}
    {!editable ? <p>Este agendamento está {appointment?.status === 'CANCELLED' ? 'cancelado' : 'em andamento ou concluído'} e não pode ser alterado pela agenda. {appointment?.cancellation_reason}</p> : <form className="schedule-form" onSubmit={(event) => { event.preventDefault(); save.mutate(); }}>
      <div className="catalog-form-grid">
        <SelectField label="Oferta de atendimento" value={offeringId} required onChange={(event) => { setOfferingId(event.target.value); setStartsAt(''); }}><option value="">Selecione</option>{offerings.filter((item) => item.active).map((item) => <option key={item.id} value={item.id}>{item.procedure.name} · {item.professional?.name} · {item.agreement.name}</option>)}</SelectField>
        <TextField label="Data do agendamento" type="date" value={date} required onChange={(event) => { setDate(event.target.value); setStartsAt(''); }} />
        <SelectField label="Horário disponível" value={startsAt} required onChange={(event) => setStartsAt(event.target.value)} disabled={availability.isFetching}><option value="">{availability.isFetching ? 'Consultando vagas...' : 'Selecione um horário'}</option>{slots.map((slot) => <option value={slot.starts_at} key={slot.starts_at}>{slot.starts_at.slice(11, 16)} · {slot.remaining_capacity} vaga(s)</option>)}</SelectField>
        {offering ? <p className="schedule-price">{offering.unit.name}<br />Preço de tabela: <strong>{formatMoney(offering.price)}</strong></p> : null}
      </div>
      {offering?.booking_mode === 'WALK_IN_INFO' ? <Alert tone="info" title="Sem reserva de horário">Este serviço atende por chegada. A entrada em fila será habilitada na fase de Recepção.</Alert> : null}
      {offeringId && !availability.isFetching && slots.length === 0 ? <EmptyState title="Nenhuma vaga disponível nesta data" description="Escolha outra data ou solicite a revisão da escala." /> : null}
      {availability.isError ? <Alert tone="danger" title="Disponibilidade indisponível">{apiErrorMessage(availability.error)}</Alert> : null}
      {!appointment ? <><div className="patient-lookup"><TextField label="CPF do paciente" required value={patient.cpf} onChange={(event) => { setPatient((current) => ({ ...current, cpf: event.target.value })); lookup.reset(); }} /><Button type="button" variant="outline" disabled={patient.cpf.replace(/\D/g, '').length !== 11 || lookup.isPending} onClick={() => lookup.mutate()}>Localizar paciente</Button></div>
        {lookup.isSuccess ? <p role="status">{lookup.data ? 'Paciente encontrado. Dados preenchidos.' : 'Paciente não encontrado. Preencha os dados para cadastrá-lo.'}</p> : null}
        {lookup.isError ? <Alert tone="danger" title="Falha na busca">{apiErrorMessage(lookup.error)}</Alert> : null}
        <div className="catalog-form-grid"><TextField label="Nome do paciente" required value={patient.name} onChange={(event) => setPatient((current) => ({ ...current, name: event.target.value }))} /><TextField label="Telefone do paciente" required value={patient.phone} onChange={(event) => setPatient((current) => ({ ...current, phone: event.target.value }))} /><TextField label="Nascimento" type="date" value={patient.birth_date} onChange={(event) => setPatient((current) => ({ ...current, birth_date: event.target.value }))} /><TextField label="E-mail do paciente" type="email" value={patient.email} onChange={(event) => setPatient((current) => ({ ...current, email: event.target.value }))} /></div>
        <label className="catalog-check"><input type="checkbox" required checked={consent} onChange={(event) => setConsent(event.target.checked)} />Paciente informado sobre o uso dos dados para o agendamento.</label>
      </> : <TextField label="Motivo da alteração ou cancelamento" value={reason} minLength={3} required onChange={(event) => setReason(event.target.value)} />}
      {save.isError || cancel.isError ? <Alert tone="danger" title="Não foi possível concluir">{apiErrorMessage(save.error ?? cancel.error)}</Alert> : null}
      <div className="catalog-form-actions">{appointment ? <Button type="button" variant="danger" disabled={reason.trim().length < 3 || cancel.isPending || save.isPending} onClick={() => cancel.mutate()}>Cancelar agendamento</Button> : null}<Button type="submit" disabled={!startsAt || save.isPending || cancel.isPending || (!appointment && !consent)}>{save.isPending ? 'Salvando...' : appointment ? 'Remarcar agendamento' : 'Confirmar agendamento interno'}</Button></div>
    </form>}
  </Panel>;
}
