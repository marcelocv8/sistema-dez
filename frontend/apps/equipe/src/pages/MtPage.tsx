import { useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type MtAso } from '@dez/api';
import { Alert, Button, EmptyState, Panel, SectionTitle, SelectField, StatusPill, TextField } from '@dez/ui';

const ASO_TYPES = [
  { value: 'ADMISSIONAL', label: 'Admissional' },
  { value: 'PERIODICO', label: 'Periódico' },
  { value: 'RETORNO_AO_TRABALHO', label: 'Retorno ao trabalho' },
  { value: 'MUDANCA_DE_FUNCAO', label: 'Mudança de função' },
  { value: 'DEMISSIONAL', label: 'Demissional' },
] as const;

const STATUS_TONES: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  EMITIDO: 'success', AGENDADO: 'info', PENDENTE: 'warning', CANCELADO: 'neutral',
};

type Tab = 'vencimentos' | 'asos' | 'empresas' | 'lote' | 'empresa';

export default function MtPage({ user }: { user: CurrentUser }) {
  const canManage = user.permissions.includes('mt.manage' as never);
  const [tab, setTab] = useState<Tab>('vencimentos');
  const options = useQuery({ queryKey: ['mt-options'], queryFn: api.mtOptions });
  const queryClient = useQueryClient();
  const invalidate = () => { void queryClient.invalidateQueries({ queryKey: ['mt-options'] }); void queryClient.invalidateQueries({ queryKey: ['mt-due'] }); void queryClient.invalidateQueries({ queryKey: ['mt-asos'] }); };

  const tabs: { key: Tab; label: string }[] = [
    { key: 'vencimentos', label: 'Vencimentos' },
    { key: 'asos', label: 'ASOs' },
    { key: 'empresas', label: 'Empresas' },
    ...(canManage ? [{ key: 'lote' as Tab, label: 'Agendar lote' }] : []),
    { key: 'empresa', label: 'Relatório empresa' },
  ];

  return (
    <div className="page mt-page">
      <div className="page-heading"><div><h1>Medicina do Trabalho</h1><p>ASOs, setores e funções por empresa (§6.7).</p></div></div>
      <div className="mt-tabs" role="tablist">
        {tabs.map((item) => (
          <Button key={item.key} variant={tab === item.key ? 'primary' : 'outline'} role="tab" aria-selected={tab === item.key} onClick={() => setTab(item.key)}>{item.label}</Button>
        ))}
      </div>
      {options.isError ? <Alert tone="danger" title="Não carregou">{apiErrorMessage(options.error)}</Alert> : null}
      {tab === 'vencimentos' ? <DueTab canManage={canManage} onInvalidate={invalidate} /> : null}
      {tab === 'asos' ? <AsosTab canManage={canManage} onInvalidate={invalidate} /> : null}
      {tab === 'empresas' ? <CompaniesTab canManage={canManage} onInvalidate={invalidate} /> : null}
      {tab === 'lote' ? <BatchTab /> : null}
      {tab === 'empresa' ? <CompanyReportTab /> : null}
    </div>
  );
}

function DueTab({ canManage, onInvalidate }: { canManage: boolean; onInvalidate: () => void }) {
  const due = useQuery({ queryKey: ['mt-due'], queryFn: () => api.mtDue({ days: '30' }) });
  const extend = useMutation({ mutationFn: async (input: { id: string; expires_at: string }) => api.extendMtAso(input.id, input.expires_at), onSuccess: onInvalidate });

  if (due.isLoading) return <Panel><p>Carregando vencimentos…</p></Panel>;
  if (due.isError) return <Alert tone="danger" title="Erro">{apiErrorMessage(due.error)}</Alert>;
  const items = due.data?.items ?? [];

  return (
    <Panel>
      <SectionTitle title="ASOs a vencer (30 dias)" subtitle={`${items.filter((item) => item.overdue).length} vencido(s)`} />
      {items.length === 0 ? <EmptyState title="Nada a vencer" description="Nenhum ASO periódico vence nos próximos 30 dias." /> : null}
      {items.length > 0 ? (
        <div className="mt-table-scroll" role="region" aria-label="Tabela de ASOs" tabIndex={0}><table className="data-table">
          <thead><tr><th>Funcionário</th><th>Empresa</th><th>Setor</th><th>Função</th><th>Vencimento</th>{canManage ? <th>Ações</th> : null}</tr></thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>{item.patient?.name}{item.overdue ? <> <StatusPill tone="danger">Vencido</StatusPill></> : null}</td>
                <td>{item.agreement?.name}</td>
                <td>{item.sector?.name ?? '—'}</td>
                <td>{item.function?.name ?? '—'}</td>
                <td>{item.expires_at ?? '—'}</td>
                {canManage ? <td><ExtendButton onSubmit={(expires) => extend.mutate({ id: item.id, expires_at: expires })} /></td> : null}
              </tr>
            ))}
          </tbody>
        </table></div>
      ) : null}
    </Panel>
  );
}

