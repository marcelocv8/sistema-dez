import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type ProntuarioNote } from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, StatusPill, TextField } from '@dez/ui';

const dateLabel = (value: string | null) => (value ? new Date(value).toLocaleDateString('pt-BR', { timeZone: 'America/Fortaleza' }) : '—');
const noteKindLabel: Record<string, string> = { ANAMNESE: 'Anamnese', EVOLUCAO: 'Evolução', INTERCORRENCIA: 'Intercorrência', ALTA: 'Alta' };
const alertKindLabel: Record<string, string> = { ALERGIA: 'Alergia', CONDICAO: 'Condição', OUTRO: 'Outro' };

export default function ProntuarioPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [cpf, setCpf] = useState('');
  const [patient, setPatient] = useState<{ id: string; name: string } | null>(null);
  const [noteFor, setNoteFor] = useState('');
  const [noteKind, setNoteKind] = useState('EVOLUCAO');
  const [editing, setEditing] = useState<ProntuarioNote | null>(null);
  const [alertTitle, setAlertTitle] = useState('');
  const [alertDetail, setAlertDetail] = useState('');
  const [alertKind, setAlertKind] = useState('ALERGIA');
  const [deactivateFor, setDeactivateFor] = useState('');
  const [deactivateReason, setDeactivateReason] = useState('');
  const [aiQuestion, setAiQuestion] = useState('');
  const [statusMessage, setStatusMessage] = useState('');
  const noteRef = useRef<HTMLDivElement>(null);
  const patientEpoch = useRef(0);
  const canWrite = user.permissions.includes('prontuario.write');
  const canAi = user.permissions.includes('ai.use');

  const lookup = useMutation({
    mutationFn: () => api.chatLookupPatient(cpf.replace(/\D/g, '')),
    onSuccess: (found) => { setPatient(found); setStatusMessage(''); },
  });
  const timeline = useQuery({
    queryKey: ['prontuario', patient?.id],
    queryFn: () => api.prontuarioTimeline(patient!.id),
    enabled: patient !== null,
  });
  const aiStatus = useQuery({ queryKey: ['ai-status'], queryFn: api.aiStatus, enabled: canAi });
  const aiEnabled = (aiStatus.data?.enabled ?? false) && canAi;

  useEffect(() => {
    if (noteRef.current) noteRef.current.innerHTML = '';
    setEditing(null);
    setNoteFor(timeline.data?.appointments[0]?.id ?? '');
  }, [patient?.id, timeline.data?.appointments.length]);

  const refresh = () => client.invalidateQueries({ queryKey: ['prontuario'] });
  const saveNote = useMutation({
    mutationFn: () => {
      const html = noteRef.current?.innerHTML ?? '';
      return editing !== null ? api.updateProntuarioNote(editing.id, html) : api.createProntuarioNote({ appointment_id: noteFor, kind: noteKind, content_html: html });
    },
    onSuccess: async () => {
      if (noteRef.current) noteRef.current.innerHTML = '';
      setEditing(null);
      setStatusMessage('Nota registrada no prontuário.');
      await refresh();
    },
  });
  const addAlert = useMutation({
    mutationFn: () => api.addProntuarioAlert({ patient_id: patient!.id, kind: alertKind, title: alertTitle, detail: alertDetail || undefined }),
    onSuccess: async () => { setAlertTitle(''); setAlertDetail(''); setStatusMessage('Alerta incluído.'); await refresh(); },
  });
  const deactivateAlert = useMutation({
    mutationFn: () => api.deactivateProntuarioAlert(deactivateFor, deactivateReason),
    onSuccess: async () => { setDeactivateFor(''); setDeactivateReason(''); setStatusMessage('Alerta inativado (histórico preservado).'); await refresh(); },
  });
  const aiSuggest = useMutation({
    mutationFn: async (input: { patientId: string; appointmentId: string; question: string; epoch: number }) => ({
      ...await api.aiSuggest({ patient_id: input.patientId, appointment_id: input.appointmentId || undefined, question: input.question }),
      patientId: input.patientId, epoch: input.epoch,
    }),
    onSuccess: () => setStatusMessage('Sugestão de IA gerada — revise antes de usar.'),
  });
  const suggestion = aiSuggest.data?.patientId === patient?.id && aiSuggest.data?.epoch === patientEpoch.current ? aiSuggest.data : null;
  const insertSuggestion = () => {
    if (!suggestion || !noteRef.current) return;
    noteRef.current.innerHTML = suggestion!.suggestion_html + (noteRef.current.innerHTML ?? '');
    noteRef.current.focus();
    setStatusMessage('Sugestão inserida como rascunho na nota — revise e salve.');
  };

  const data = timeline.data;
  const activeAlerts = data?.alerts.filter((alert) => alert.active) ?? [];
  const pastAlerts = data?.alerts.filter((alert) => !alert.active) ?? [];
  const appointments = data?.appointments ?? [];

  return <div className="page prontuario-page">
    <div className="page-heading"><div>
      <h1>Prontuário</h1>
      <p>Histórico cronológico com alertas, notas, laudos e atendimentos — leitura e edição auditadas (retenção 20 anos).</p>
    </div></div>

    <Panel>
      <div className="patient-lookup">
        <TextField label="CPF do paciente" value={cpf} onChange={(event) => { patientEpoch.current++; aiSuggest.reset(); setAiQuestion(''); setAlertTitle(''); setAlertDetail(''); setDeactivateFor(''); setStatusMessage(''); setCpf(event.target.value); setPatient(null); }} />
        <Button type="button" variant="outline" disabled={cpf.replace(/\D/g, '').length !== 11 || lookup.isPending} onClick={() => lookup.mutate()}>Localizar</Button>
        {patient ? <p><strong>Paciente:</strong> {patient.name}{data?.patient.birth_date ? ` · ${data.patient.birth_date}` : ''}</p> : null}
      </div>
      {lookup.isError ? <Alert tone="danger" title="Paciente não encontrado">{apiErrorMessage(lookup.error)}</Alert> : null}
      {timeline.isLoading ? <LoadingState label="Abrindo prontuário" /> : null}
      {timeline.isError ? <Alert tone="danger" title="Prontuário indisponível">{apiErrorMessage(timeline.error)}</Alert> : null}
    </Panel>

    {data ? <>
      <Panel>
        <h2>Alertas ({activeAlerts.length} ativos)</h2>
        <div className="alert-chips">
          {activeAlerts.map((alert) => <span key={alert.id} className={`alert-chip alert-chip--${alert.kind.toLowerCase()}`}>
            <strong>{alertKindLabel[alert.kind] ?? alert.kind}:</strong> {alert.title}{alert.detail ? <small> — {alert.detail}</small> : null}
            {canWrite ? <button type="button" aria-label={`Inativar alerta ${alert.title}`} onClick={() => { setDeactivateFor(alert.id); setDeactivateReason(''); }}>×</button> : null}
          </span>)}
          {activeAlerts.length === 0 ? <p className="muted">Sem alertas ativos.</p> : null}
        </div>
        {canWrite ? <div className="catalog-form-grid">
          <TextField label="Novo alerta" value={alertTitle} minLength={2} onChange={(event) => setAlertTitle(event.target.value)} placeholder="Ex.: Penicilina" />
          <TextField label="Detalhe (opcional)" value={alertDetail} onChange={(event) => setAlertDetail(event.target.value)} placeholder="Reação observada" />
        </div> : null}
        {canWrite ? <div className="catalog-form-actions">
          <Button variant="success" disabled={alertTitle.trim().length < 2 || addAlert.isPending} onClick={() => addAlert.mutate()}>Incluir alerta</Button>
        </div> : null}
        {pastAlerts.length > 0 ? <details><summary>Inativos ({pastAlerts.length})</summary>
          <ul className="event-trail">{pastAlerts.map((alert) => <li key={alert.id}>
            {alertKindLabel[alert.kind] ?? alert.kind}: {alert.title} — inativado por {alert.deactivated_by?.name ?? '—'} ({dateLabel(alert.deactivated_at)}) — {alert.deactivation_reason}
          </li>)}</ul>
        </details> : null}
        {addAlert.isError ? <Alert tone="danger" title="Alerta não incluído">{apiErrorMessage(addAlert.error)}</Alert> : null}
        {deactivateFor !== '' ? <div className="catalog-form-actions">
          <TextField label="Motivo da inativação" value={deactivateReason} minLength={3} onChange={(event) => setDeactivateReason(event.target.value)} />
          <Button disabled={deactivateReason.trim().length < 3 || deactivateAlert.isPending} onClick={() => deactivateAlert.mutate()}>Inativar</Button>
          <Button variant="outline" onClick={() => setDeactivateFor('')}>Cancelar</Button>
        </div> : null}
        {deactivateAlert.isError ? <Alert tone="danger" title="Inativação falhou">{apiErrorMessage(deactivateAlert.error)}</Alert> : null}
      </Panel>

      <Panel>
        <h2>Notas do prontuário ({data.notes.length})</h2>
        {canWrite && appointments.length > 0 ? <div className="catalog-form-grid">
          <TextField label="Consulta" value={noteFor} onChange={(event) => setNoteFor(event.target.value)} placeholder="ID da consulta" />
          <TextField label="Tipo" value={noteKind} onChange={(event) => setNoteKind(event.target.value)} />
        </div> : null}
        {canWrite && appointments.length > 0 ? <div className="report-toolbar">
          <Button type="button" variant="outline" onClick={() => { noteRef.current?.focus(); document.execCommand('bold'); }}><strong>B</strong></Button>
          <Button type="button" variant="outline" onClick={() => { noteRef.current?.focus(); document.execCommand('italic'); }}><em>I</em></Button>
        </div> : null}
        {canWrite && appointments.length > 0 ? <div className="report-editor" ref={noteRef} contentEditable suppressContentEditableWarning aria-label="Nova nota do prontuário" /> : null}
        {canWrite && appointments.length > 0 ? <div className="catalog-form-actions">
          <Button variant="success" disabled={saveNote.isPending || noteFor === ''} onClick={() => saveNote.mutate()}>
            {editing !== null ? 'Salvar edição' : 'Registrar nota'}
          </Button>
          {editing !== null ? <Button variant="outline" onClick={() => setEditing(null)}>Cancelar edição</Button> : null}
        </div> : null}
        {saveNote.isError ? <Alert tone="danger" title="Nota não salva">{apiErrorMessage(saveNote.error)}</Alert> : null}
        {aiEnabled ? <div className="ai-panel">
          <h3>Assistente clínico (IA) — opcional</h3>
          <p className="muted">Sugestão automática a partir do contexto do paciente. Nunca substitui o julgamento médico; entra sempre como rascunho marcado.</p>
          <TextField label="Pergunta ao assistente" value={aiQuestion} minLength={5} onChange={(event) => setAiQuestion(event.target.value)} placeholder="Ex.: conduta para cefaleia tensional?" />
          <div className="catalog-form-actions">
            <Button disabled={aiQuestion.trim().length < 5 || aiSuggest.isPending} onClick={() => aiSuggest.mutate({ patientId: patient!.id, appointmentId: noteFor, question: aiQuestion, epoch: patientEpoch.current })}>{aiSuggest.isPending ? 'Consultando...' : 'Sugerir conduta (IA)'}</Button>
            {suggestion ? <Button variant="outline" onClick={insertSuggestion}>Inserir no prontuário</Button> : null}
          </div>
          {suggestion ? <div className="report-editor ai-output" dangerouslySetInnerHTML={{ __html: suggestion!.suggestion_html }} /> : null}
          {aiSuggest.isError ? <Alert tone="danger" title="IA indisponível">{apiErrorMessage(aiSuggest.error)}</Alert> : null}
        </div> : null}
        {data.notes.length === 0 ? <EmptyState title="Sem notas" description="As evoluções e anamneses aparecem aqui." /> : null}
        <div className="catalog-list-panel">
          {data.notes.map((note) => <div className="catalog-row" key={note.id}>
            <span>
              <strong>{noteKindLabel[note.kind] ?? note.kind}</strong>
              <small>{dateLabel(note.created_at)} · {note.created_by?.name ?? '—'}{note.updated_at ? ` · editada ${dateLabel(note.updated_at)}` : ''} · expurgo {dateLabel(note.retention_until)}</small>
              <small dangerouslySetInnerHTML={{ __html: note.content_html }} />
            </span>
            {canWrite ? <div className="catalog-actions">
              <Button variant="outline" onClick={() => { setEditing(note); setNoteFor(note.appointment_id ?? noteFor); if (noteRef.current) noteRef.current.innerHTML = note.content_html; noteRef.current?.focus(); }}>Editar</Button>
            </div> : null}
          </div>)}
        </div>
      </Panel>

      <Panel>
        <h2>Laudos ({data.reports.length})</h2>
        {data.reports.length === 0 ? <EmptyState title="Sem laudos" description="Crie laudos na página de Laudos." /> : null}
        <div className="catalog-list-panel">
          {data.reports.map((report) => <div className="catalog-row" key={report.id}>
            <span>
              <strong>{report.procedure?.name ?? 'Laudo'}</strong>
              <small>{dateLabel(report.created_at)} · {report.doctor?.name ?? '—'} · {report.print_count} impressão(ões){report.signed_manual ? ' · assinado' : ''}</small>
            </span>
            <StatusPill tone={report.status === 'FINALIZADO' ? 'success' : 'warning'}>{report.status}{report.portal_published ? ' · portal' : ''}</StatusPill>
            <div className="catalog-actions"><Button variant="outline" onClick={() => { window.location.href = `${import.meta.env.BASE_URL}laudos`; }}>Abrir em Laudos</Button></div>
          </div>)}
        </div>
      </Panel>

      <Panel>
        <h2>Atendimentos ({appointments.length})</h2>
        <div className="catalog-list-panel">
          {appointments.map((appointment) => <div className="catalog-row" key={appointment.id}>
            <span>
              <strong>{appointment.procedure?.name ?? 'Consulta'}</strong>
              <small>{dateLabel(appointment.scheduled_at)} · {appointment.professional?.name ?? '—'} · {appointment.unit?.name ?? '—'} · origem {appointment.origin}</small>
            </span>
            <StatusPill tone={appointment.payment_status === 'PAID' || appointment.payment_status === 'AUTHORIZED' ? 'success' : 'warning'}>
              {appointment.payment_status}{appointment.receipt_number !== null ? ` · recibo ${appointment.receipt_number}` : ''}
            </StatusPill>
          </div>)}
        </div>
      </Panel>
    </> : null}

    {statusMessage ? <p role="status" className="muted">{statusMessage}</p> : null}
  </div>;
}
