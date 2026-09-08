import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type RoutineTaskItem } from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, StatusPill, TextField } from '@dez/ui';

const typeLabel: Record<string, string> = {
  CONFIRMACAO: 'Confirmação', ORCAMENTO_RETORNO: 'Orçamento', ANIVERSARIANTE: 'Retorno', FATURA_VENCENDO: 'Financeiro', MANUAL: 'Manual',
};
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Fortaleza' });

export default function RoutinesPage({ user }: { user: CurrentUser }) {
  const client = useQueryClient();
  const [date, setDate] = useState(today);
  const [tickFor, setTickFor] = useState('');
  const [note, setNote] = useState('');
  const [reopenFor, setReopenFor] = useState('');
  const [reopenNote, setReopenNote] = useState('');
  const canGenerate = user.permissions.includes('routine.manage');
  const canReopen = canGenerate;

  const day = useQuery({ queryKey: ['routines', date], queryFn: () => api.routines({ date, scope: canGenerate ? 'all' : 'mine' }) });
  const tasks = day.data?.tasks ?? [];
  const pending = tasks.filter((task) => task.status === 'PENDENTE');
  const done = tasks.filter((task) => task.status === 'FEITO');
  const refresh = () => client.invalidateQueries({ queryKey: ['routines'] });
  const generate = useMutation({ mutationFn: () => api.generateRoutines(date), onSuccess: refresh });
  const tick = useMutation({
    mutationFn: () => api.tickRoutine(tickFor, note.trim() === '' ? undefined : note),
    onSuccess: async () => { setTickFor(''); setNote(''); await refresh(); },
  });
  const reopen = useMutation({
    mutationFn: () => api.reopenRoutine(reopenFor, reopenNote),
    onSuccess: async () => { setReopenFor(''); setReopenNote(''); await refresh(); },
  });

  const taskRow = (task: RoutineTaskItem) => <div className="catalog-row" key={task.id}>
    <span><strong>{task.title}</strong><small>{typeLabel[task.type] ?? task.type} · {task.assignee_name ?? 'Geral'}{task.detail ? ` · ${task.detail}` : ''}</small>
      {task.note ? <small>Crítica: {task.note}{task.done_by_name ? ` · ${task.done_by_name}` : ''}</small> : null}</span>
    <StatusPill tone={task.status === 'FEITO' ? 'success' : 'warning'}>{task.status === 'FEITO' ? 'Feito' : 'Pendente'}</StatusPill>
    <div className="catalog-actions">
      {task.status === 'PENDENTE' && user.permissions.includes('routine.tick') ? <Button variant="success" onClick={() => { setTickFor(task.id); setNote(''); }}>Tick</Button> : null}
      {task.status === 'FEITO' && canReopen ? <Button variant="outline" onClick={() => { setReopenFor(task.id); setReopenNote(''); }}>Reabrir</Button> : null}
    </div>
    {tickFor === task.id ? <div className="catalog-form-grid">
      <TextField label="Crítica/observação (opcional)" value={note} maxLength={1000} onChange={(event) => setNote(event.target.value)} />
    </div> : null}
    {tickFor === task.id ? <div className="catalog-form-actions"><Button variant="success" disabled={tick.isPending} onClick={() => tick.mutate()}>Confirmar feito</Button><Button variant="outline" onClick={() => setTickFor('')}>Cancelar</Button></div> : null}
    {reopenFor === task.id ? <div className="catalog-form-grid">
      <TextField label="Motivo da reabertura" value={reopenNote} minLength={3} required onChange={(event) => setReopenNote(event.target.value)} />
    </div> : null}
    {reopenFor === task.id ? <div className="catalog-form-actions"><Button disabled={reopenNote.trim().length < 3 || reopen.isPending} onClick={() => reopen.mutate()}>Reabrir tarefa</Button><Button variant="outline" onClick={() => setReopenFor('')}>Cancelar</Button></div> : null}
  </div>;

  return <div className="page routines-page">
    <div className="page-heading"><div><h1>Rotinas</h1><p>Lista do dia gerada automaticamente — tick com crítica e histórico.</p></div>
      {canGenerate ? <Button disabled={generate.isPending} onClick={() => generate.mutate()}>{generate.isPending ? 'Gerando...' : 'Gerar/refresh do dia'}</Button> : null}</div>
    <Panel>
      <TextField label="Data" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
      {generate.isError ? <Alert tone="danger" title="Geração falhou">{apiErrorMessage(generate.error)}</Alert> : null}
      {generate.isSuccess ? <p role="status">{generate.data.created} tarefa(s) nova(s) para {generate.data.date}.</p> : null}
    </Panel>
    <Panel>
      <h2>Pendentes ({pending.length})</h2>
      {day.isLoading ? <LoadingState label="Carregando rotinas" /> : null}
      {day.isError ? <Alert tone="danger" title="Rotinas indisponíveis">{apiErrorMessage(day.error)}</Alert> : null}
      {!day.isLoading && tasks.length === 0 ? <EmptyState title="Nada para hoje" description="As rotinas diárias aparecem aqui conforme são geradas pelo sistema." /> : null}
      <div className="catalog-list-panel">{pending.map(taskRow)}</div>
    </Panel>
    {done.length > 0 ? <Panel>
      <h2>Concluídas ({done.length})</h2>
      <div className="catalog-list-panel">{done.map(taskRow)}</div>
      {reopen.isError ? <Alert tone="danger" title="Reabertura falhou">{apiErrorMessage(reopen.error)}</Alert> : null}
    </Panel> : null}
    {tick.isError ? <Alert tone="danger" title="Tick não registrado">{apiErrorMessage(tick.error)}</Alert> : null}
  </div>;
}