function ExtendButton({ onSubmit }: { onSubmit: (expires: string) => void }) {
  const [value, setValue] = useState('');
  return (
    <span className="inline-actions">
      <input type="date" aria-label="Nova validade" value={value} onChange={(event) => setValue(event.target.value)} />
      <Button variant="outline" onClick={() => value && onSubmit(value)}>Prorrogar</Button>
    </span>
  );
}

function AsosTab({ canManage, onInvalidate }: { canManage: boolean; onInvalidate: () => void }) {
  const [agreementId, setAgreementId] = useState('');
  const [status, setStatus] = useState('');
  const options = useQuery({ queryKey: ['mt-options'], queryFn: api.mtOptions });
  const asos = useQuery({ queryKey: ['mt-asos', agreementId, status], queryFn: () => api.mtAsos({ agreement_id: agreementId || undefined, status: status || undefined }) });
  const [form, setForm] = useState({ cpf: '', agreement_id: '', sector_id: '', function_id: '', type: 'PERIODICO', notes: '' });
  const patientVersion = useRef(0);
  const lookup = useMutation({
    mutationFn: async (input: { cpf: string; version: number }) => ({ ...input, patient: await api.lookupPatient(input.cpf) }),
  });
  const identified = lookup.data?.version === patientVersion.current && lookup.data.cpf === form.cpf.replace(/\D/g, '') ? lookup.data.patient : null;
  const [linkFor, setLinkFor] = useState<MtAso | null>(null);
  const create = useMutation({
    mutationFn: async () => api.createMtAso({
      patient_id: identified?.id ?? '',
      agreement_id: form.agreement_id,
      sector_id: form.sector_id || undefined,
      function_id: form.function_id || undefined,
      type: form.type as MtAso['type'],
      notes: form.notes || undefined,
    }),
    onSuccess: () => { patientVersion.current++; lookup.reset(); setForm({ cpf: '', agreement_id: '', sector_id: '', function_id: '', type: 'PERIODICO', notes: '' }); onInvalidate(); },
  });
  const emit = useMutation({ mutationFn: async (aso: MtAso) => api.emitMtAso(aso.id, {}), onSuccess: onInvalidate });
  const cancel = useMutation({ mutationFn: async (input: { id: string; reason: string }) => api.cancelMtAso(input.id, input.reason), onSuccess: onInvalidate });

  const agreements = options.data?.agreements ?? [];
  const sectors = (options.data?.sectors ?? []).filter((sector) => !form.agreement_id || sector.agreement?.id === form.agreement_id);
  const functions = (options.data?.functions ?? []).filter((fn) => fn.agreement_id === form.agreement_id && (!form.sector_id || fn.sector?.id === form.sector_id));
  const rows = asos.data?.data.items ?? [];

  return (
    <div className="stack-gap">
      {linkFor ? <AsoExamPicker aso={linkFor} onClose={() => setLinkFor(null)} onLinked={() => { setLinkFor(null); onInvalidate(); }} /> : null}
      {canManage ? (
        <Panel>
          <SectionTitle title="Novo ASO" subtitle="Funcionário já precisa estar cadastrado como paciente" />
          {create.isError ? <Alert tone="danger" title="Erro">{apiErrorMessage(create.error)}</Alert> : null}
          <div className="catalog-form-grid">
            <TextField label="CPF do funcionário" value={form.cpf} onChange={(event) => { patientVersion.current++; lookup.reset(); setForm({ ...form, cpf: event.target.value }); }} />
            <span className="catalog-form-actions"><Button variant="outline" onClick={() => lookup.mutate({ cpf: form.cpf.replace(/\D/g, ''), version: patientVersion.current })} disabled={lookup.isPending}>Localizar</Button></span>
          </div>
          {lookup.isError ? <Alert tone="danger" title="Busca">{apiErrorMessage(lookup.error)}</Alert> : null}
          {identified ? <p><StatusPill tone="success">Paciente localizado: {identified.name}</StatusPill></p> : null}
          <div className="catalog-form-grid">
            <SelectField label="Empresa (convênio)" value={form.agreement_id} onChange={(event) => setForm({ ...form, agreement_id: event.target.value, sector_id: '', function_id: '' })}>
              <option value="">Selecione…</option>
              {agreements.map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
            </SelectField>
            <SelectField label="Setor" value={form.sector_id} onChange={(event) => setForm({ ...form, sector_id: event.target.value, function_id: '' })}>
              <option value="">—</option>
              {sectors.map((sector) => <option key={sector.id} value={sector.id}>{sector.name}</option>)}
            </SelectField>
            <SelectField label="Função" value={form.function_id} onChange={(event) => setForm({ ...form, function_id: event.target.value })}>
              <option value="">—</option>
              {functions.map((fn) => <option key={fn.id} value={fn.id}>{fn.name}</option>)}
            </SelectField>
            <SelectField label="Tipo" value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value })}>
              {ASO_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
            </SelectField>
            <TextField label="Observações" value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} />
          </div>
          <div className="catalog-form-actions">
            <Button onClick={() => create.mutate()} disabled={create.isPending || !identified || !form.agreement_id}>Cadastrar ASO</Button>
          </div>
        </Panel>
      ) : null}
      <Panel>
        <SectionTitle title="ASOs cadastrados" subtitle={`${rows.length} registro(s)`} />
        <div className="catalog-form-grid">
          <SelectField label="Empresa" value={agreementId} onChange={(event) => setAgreementId(event.target.value)}>
            <option value="">Todas</option>
            {agreements.map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
          </SelectField>
          <SelectField label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="">Todos</option>
            <option value="PENDENTE">Pendente</option>
            <option value="AGENDADO">Agendado</option>
            <option value="EMITIDO">Emitido</option>
          </SelectField>
        </div>
        {rows.length === 0 ? <EmptyState title="Sem ASOs" description="Cadastre o primeiro ASO acima." /> : null}
        {rows.length > 0 ? (
          <div className="mt-table-scroll" role="region" aria-label="Tabela de ASOs" tabIndex={0}><table className="data-table">
            <thead><tr><th>Funcionário</th><th>Empresa</th><th>Tipo</th><th>Status</th><th>Exame</th><th>Laudo</th>{canManage ? <th>Ações</th> : null}</tr></thead>
            <tbody>
              {rows.map((aso) => (
                <tr key={aso.id}>
                  <td>{aso.patient?.name ?? '—'}</td>
                  <td>{aso.agreement?.name}</td>
                  <td>{aso.type_label}</td>
                  <td><StatusPill tone={STATUS_TONES[aso.status] ?? 'neutral'}>{aso.status_label}</StatusPill></td>
                  <td>{aso.appointment ? aso.appointment.status : '—'}</td>
                  <td>{aso.report ? aso.report.status : '—'}</td>
                  {canManage && aso.status !== 'CANCELADO' ? (
                    <td>
                      <span className="inline-actions">
                        {!aso.report && aso.status !== 'EMITIDO' ? <Button variant="outline" onClick={() => setLinkFor(aso)}>Vincular atendimento</Button> : null}
                        <Button variant="outline" onClick={() => emit.mutate(aso)} disabled={emit.isPending || !aso.appointment || Boolean(aso.report)}>Emitir laudo</Button>
                        <Button variant="outline" onClick={() => { const reason = window.prompt('Motivo do cancelamento:'); if (reason) cancel.mutate({ id: aso.id, reason }); }}>Cancelar</Button>
                      </span>
                    </td>
                  ) : canManage ? <td>—</td> : null}
                </tr>
              ))}
            </tbody>
          </table></div>
        ) : null}
        {emit.isError ? <Alert tone="danger" title="Emissão">{apiErrorMessage(emit.error)}</Alert> : null}
      </Panel>
    </div>
  );
}

