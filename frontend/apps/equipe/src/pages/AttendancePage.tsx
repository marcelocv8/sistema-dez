import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser } from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, SectionTitle, SelectField, StatusPill, TextField } from '@dez/ui';

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });
const weekAgo = () => {
  const date = new Date(`${today()}T12:00:00`);
  date.setDate(date.getDate() - 6);
  return date.toISOString().slice(0, 10);
};

export default function AttendancePage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [from, setFrom] = useState(weekAgo);
  const [to, setTo] = useState(today);
  const [exitHour, setExitHour] = useState('');
  const canManageConfig = user.permissions.includes('team.manage');

  const report = useQuery({ queryKey: ['attendance', from, to], queryFn: () => api.attendanceReport({ from, to }) });
  const users = useQuery({ queryKey: ['team-users'], queryFn: () => api.teamUsers(), enabled: canManageConfig });
  const saveExit = useMutation({
    mutationFn: () => api.saveAttendanceExitHour(exitHour),
    onSuccess: async () => {
      await Promise.all([client.invalidateQueries({ queryKey: ['attendance'] }), client.invalidateQueries({ queryKey: ['attendance-exit'] })]);
    },
  });
  const exportCsv = useMutation({
    mutationFn: () => api.attendanceExport({ from, to }),
    onSuccess: (data) => {
      const blob = new Blob([data.csv], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = data.filename;
      anchor.click();
      URL.revokeObjectURL(url);
    },
  });

  if (!user.permissions.includes('attendance.view')) {
    return <div className="page"><Panel><EmptyState title="Sem acesso ao ponto" description="O relatório de ponto é exclusivo de Admin e Supervisor." /></Panel></div>;
  }
  const rows = report.data?.days ?? [];

  return <div className="page attendance-page">
    <div className="page-heading"><div><h1>Controle de ponto</h1><p>Cada login registra a entrada do dia; o relogin aparece sinalizado.</p></div></div>
    <Panel>
      <h2>Relatório</h2>
      <div className="catalog-form-grid">
        <TextField label="De" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
        <TextField label="Até" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
        <div><label className="catalog-check-label" htmlFor="exit-hour">Saída configurada (fecha o dia)</label>
          <div className="inline-pair">
            <TextField label="Novo horário de saída (HH:MM)" id="exit-hour" value={exitHour} placeholder={report.data?.exit_hour ?? '18:00'} maxLength={5} onChange={(event) => setExitHour(event.target.value)} />
            <Button disabled={!canManageConfig || !/^\d{2}:\d{2}$/.test(exitHour) || saveExit.isPending} onClick={() => saveExit.mutate()}>Salvar</Button>
          </div>
          {!canManageConfig ? <p>Só o Admin altera o horário de saída.</p> : null}
        </div>
        {users.data ? <SelectField label="Filtrar usuário (não aplicado — exporte para análise)" disabled><option>Todos</option></SelectField> : null}
      </div>
      {report.isError ? <Alert tone="danger" title="Relatório indisponível">{apiErrorMessage(report.error)}</Alert> : null}
      {saveExit.isError ? <Alert tone="danger" title="Horário não salvo">{apiErrorMessage(saveExit.error)}</Alert> : null}
      {saveExit.isSuccess ? <p role="status">Horário de saída atualizado para {exitHour}.</p> : null}
      <div className="catalog-form-actions">
        <Button variant="outline" disabled={exportCsv.isPending} onClick={() => exportCsv.mutate()}>Exportar CSV</Button>
      </div>
      {exportCsv.isError ? <Alert tone="danger" title="Exportação falhou">{apiErrorMessage(exportCsv.error)}</Alert> : null}
    </Panel>
    <Panel>
      <SectionTitle title={`${rows.length} registro(s) de dias`} action={<b className="teal">Saída {report.data?.exit_hour ?? '—'}</b>} />
      {report.isLoading ? <LoadingState label="Buscando ponto" /> : null}
      {!report.isLoading && rows.length === 0 ? <EmptyState title="Sem logins no período" description="Cada login no sistema registra automaticamente a entrada aqui." /> : null}
      <div className="catalog-list-panel">{rows.map((day) => <div className="catalog-row" key={`${day.user_id}-${day.date}`}>
        <span><strong>{day.user_name}</strong><small>{day.roles.join(', ')} · {day.date}</small>
          <small>Entradas: {day.logins.join(', ')} · Saída prevista: {day.exit_hour}</small></span>
        <span><StatusPill tone={day.relogin_after_exit ? 'danger' : day.relogins > 0 ? 'warning' : 'success'}>
          {day.relogin_after_exit ? `Relogin após a saída (${day.relogins})` : day.relogins > 0 ? `${day.relogins} relogin(s)` : 'Entrada única'}
        </StatusPill></span>
      </div>)}</div>
    </Panel>
  </div>;
}
