import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type Report, type ReportTemplateItem } from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, StatusPill, TextField } from '@dez/ui';

const clinicToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
const dateLabel = (value: string | null) => (value ? new Date(value).toLocaleDateString('pt-BR', { timeZone: 'America/Fortaleza' }) : '—');

export default function LaudosPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [cpf, setCpf] = useState('');
  const [patient, setPatient] = useState<{ id: string; name: string } | null>(null);
  const [selected, setSelected] = useState<Report | null>(null);
  const [statusMessage, setStatusMessage] = useState('');
  const [adendoFor, setAdendoFor] = useState('');
  const [adendoText, setAdendoText] = useState('');
  const [revokeFor, setRevokeFor] = useState('');
  const [revokeReason, setRevokeReason] = useState('');
  const editorRef = useRef<HTMLDivElement>(null);
  const canWrite = user.permissions.includes('reports.write');
  const canSign = user.permissions.includes('reports.sign');

  const lookup = useMutation({
    mutationFn: () => api.chatLookupPatient(cpf.replace(/\D/g, '')),
    onSuccess: async (found) => {
      setPatient(found);
      setSelected(null);
      setStatusMessage('');
    },
  });
  const history = useQuery({
    queryKey: ['report-history', patient?.id],
    queryFn: () => api.reportHistory(patient!.id),
    enabled: patient !== null,
  });
  const appointments = useQuery({
    queryKey: ['laudo-appointments', patient?.id],
    queryFn: async () => {
      const from = new Date(Date.now() - 60 * 24 * 3600 * 1000).toISOString().slice(0, 10);
      const to = new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString().slice(0, 10);
      return (await api.appointments(undefined, { patient_id: patient!.id, from, to })).data;
    },
    enabled: patient !== null,
  });
  const templates = useQuery({ queryKey: ['report-templates'], queryFn: () => api.reportTemplates({ legacy_type: 'LAUDO' }) });
  const lines = useQuery({ queryKey: ['report-line-templates'], queryFn: () => api.reportTemplates({ legacy_type: 'LINHA' }) });

  useEffect(() => {
    if (editorRef.current && selected) {
      editorRef.current.innerHTML = selected.content_html ?? '';
    }
  }, [selected?.id]);

  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ['report-history'] });
    if (selected) {
      const fresh = await api.reportHistory(patient?.id ?? '');
      const updated = fresh.find((report) => report.id === selected.id) ?? null;
      setSelected(updated);
      if (updated) {
        editorRef.current!.innerHTML = updated.content_html ?? '';
      }
    }
  };

  const create = useMutation({
    mutationFn: (input: { appointment_id: string; template_id: string }) => api.createReport(input),
    onSuccess: async (report) => {
      await refresh();
      setSelected(report);
      setStatusMessage('Laudo criado a partir do modelo.');
    },
  });
  const save = useMutation({
    mutationFn: () => api.saveReport(selected!.id, editorRef.current?.innerHTML ?? ''),
    onSuccess: async () => { await refresh(); setStatusMessage('Rascunho salvo.'); },
  });
  const finalize = useMutation({
    mutationFn: () => api.finalizeReport(selected!.id),
    onSuccess: async (report) => { await refresh(); setSelected(report); setStatusMessage('Laudo finalizado — pode imprimir para o médico revisar e assinar no papel.'); },
  });
  const append = useMutation({
    mutationFn: () => api.appendReport(selected!.id, adendoText),
    onSuccess: async (report) => { setAdendoFor(''); setAdendoText(''); await refresh(); setSelected(report); setStatusMessage('Adendo registrado.'); },
  });
  const sign = useMutation({
    mutationFn: () => api.signReportManual(selected!.id),
    onSuccess: async (report) => { await refresh(); setSelected(report); setStatusMessage('Assinatura manual registrada.'); },
  });
  const printHtml = (html: string) => {
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    document.body.appendChild(frame);
    frame.srcdoc = html;
    frame.onload = () => {
      frame.contentWindow?.focus();
      frame.contentWindow?.print();
      window.setTimeout(() => frame.remove(), 60_000);
    };
  };
  const publishPortal = useMutation({
    mutationFn: () => api.publishReportPortal(selected!.id),
    onSuccess: async ({ folha_html, login, password }) => {
      await refresh();
      setStatusMessage(password !== null
        ? `Publicado no portal. Credencial: ${login} / ${password} — imprima a folha de acesso.`
        : `Publicado no portal com a credencial ativa (${login}).`);
      printHtml(folha_html);
    },
  });
  const printFolha = useMutation({
    mutationFn: () => api.reportPortalFolha(selected!.id),
    onSuccess: ({ folha_html }) => printHtml(folha_html),
  });
  const revokePortal = useMutation({
    mutationFn: () => api.revokeReportPortal(selected!.id, revokeReason),
    onSuccess: async () => { setRevokeFor(''); setRevokeReason(''); await refresh(); setStatusMessage('Publicação revogada.'); },
  });
  const print = useMutation({
    mutationFn: () => api.printReport(selected!.id),
    onSuccess: async ({ print_html }) => {
      await refresh();
      printHtml(print_html);
    },
  });

  const exec = (command: string) => {
    editorRef.current?.focus();
    document.execCommand(command);
  };
  const insertLine = (line: ReportTemplateItem) => {
    if (!line.content_html) return;
    editorRef.current?.focus();
    document.execCommand('insertHTML', false, line.content_html);
  };

  const historyList = history.data ?? [];
  const upcoming = (appointments.data?.appointments ?? []).filter((appointment) => appointment.status !== 'CANCELLED');

  const reportRow = (report: Report) => <div className="catalog-row" key={report.id}>
    <span>
      <strong>{report.procedure?.name ?? 'Laudo'}</strong>
      <small>{dateLabel(report.created_at)} · {report.status}{report.print_count > 0 ? ` · ${report.print_count} impressão(ões)` : ''}{report.signed_manual ? ' · assinado' : ''}</small>
    </span>
    <StatusPill tone={report.status === 'FINALIZADO' ? 'success' : 'warning'}>{report.status}</StatusPill>
    <div className="catalog-actions"><Button variant="outline" onClick={() => setSelected(report)}>Abrir</Button></div>
  </div>;

  return <div className="page laudos-page">
    <div className="page-heading"><div>
      <h1>Laudos</h1>
      <p>Médico dita → recepção digita → imprime → médico revisa no papel e assina. Rotina preservada do STG.</p>
    </div></div>

    <Panel>
      <div className="patient-lookup">
        <TextField label="CPF do paciente" value={cpf} onChange={(event) => { setCpf(event.target.value); setPatient(null); setSelected(null); }} />
        <Button type="button" variant="outline" disabled={cpf.replace(/\D/g, '').length !== 11 || lookup.isPending} onClick={() => lookup.mutate()}>Localizar</Button>
        {patient ? <p><strong>Paciente:</strong> {patient.name}</p> : null}
      </div>
      {lookup.isError ? <Alert tone="danger" title="Paciente não encontrado">{apiErrorMessage(lookup.error)}</Alert> : null}
      {patient && history.isLoading ? <LoadingState label="Carregando histórico de laudos" /> : null}
      {patient && historyList.length > 0 ? <div className="catalog-list-panel">{historyList.map(reportRow)}</div> : null}
      {patient && !history.isLoading && historyList.length === 0 ? <EmptyState title="Sem laudos" description="Nenhum laudo para este paciente ainda — crie a partir de um modelo." /> : null}
    </Panel>

    {patient && appointments.data ? <Panel>
      <h2>Novo laudo a partir de modelo</h2>
      <p className="muted">Escolha a consulta do paciente e o modelo de laudo; as variáveis (nome, idade, data) já vêm preenchidas.</p>
      {upcoming.length === 0 ? <EmptyState title="Sem consultas nos últimos 60 dias" description="Cadastre/consulta a agenda para iniciar um laudo." /> : null}
      <div className="catalog-list-panel">
        {upcoming.slice(0, 10).map((appointment) => <div className="catalog-row" key={appointment.id}>
          <span>
            <strong>{appointment.procedure?.name ?? 'Consulta'}</strong>
            <small>{dateLabel(appointment.scheduled_at)} · {appointment.professional?.name ?? '—'} · {appointment.status}</small>
          </span>
          <div className="catalog-actions">
            <Button variant="outline" onClick={() => {
              const procedureId = appointment.procedure?.id;
              const candidates = (templates.data ?? []).filter(
                (template) => procedureId == null || template.procedure == null || template.procedure.id === procedureId,
              );
              const target = candidates[0];
              if (!target) { setStatusMessage('Nenhum modelo disponível para este exame.'); return; }
              create.mutate({ appointment_id: appointment.id, template_id: target.id });
            }}>Criar laudo</Button>
          </div>
        </div>)}
      </div>
      {create.isPending ? <LoadingState label="Criando laudo" /> : null}
      {create.isError ? <Alert tone="danger" title="Não criou">{apiErrorMessage(create.error)}</Alert> : null}
    </Panel> : null}

    {selected ? <Panel>
      <h2>Laudo {selected.status === 'RASCUNHO' ? '(rascunho)' : '(finalizado)'}</h2>
      <p className="muted">
        {selected.patient?.name} · {selected.procedure?.name ?? '—'} · {dateLabel(selected.created_at)}
        {selected.finalized_at ? ` · finalizado ${dateLabel(selected.finalized_at)}` : ''}
        {selected.signed_manual ? ' · assinatura manual registrada' : ''}
        {selected.portal_published ? ' · publicado no portal' : ''}
        {selected.retention_until ? ` · expurgo ${dateLabel(selected.retention_until)}` : ''}
      </p>
      <div className="report-toolbar">
        <Button type="button" variant="outline" onClick={() => exec('bold')}><strong>B</strong></Button>
        <Button type="button" variant="outline" onClick={() => exec('italic')}><em>I</em></Button>
        <Button type="button" variant="outline" onClick={() => exec('underline')}><u>U</u></Button>
        {(lines.data ?? []).slice(0, 12).map((line) => (
          <Button key={line.id} type="button" variant="outline" title={line.preview} onClick={() => insertLine(line)}>{line.nome}</Button>
        ))}
      </div>
      <div
        className="report-editor"
        ref={editorRef}
        contentEditable={selected.status === 'RASCUNHO' && canWrite}
        suppressContentEditableWarning
        aria-label="Conteúdo do laudo"
      />
      {selected.status === 'RASCUNHO' && canWrite ? <div className="catalog-form-actions">
        <Button variant="success" disabled={save.isPending} onClick={() => save.mutate()}>Salvar rascunho</Button>
        <Button disabled={finalize.isPending} onClick={() => finalize.mutate()}>Finalizar</Button>
      </div> : null}
      {selected.status === 'FINALIZADO' && canWrite ? <div className="catalog-form-actions">
        {adendoFor === selected.id
          ? <>
            <TextField label="Texto do adendo" value={adendoText} minLength={3} onChange={(event) => setAdendoText(event.target.value)} />
            <Button disabled={adendoText.trim().length < 3 || append.isPending} onClick={() => append.mutate()}>Registrar adendo</Button>
            <Button variant="outline" onClick={() => setAdendoFor('')}>Cancelar</Button>
          </>
          : <Button variant="outline" onClick={() => { setAdendoFor(selected.id); setAdendoText(''); }}>Adicionar adendo</Button>}
      </div> : null}
      <div className="catalog-form-actions">
        <Button variant="outline" disabled={print.isPending} onClick={() => print.mutate()}>Imprimir{selected.print_count > 0 ? ` (reimpressão #${selected.print_count + 1})` : ''}</Button>
        {selected.status === 'FINALIZADO' && !selected.signed_manual && canSign
          ? <Button variant="success" disabled={sign.isPending} onClick={() => sign.mutate()}>Marcar assinatura manual</Button> : null}
        {selected.status === 'FINALIZADO' && canWrite && !selected.portal_published
          ? <Button variant="success" disabled={publishPortal.isPending} onClick={() => publishPortal.mutate()}>Publicar resultado no portal</Button> : null}
        {selected.status === 'FINALIZADO' && selected.portal_published
          ? <Button variant="outline" disabled={printFolha.isPending} onClick={() => printFolha.mutate()}>Folha de acesso</Button> : null}
        {selected.status === 'FINALIZADO' && canWrite && selected.portal_published && revokeFor !== selected.id
          ? <Button variant="outline" onClick={() => { setRevokeFor(selected.id); setRevokeReason(''); }}>Revogar publicação</Button> : null}
      </div>
      {revokeFor === selected.id ? <div className="catalog-form-actions">
        <TextField label="Motivo da revogação" value={revokeReason} minLength={3} onChange={(event) => setRevokeReason(event.target.value)} />
        <Button disabled={revokeReason.trim().length < 3 || revokePortal.isPending} onClick={() => revokePortal.mutate()}>Revogar</Button>
        <Button variant="outline" onClick={() => setRevokeFor('')}>Cancelar</Button>
      </div> : null}
      {selected.events.length > 0 ? <details><summary>Trilha do laudo ({selected.events.length})</summary>
        <ul className="event-trail">
          {selected.events.map((event) => <li key={event.id}>{event.event} — {event.by?.name ?? '—'} — {dateLabel(event.created_at)}</li>)}
        </ul>
      </details> : null}
    </Panel> : null}

    {statusMessage ? <p role="status" className="muted">{statusMessage}</p> : null}
    {(save.isError || finalize.isError || append.isError || sign.isError || print.isError || publishPortal.isError || printFolha.isError || revokePortal.isError) ? <Alert tone="danger" title="Operação falhou">
      {apiErrorMessage(save.error ?? finalize.error ?? append.error ?? sign.error ?? print.error ?? publishPortal.error ?? printFolha.error ?? revokePortal.error)}
    </Alert> : null}
  </div>;
}