function AsoExamPicker({ aso, onClose, onLinked }: { aso: MtAso; onClose: () => void; onLinked: () => void }) {
  const day = (offset: number) => new Date(Date.now() + offset * 86400000).toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
  const [from, setFrom] = useState(() => day(-30));
  const [to, setTo] = useState(() => day(30));
  const [selected, setSelected] = useState('');
  const appointments = useQuery({
    queryKey: ['mt-patient-appointments', aso.patient?.id, from, to],
    queryFn: async () => (await api.appointments(undefined, { patient_id: aso.patient!.id, from, to })).data.appointments,
    enabled: Boolean(aso.patient && from && to && from <= to),
  });
  const candidates = (appointments.data ?? []).filter((appointment) => appointment.status !== 'CANCELLED');
  const link = useMutation({ mutationFn: () => api.linkMtAsoExam(aso.id, selected), onSuccess: onLinked });
  return <Panel>
    <SectionTitle title={'Vincular atendimento: ' + (aso.patient?.name ?? '')} />
    <p>Selecione um atendimento do funcionário. Se necessário, agende primeiro na Agenda ou em Agendar lote.</p>
    <div className="catalog-form-grid">
      <TextField label="Atendimentos de" type="date" value={from} onChange={(event) => { setFrom(event.target.value); setSelected(''); }} />
      <TextField label="Atendimentos até" type="date" value={to} onChange={(event) => { setTo(event.target.value); setSelected(''); }} />
      <SelectField label="Atendimento do funcionário" value={selected} onChange={(event) => setSelected(event.target.value)}>
        <option value="">Selecione…</option>
        {candidates.map((appointment) => <option key={appointment.id} value={appointment.id}>{new Date(appointment.scheduled_at).toLocaleString('pt-BR', { timeZone: 'America/Fortaleza' })} · {appointment.procedure?.name} · {appointment.professional?.name} · {appointment.status}</option>)}
      </SelectField>
    </div>
    {appointments.isLoading ? <p>Buscando atendimentos…</p> : null}
    {appointments.isError ? <Alert tone="danger" title="Busca de atendimentos">{apiErrorMessage(appointments.error)}</Alert> : null}
    {!appointments.isFetching && !appointments.isError && candidates.length === 0 ? <p>Nenhum atendimento neste período.</p> : null}
    {link.isError ? <Alert tone="danger" title="Vínculo não concluído">{apiErrorMessage(link.error)}</Alert> : null}
    <div className="catalog-form-actions">
      <Button disabled={link.isPending || appointments.isFetching || !candidates.some((appointment) => appointment.id === selected)} onClick={() => link.mutate()}>Confirmar vínculo</Button>
      <Button variant="outline" disabled={link.isPending} onClick={onClose}>Fechar vínculo</Button>
    </div>
  </Panel>;
}

