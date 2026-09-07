import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type ScheduleConflict, type ScheduleWindow, type ScheduleWindowInput, type ScheduleException, type ScheduleExceptionInput, type ServiceOffering, type ServiceOfferingInput, type WindowImportRow } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, Button, EmptyState, LoadingState, Panel, SelectField, TextField, StatusPill } from '@dez/ui';

const days = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const modeLabels = { FIXED_SLOT: 'Horário marcado', ARRIVAL_BLOCK: 'Blocos por ordem de chegada', WALK_IN_INFO: 'Ordem de chegada sem reserva' };
const emptyOffering: ServiceOfferingInput = { catalog_link_id: '', procedure_price_id: '', booking_mode: 'FIXED_SLOT', modality: 'PRESENTIAL', duration_override_minutes: 30, daily_capacity: null, arrival_block_minutes: 60, arrival_block_capacity: 3, active: true };
const dateValue = (value: string) => value.slice(0, 10);
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });

export function SchedulingSection({ user }: { user?: CurrentUser }) {
  const client = useQueryClient();
  const [selectedId, setSelectedId] = useState('');
  const [form, setForm] = useState<ServiceOfferingInput>(emptyOffering);
  const offerings = useQuery({ queryKey: ['service-offerings'], queryFn: api.serviceOfferings });
  const options = useQuery({ queryKey: ['scheduling-options', form.catalog_link_id], queryFn: () => api.schedulingOptions(form.catalog_link_id || undefined) });
  const conflicts = useQuery({ queryKey: ['schedule-conflicts'], queryFn: () => api.scheduleConflicts(), enabled: user?.permissions.includes('schedule.view') ?? false });
  const selected = offerings.data?.find((item) => item.id === selectedId);
  const refresh = () => Promise.all([client.invalidateQueries({ queryKey: ['service-offerings'] }), client.invalidateQueries({ queryKey: ['schedule-conflicts'] })]);
  const save = useMutation({ mutationFn: () => api.saveServiceOffering(form, selectedId || undefined), onSuccess: async (offering) => { setSelectedId(offering.id); await refresh(); } });
  const deactivate = useMutation({ mutationFn: api.deactivateServiceOffering, onSuccess: refresh });
  const edit = (offering: ServiceOffering) => {
    setSelectedId(offering.id);
    setForm({ catalog_link_id: offering.catalog_link_id, procedure_price_id: offering.procedure_price_id, booking_mode: offering.booking_mode, modality: offering.modality, duration_override_minutes: offering.duration_override_minutes, daily_capacity: offering.daily_capacity, arrival_block_minutes: offering.arrival_block_minutes, arrival_block_capacity: offering.arrival_block_capacity, active: offering.active });
    save.reset();
  };
  const field = <K extends keyof ServiceOfferingInput>(key: K, value: ServiceOfferingInput[K]) => setForm((current) => ({ ...current, [key]: value }));

  return <div className="scheduling-section">
    <Alert tone="info" title="Online automático">Ofertas do convênio padrão com preço e escala válidos aparecem sozinhas no site. Use Pausar para tirar do ar.</Alert>
    <div className="catalog-layout">
      <Panel className="catalog-list-panel">
        {offerings.isLoading ? <LoadingState label="Carregando ofertas" /> : null}
        {offerings.isError ? <Alert tone="danger" title="Falha ao carregar ofertas">{apiErrorMessage(offerings.error)}</Alert> : null}
        {offerings.data?.length === 0 ? <EmptyState title="Nenhuma oferta configurada" description="Valide um vínculo na aba Vínculos e crie sua primeira oferta interna." /> : null}
        {offerings.data?.map((offering) => <div className="catalog-row" key={offering.id}>
          <span><strong>{offering.procedure.name}</strong><small>{offering.professional?.name} · {offering.agreement.name}</small><small>{offering.unit.name} · {formatMoney(offering.price)} · {modeLabels[offering.booking_mode]}</small></span>
          <StatusPill tone={offering.online_enabled ? 'warning' : offering.active ? 'success' : 'neutral'}>{offering.online_enabled ? 'Visível no site' : offering.active ? 'Interna' : 'Inativa'}</StatusPill>
          <div className="catalog-actions"><Button variant="outline" onClick={() => edit(offering)}>Configurar</Button>{offering.active ? <Button variant="danger" disabled={deactivate.isPending} onClick={() => deactivate.mutate(offering.id)}>Desativar oferta</Button> : null}</div>
        </div>)}
        {deactivate.isError ? <Alert tone="danger" title="Falha ao desativar">{apiErrorMessage(deactivate.error)}</Alert> : null}
      </Panel>
      <Panel className="catalog-form-panel">
        <h2>{selectedId ? 'Editar oferta interna' : 'Nova oferta interna'}</h2>
        <form onSubmit={(event) => { event.preventDefault(); save.mutate(); }} className="schedule-form">
          <SelectField label="Vínculo validado" value={form.catalog_link_id} disabled={Boolean(selectedId)} onChange={(event) => setForm((current) => ({ ...current, catalog_link_id: event.target.value, procedure_price_id: '' }))} required>
            <option value="">Selecione um vínculo</option>{options.data?.links.map((link) => <option key={link.id} value={link.id}>{link.procedure.name} · {link.professional?.name} · {link.agreement.name}</option>)}
          </SelectField>
          <SelectField label="Preço vigente de origem" value={form.procedure_price_id} onChange={(event) => field('procedure_price_id', event.target.value)} required>
            <option value="">Selecione um preço</option>{options.data?.prices.map((price) => <option key={price.id} value={price.id}>{formatMoney(price.amount)} · {price.nature}{price.valid_from ? ` · desde ${dateValue(price.valid_from)}` : ''}</option>)}
          </SelectField>
          {form.catalog_link_id && options.data?.prices.length === 0 ? <p>Nenhum preço vigente e válido para este vínculo. Revise o catálogo de preços.</p> : null}
          <SelectField label="Modo de atendimento" value={form.booking_mode} onChange={(event) => field('booking_mode', event.target.value as ServiceOfferingInput['booking_mode'])}>{Object.entries(modeLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</SelectField>
          <SelectField label="Modalidade" value={form.modality} onChange={(event) => field('modality', event.target.value as ServiceOfferingInput['modality'])}><option value="PRESENTIAL">Presencial</option><option value="TELECONSULTATION">Teleconsulta</option></SelectField>
          <div className="catalog-form-grid">
            <TextField label="Duração (min)" type="number" min={1} max={480} value={form.duration_override_minutes ?? ''} onChange={(event) => field('duration_override_minutes', event.target.value ? Number(event.target.value) : null)} />
            <TextField label="Capacidade diária" type="number" min={1} max={1000} value={form.daily_capacity ?? ''} onChange={(event) => field('daily_capacity', event.target.value ? Number(event.target.value) : null)} />
            {form.booking_mode === 'ARRIVAL_BLOCK' ? <><TextField label="Duração do bloco (min)" type="number" min={1} value={form.arrival_block_minutes} onChange={(event) => field('arrival_block_minutes', Number(event.target.value))} /><TextField label="Pessoas por bloco" type="number" min={1} value={form.arrival_block_capacity} onChange={(event) => field('arrival_block_capacity', Number(event.target.value))} /></> : null}
          </div>
          <label className="catalog-check"><input type="checkbox" checked={form.active} onChange={(event) => field('active', event.target.checked)} />Oferta ativa</label>
          {save.isError ? <Alert tone="danger" title="Oferta não salva">{apiErrorMessage(save.error)}</Alert> : null}
          {save.isSuccess ? <p role="status">Oferta salva. Configure os horários abaixo.</p> : null}
          <div className="catalog-form-actions"><Button type="button" variant="outline" onClick={() => { setSelectedId(''); setForm(emptyOffering); save.reset(); }}>Nova oferta</Button><Button type="submit" disabled={save.isPending || !form.procedure_price_id}>Salvar oferta</Button></div>
        </form>
      </Panel>
    </div>
    {selected ? <><VisibilityToggle key={`visibility-${selected.id}`} offering={selected} refresh={refresh} /><ScheduleEditor key={selected.id} offering={selected} refresh={refresh} /></> : null}
    {(conflicts.data?.length ?? 0) > 0 ? <ConflictsPanel conflicts={conflicts.data ?? []} canResolve={user?.permissions.includes('schedule.manage') ?? false} refresh={refresh} /> : null}
  </div>;
}

function VisibilityToggle({ offering, refresh }: { offering: ServiceOffering; refresh: () => Promise<unknown> }) {
  const toggle = useMutation({ mutationFn: () => api.setOfferingVisibility(offering.id, !offering.online_paused), onSuccess: refresh });
  const updatedAt = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', timeZone: 'America/Fortaleza' }).format(new Date(offering.updated_at));
  return <Panel className="schedule-config"><h2>Visibilidade no site</h2><p>Ofertas do convênio padrão com preço e escala válidos aparecem automaticamente no agendamento online. Sem vencimento; o preço acompanha a tabela vigente sozinho.</p>
    {offering.online_enabled ? <p role="status">Visível no site · {formatMoney(offering.price)} (atualizado em {updatedAt}).</p> : <p>Oferta fora do site: {offering.online_paused ? 'pausada pela equipe.' : 'sem preço ou escala válidos no momento.'}</p>}
    {toggle.isError ? <Alert tone="danger" title="Visibilidade não alterada">{apiErrorMessage(toggle.error)}</Alert> : null}
    <div className="catalog-form-actions">{offering.online_paused ? <Button disabled={toggle.isPending} onClick={() => toggle.mutate()}>Retomar no site</Button> : <Button variant="danger" disabled={toggle.isPending} onClick={() => toggle.mutate()}>Pausar no site</Button>}</div>
  </Panel>;
}

function ScheduleEditor({ offering, refresh }: { offering: ServiceOffering; refresh: () => Promise<unknown> }) {
  const newWindow = (): ScheduleWindowInput => ({ effective_from: today(), effective_to: null, weekday: 1, starts_at: '08:00', ends_at: '12:00', slot_minutes: 30, daily_capacity_override: null, active: true });
  const [windowId, setWindowId] = useState('');
  const [windowForm, setWindowForm] = useState(newWindow);
  const [exceptionId, setExceptionId] = useState('');
  const [exceptionForm, setExceptionForm] = useState<ScheduleExceptionInput>({ date: today(), starts_at: null, ends_at: null, reason: '', blocked: true });
  const [conflictNotice, setConflictNotice] = useState<{ count: number; affected: string[] } | null>(null);
  const warn = (result: { conflicts: Array<{ appointment_id: string; scheduled_at: string; patient_name: string | null }> }) => {
    setConflictNotice(result.conflicts.length > 0
      ? { count: result.conflicts.length, affected: result.conflicts.map((item) => `${item.scheduled_at.slice(0, 10)} ${item.scheduled_at.slice(11, 16)} · ${item.patient_name ?? 'paciente'}`) }
      : null);
  };
  const saveWindow = useMutation({ mutationFn: () => api.saveScheduleWindow(offering.id, windowForm, windowId || undefined), onSuccess: async (result) => { warn(result); setWindowId(''); setWindowForm(newWindow()); await refresh(); } });
  const removeWindow = useMutation({ mutationFn: (id: string) => api.deactivateScheduleWindow(offering.id, id), onSuccess: async (result) => { warn(result); await refresh(); } });
  const saveException = useMutation({ mutationFn: () => api.saveScheduleException(offering.id, exceptionForm, exceptionId || undefined), onSuccess: async (result) => { warn(result); setExceptionId(''); setExceptionForm({ date: today(), starts_at: null, ends_at: null, reason: '', blocked: true }); await refresh(); } });
  const removeException = useMutation({ mutationFn: (id: string) => api.removeScheduleException(offering.id, id), onSuccess: refresh });
  const editWindow = (window: ScheduleWindow) => { setWindowId(window.id); setWindowForm({ effective_from: dateValue(window.effective_from), effective_to: window.effective_to ? dateValue(window.effective_to) : null, weekday: window.weekday, starts_at: window.starts_at.slice(0, 5), ends_at: window.ends_at.slice(0, 5), slot_minutes: window.slot_minutes, daily_capacity_override: window.daily_capacity_override, active: window.active }); };
  const editException = (exception: ScheduleException) => { setExceptionId(exception.id); setExceptionForm({ date: dateValue(exception.date), starts_at: exception.starts_at?.slice(0, 5) ?? null, ends_at: exception.ends_at?.slice(0, 5) ?? null, reason: exception.reason, blocked: exception.blocked }); };
  return <div className="schedule-editors">
    <Panel className="schedule-config"><h2>Escalas · {offering.procedure.name}</h2><p>As ocorrências dos próximos 90 dias são atualizadas automaticamente. Agendamentos existentes são preservados.</p>
      {conflictNotice ? <Alert tone="warning" title={`${conflictNotice.count} agendamento(s) afetado(s) — pendência aberta`}>Nada foi apagado. Resolva com o médico ou o paciente: {conflictNotice.affected.join('; ')}.</Alert> : null}
      {offering.schedule_windows.map((window) => <div className="schedule-rule" key={window.id}><span>{days[window.weekday]} · {window.starts_at.slice(0, 5)}–{window.ends_at.slice(0, 5)} · {window.active ? 'Ativa' : 'Inativa'}</span><Button variant="outline" onClick={() => editWindow(window)}>Editar escala</Button>{window.active ? <Button variant="danger" onClick={() => removeWindow.mutate(window.id)}>Desativar escala</Button> : null}</div>)}
      <form className="schedule-form" onSubmit={(event) => { event.preventDefault(); saveWindow.mutate(); }}>
        <div className="catalog-form-grid"><SelectField label="Dia da semana" value={windowForm.weekday} onChange={(event) => setWindowForm((current) => ({ ...current, weekday: Number(event.target.value) }))}>{days.map((day, index) => <option value={index} key={day}>{day}</option>)}</SelectField>
          <TextField label="Início da vigência" type="date" required value={windowForm.effective_from} onChange={(event) => setWindowForm((current) => ({ ...current, effective_from: event.target.value }))} />
          <TextField label="Fim da vigência (opcional)" type="date" value={windowForm.effective_to ?? ''} onChange={(event) => setWindowForm((current) => ({ ...current, effective_to: event.target.value || null }))} />
          <TextField label="Hora inicial" type="time" required value={windowForm.starts_at} onChange={(event) => setWindowForm((current) => ({ ...current, starts_at: event.target.value }))} />
          <TextField label="Hora final" type="time" required value={windowForm.ends_at} onChange={(event) => setWindowForm((current) => ({ ...current, ends_at: event.target.value }))} />
          <TextField label="Intervalo de vagas (min)" type="number" min={1} value={windowForm.slot_minutes ?? ''} onChange={(event) => setWindowForm((current) => ({ ...current, slot_minutes: event.target.value ? Number(event.target.value) : null }))} />
          <TextField label="Limite diário da escala" type="number" min={1} value={windowForm.daily_capacity_override ?? ''} onChange={(event) => setWindowForm((current) => ({ ...current, daily_capacity_override: event.target.value ? Number(event.target.value) : null }))} />
        </div><label className="catalog-check"><input type="checkbox" checked={windowForm.active} onChange={(event) => setWindowForm((current) => ({ ...current, active: event.target.checked }))} />Escala ativa</label>
        {saveWindow.isError || removeWindow.isError ? <Alert tone="danger" title="Escala não atualizada">{apiErrorMessage(saveWindow.error ?? removeWindow.error)}</Alert> : null}
        <div className="catalog-form-actions"><Button type="button" variant="outline" onClick={() => { setWindowId(''); setWindowForm(newWindow()); }}>Nova escala</Button><Button type="submit" disabled={saveWindow.isPending}>{windowId ? 'Atualizar escala' : 'Adicionar escala'}</Button></div>
      </form>
    </Panel>
    <Panel className="schedule-config"><h2>Exceções e bloqueios</h2><p>Deixe as horas vazias para bloquear o dia inteiro. Reservas existentes não são canceladas automaticamente.</p>
      {offering.schedule_exceptions.map((exception) => <div className="schedule-rule" key={exception.id}><span>{dateValue(exception.date)} · {exception.reason} · {exception.blocked ? 'Bloqueado' : 'Liberado'}</span><Button variant="outline" onClick={() => editException(exception)}>Editar bloqueio</Button>{exception.blocked ? <Button variant="outline" onClick={() => removeException.mutate(exception.id)}>Liberar</Button> : null}</div>)}
      <form className="schedule-form" onSubmit={(event) => { event.preventDefault(); saveException.mutate(); }}><div className="catalog-form-grid">
        <TextField label="Data do bloqueio" type="date" required value={exceptionForm.date} onChange={(event) => setExceptionForm((current) => ({ ...current, date: event.target.value }))} />
        <TextField label="Bloquear a partir de" type="time" value={exceptionForm.starts_at ?? ''} onChange={(event) => setExceptionForm((current) => ({ ...current, starts_at: event.target.value || null }))} />
        <TextField label="Bloquear até" type="time" value={exceptionForm.ends_at ?? ''} onChange={(event) => setExceptionForm((current) => ({ ...current, ends_at: event.target.value || null }))} />
        <TextField label="Motivo do bloqueio" required minLength={3} value={exceptionForm.reason} onChange={(event) => setExceptionForm((current) => ({ ...current, reason: event.target.value }))} />
      </div>{saveException.isError || removeException.isError ? <Alert tone="danger" title="Bloqueio não atualizado">{apiErrorMessage(saveException.error ?? removeException.error)}</Alert> : null}<Button type="submit" disabled={saveException.isPending}>{exceptionId ? 'Atualizar bloqueio' : 'Adicionar bloqueio'}</Button></form>
    </Panel>
    <WindowImportPanel offering={offering} />
  </div>;
}

const weekdayNames: Record<string, number> = { domingo: 0, segunda: 1, terca: 2, terça: 2, quarta: 3, quinta: 4, sexta: 5, sabado: 6, sábado: 6 };
const normalize = (value: string) => value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

function parseScaleCsv(text: string): { rows: WindowImportRow[]; errors: string[] } {
  const rows: WindowImportRow[] = [];
  const errors: string[] = [];
  text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).forEach((line, index) => {
    if (index === 0 && /weekday|dia/i.test(line)) return;
    const [effective_from, effective_to, weekday, starts_at, ends_at, slot_minutes, daily_capacity] = line.split(/[;,]/).map((cell) => cell.trim());
    const label = `linha ${index + 1}`;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(effective_from ?? '')) { errors.push(`${label}: início da vigência inválido (AAAA-MM-DD).`); return; }
    if (effective_to && !/^\d{4}-\d{2}-\d{2}$/.test(effective_to)) { errors.push(`${label}: fim da vigência inválido.`); return; }
    const day = /^\d$/.test(weekday ?? '') ? Number(weekday) : weekdayNames[normalize(weekday ?? '')];
    if (day === undefined || day < 0 || day > 6) { errors.push(`${label}: dia da semana inválido (0–6 ou nome).`); return; }
    if (!/^\d{2}:\d{2}$/.test(starts_at ?? '') || !/^\d{2}:\d{2}$/.test(ends_at ?? '') || starts_at >= ends_at) { errors.push(`${label}: horários inválidos (HH:MM, início antes do fim).`); return; }
    rows.push({
      effective_from, effective_to: effective_to || null, weekday: day, starts_at, ends_at,
      slot_minutes: slot_minutes ? Number(slot_minutes) : null, daily_capacity_override: daily_capacity ? Number(daily_capacity) : null,
    });
  });
  return { rows, errors };
}