function CompaniesTab({ canManage, onInvalidate }: { canManage: boolean; onInvalidate: () => void }) {
  const options = useQuery({ queryKey: ['mt-options'], queryFn: api.mtOptions });
  const [agreementId, setAgreementId] = useState('');
  const [sectorName, setSectorName] = useState('');
  const [functionName, setFunctionName] = useState('');
  const [riskName, setRiskName] = useState('');

  const createSector = useMutation({ mutationFn: async () => api.createMtSector({ agreement_id: agreementId, name: sectorName }), onSuccess: () => { setSectorName(''); onInvalidate(); } });
  const createFunction = useMutation({ mutationFn: async () => api.createMtFunction({ agreement_id: agreementId, name: functionName }), onSuccess: () => { setFunctionName(''); onInvalidate(); } });
  const createRisk = useMutation({ mutationFn: async () => api.createMtRisk(riskName), onSuccess: () => { setRiskName(''); onInvalidate(); } });

  const sectors = useMemo(() => (options.data?.sectors ?? []).filter((sector) => !agreementId || sector.agreement?.id === agreementId), [options.data, agreementId]);
  const functions = useMemo(() => (options.data?.functions ?? []).filter((fn) => !agreementId || sectors.some((sector) => sector.id === fn.sector?.id)), [options.data, sectors, agreementId]);
  const risks = options.data?.risks ?? [];

  return (
    <Panel>
      <SectionTitle title="Empresa → setor → função → risco" subtitle="Modelagem do STG mantida (§6.7)" />
      <SelectField label="Empresa" value={agreementId} onChange={(event) => setAgreementId(event.target.value)}>
        <option value="">Todas</option>
        {(options.data?.agreements ?? []).map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
      </SelectField>
      {canManage ? (
        <div className="catalog-form-grid">
          <TextField label="Novo setor" value={sectorName} onChange={(event) => setSectorName(event.target.value)} />
          <span className="catalog-form-actions"><Button variant="outline" onClick={() => createSector.mutate()} disabled={!agreementId || !sectorName}>Criar setor</Button></span>
          <TextField label="Nova função" value={functionName} onChange={(event) => setFunctionName(event.target.value)} />
          <span className="catalog-form-actions"><Button variant="outline" onClick={() => createFunction.mutate()} disabled={!agreementId || !functionName}>Criar função</Button></span>
          <TextField label="Novo risco" value={riskName} onChange={(event) => setRiskName(event.target.value)} />
          <span className="catalog-form-actions"><Button variant="outline" onClick={() => createRisk.mutate()} disabled={!riskName}>Criar risco</Button></span>
        </div>
      ) : null}
      {createSector.isError ? <Alert tone="danger" title="Setor">{apiErrorMessage(createSector.error)}</Alert> : null}
      {createFunction.isError ? <Alert tone="danger" title="Função">{apiErrorMessage(createFunction.error)}</Alert> : null}
      {createRisk.isError ? <Alert tone="danger" title="Risco">{apiErrorMessage(createRisk.error)}</Alert> : null}
      <div className="two-col">
        <div>
          <h3>Setores</h3>
          <ul className="plain-list">{sectors.map((sector) => <li key={sector.id}>{sector.name} <small>({sector.agreement?.name})</small></li>)}</ul>
        </div>
        <div>
          <h3>Funções</h3>
          <ul className="plain-list">{functions.map((fn) => <li key={fn.id}>{fn.name}{fn.risk ? <small> · risco: {fn.risk.name}</small> : null}</li>)}</ul>
          <h3>Riscos</h3>
          <ul className="plain-list">{risks.map((risk) => <li key={risk.id}>{risk.name}</li>)}</ul>
        </div>
      </div>
    </Panel>
  );
}

function BatchTab() {
  const options = useQuery({ queryKey: ['mt-options'], queryFn: api.mtOptions });
  const offerings = useQuery({ queryKey: ['offerings'], queryFn: api.serviceOfferings });
  const [agreementId, setAgreementId] = useState('');
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [offeringId, setOfferingId] = useState('');
  const [date, setDate] = useState('');
  const [startTime, setStartTime] = useState('08:00');
  const [result, setResult] = useState<{ scheduled: number; failed: number } | null>(null);

  const asos = useQuery({ queryKey: ['mt-asos', agreementId], queryFn: () => api.mtAsos({ agreement_id: agreementId }), enabled: agreementId !== '' });
  const uniquePatients = useMemo(() => {
    const map = new Map<string, string>();
    (asos.data?.data.items ?? []).forEach((aso) => { if (aso.patient) map.set(aso.patient.id, aso.patient.name); });
    return [...map.entries()];
  }, [asos.data]);

  const schedule = useMutation({
    mutationFn: async () => {
      const [hour, minute] = startTime.split(':').map(Number);
      const base = new Date(`${date}T00:00:00`);
      base.setHours(hour, minute, 0, 0);
      const items = uniquePatients
        .filter(([patientId]) => selected[patientId])
        .map(([patientId], index) => ({ patient_id: patientId, starts_at: new Date(base.getTime() + index * 30 * 60000).toISOString() }));
      const response = await api.mtBatchSchedule({ offering_id: offeringId, items });
      setResult({ scheduled: response.scheduled, failed: response.failed });
      return response;
    },
  });

  return (
    <Panel>
      <SectionTitle title="Agendar lote por empresa" subtitle="Um funcionário por slot de 30 minutos; conflitos aparecem por item" />
      <div className="catalog-form-grid">
        <SelectField label="Empresa" value={agreementId} onChange={(event) => { setAgreementId(event.target.value); setSelected({}); }}>
          <option value="">Selecione…</option>
          {(options.data?.agreements ?? []).map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
        </SelectField>
        <SelectField label="Oferta (procedimento/profissional)" value={offeringId} onChange={(event) => setOfferingId(event.target.value)}>
          <option value="">Selecione…</option>
          {(offerings.data ?? []).filter((offering) => offering.active).map((offering) => (
            <option key={offering.id} value={offering.id}>{offering.procedure?.name} · {offering.professional?.name ?? 'Sem profissional'}</option>
          ))}
        </SelectField>
        <TextField label="Data" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
        <TextField label="Hora inicial" type="time" value={startTime} onChange={(event) => setStartTime(event.target.value)} />
      </div>
      {agreementId !== '' ? (
        <div>
          <p>Funcionários (da lista de ASOs da empresa):</p>
          <ul className="plain-list">
            {uniquePatients.map(([patientId, name]) => (
              <li key={patientId}>
                <label><input type="checkbox" checked={selected[patientId] === true} onChange={(event) => setSelected({ ...selected, [patientId]: event.target.checked })} /> {name}</label>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      <div className="catalog-form-actions">
        <Button onClick={() => schedule.mutate()} disabled={schedule.isPending || !offeringId || !date || uniquePatients.filter(([id]) => selected[id]).length === 0}>Agendar selecionados</Button>
      </div>
      {schedule.isError ? <Alert tone="danger" title="Erro">{apiErrorMessage(schedule.error)}</Alert> : null}
      {result ? <Alert tone={result.failed > 0 ? 'warning' : 'info'} title="Resultado">{result.scheduled} agendado(s), {result.failed} falha(s) por conflito de horário.</Alert> : null}
    </Panel>
  );
}

function CompanyReportTab() {
  const options = useQuery({ queryKey: ['mt-options'], queryFn: api.mtOptions });
  const [agreementId, setAgreementId] = useState('');
  const report = useQuery({ queryKey: ['mt-company-report', agreementId], queryFn: () => api.mtCompanyReport(agreementId), enabled: agreementId !== '' });

  return (
    <Panel>
      <SectionTitle title="Relatório por empresa" subtitle="ASOs, vencimentos e atendimentos do período" />
      <SelectField label="Empresa" value={agreementId} onChange={(event) => setAgreementId(event.target.value)}>
        <option value="">Selecione…</option>
        {(options.data?.agreements ?? []).map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
      </SelectField>
      {report.data ? (
        <div>
          <p><strong>Total de ASOs:</strong> {report.data.total_asos}</p>
          <p><strong>Por status:</strong> {Object.entries(report.data.by_status).map(([status, count]) => `${status}: ${count}`).join(' · ')}</p>
          <p><strong>Atendimentos vinculados no período:</strong> {report.data.linked_appointments_in_period}</p>
          <h3>Vencidos</h3>
          <ul className="plain-list">{report.data.overdue.map((aso) => <li key={aso.id}>{aso.patient?.name} — {aso.expires_at}</li>)}</ul>
          <h3>A vencer (30 dias)</h3>
          <ul className="plain-list">{report.data.due_soon.map((aso) => <li key={aso.id}>{aso.patient?.name} — {aso.expires_at}</li>)}</ul>
        </div>
      ) : null}
    </Panel>
  );
}