function WindowImportPanel({ offering }: { offering: ServiceOffering }) {
  const client = useQueryClient();
  const [text, setText] = useState('');
  const [localErrors, setLocalErrors] = useState<string[]>([]);
  const [report, setReport] = useState('');
  const parsed = parseScaleCsv(text);
  const importRows = useMutation({
    mutationFn: (dry_run: boolean) => api.importScheduleWindows(offering.id, { rows: parsed.rows, dry_run }),
    onSuccess: async (result) => {
      setReport(result.dry_run ? `${result.validated} linha(s) válidas — nada foi gravado.` : `${result.created} escala(s) importada(s).`);
      if (!result.dry_run) {
        setText('');
        await Promise.all([client.invalidateQueries({ queryKey: ['service-offerings'] }), client.invalidateQueries({ queryKey: ['schedule-conflicts'] })]);
      }
    },
  });
  const loadFile = async (file: File | undefined) => {
    if (!file) return;
    setText(await file.text());
    setLocalErrors([]);
    setReport('');
    importRows.reset();
  };
  return <Panel className="schedule-config"><h2>Importar escala da planilha</h2>
    <p>CSV com colunas: início da vigência, fim (opcional), dia (0–6 ou nome), hora inicial, hora final, intervalo (min, opcional), limite diário (opcional). Mesma estrutura da tela.</p>
    <input type="file" accept=".csv,.txt" aria-label="Arquivo CSV da escala" onChange={(event) => void loadFile(event.target.files?.[0])} />
    {text ? <p role="status">{parsed.rows.length} linha(s) lidas{parsed.errors.length > 0 ? ` · ${parsed.errors.length} com erro` : ''}.</p> : null}
    {(localErrors.length > 0 || parsed.errors.length > 0) ? <Alert tone="danger" title="Planilha com erros">{[...localErrors, ...parsed.errors].join(' ')}</Alert> : null}
    {importRows.isError ? <Alert tone="danger" title="Importação não concluída">{apiErrorMessage(importRows.error)}</Alert> : null}
    {report ? <p role="status">{report}</p> : null}
    <div className="catalog-form-actions">
      <Button variant="outline" disabled={parsed.rows.length === 0 || parsed.errors.length > 0 || importRows.isPending} onClick={() => importRows.mutate(true)}>Validar sem gravar</Button>
      <Button disabled={parsed.rows.length === 0 || parsed.errors.length > 0 || importRows.isPending} onClick={() => importRows.mutate(false)}>Importar escalas</Button>
    </div>
  </Panel>;
}

function ConflictsPanel({ conflicts, canResolve, refresh }: { conflicts: ScheduleConflict[]; canResolve: boolean; refresh: () => Promise<unknown> }) {
  const [note, setNote] = useState<Record<string, string>>({});
  const resolve = useMutation({
    mutationFn: ({ id, resolution_note }: { id: string; resolution_note: string }) => api.resolveScheduleConflict(id, resolution_note),
    onSuccess: refresh,
  });
  return <Panel className="schedule-config"><h2>Pendências de escala ({conflicts.length})</h2>
    <p>Mudanças de escala com agendamentos existentes. Nada foi apagado: resolva com o médico ou o paciente e registre abaixo.</p>
    {conflicts.map((conflict) => <div className="catalog-row" key={conflict.id}>
      <span><strong>{conflict.procedure_name} · {conflict.professional_name}</strong><small>{conflict.reason}</small>
        <small>{conflict.affected.map((item) => `${item.scheduled_at.slice(0, 10)} ${item.scheduled_at.slice(11, 16)} · ${item.patient_name ?? 'paciente'}`).join('; ')}</small></span>
      <StatusPill tone="warning">Pendente</StatusPill>
      {canResolve ? <div className="catalog-actions">
        <TextField label="Como foi resolvido" value={note[conflict.id] || ''} minLength={3} onChange={(event) => setNote((prev) => ({ ...prev, [conflict.id]: event.target.value }))} />
        <Button disabled={resolve.isPending || (note[conflict.id] || '').trim().length < 3} onClick={() => resolve.mutate({ id: conflict.id, resolution_note: note[conflict.id] })}>Marcar resolvida</Button>
      </div> : null}
    </div>)}
    {resolve.isError ? <Alert tone="danger" title="Pendência não resolvida">{apiErrorMessage(resolve.error)}</Alert> : null}
  </Panel>;
}
