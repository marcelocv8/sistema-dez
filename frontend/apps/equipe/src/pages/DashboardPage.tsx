import { useQuery } from '@tanstack/react-query';
import { api, type DashboardQueueItem } from '@dez/api';
import { formatClinicDate } from '@dez/domain';
import { EmptyState, LoadingState, Panel, SectionTitle, StatusPill } from '@dez/ui';

const queueStatus: Record<string, { label: string; tone: 'success' | 'warning' | 'purple' | 'info' }> = {
  WAITING: { label: 'Na fila', tone: 'warning' },
  IN_SERVICE: { label: 'Em atendimento', tone: 'purple' },
  CHECKOUT: { label: 'Em caixa', tone: 'info' },
  FINISHED: { label: 'Concluído', tone: 'success' },
};

export default function DashboardPage() {
  const { data, isLoading, isError } = useQuery({ queryKey: ['dashboard'], queryFn: api.dashboard });
  if (isLoading) return <LoadingState label="Preparando visão geral" />;
  if (isError || !data) return <EmptyState title="Não foi possível carregar a visão geral" description="Tente novamente em instantes." />;
  const summary = data.data;

  return <div className="page page-dashboard">
    <div className="page-heading"><div><h1>Visão geral</h1><p>Resumo da operação de {formatClinicDate(`${summary.date}T12:00:00`, { weekday: 'long' })}</p></div></div>
    <div className="dashboard-grid">
      <Panel className="dashboard-card"><SectionTitle title="Fluxo de pacientes" action={<a href="/recepcao">Ver detalhes</a>} />
        <div className="flow-stats"><span><small>Na fila</small><b className="red">{summary.patient_flow.waiting}</b></span><span><small>Em atendimento</small><b>{summary.patient_flow.in_care}</b></span><span><small>Em caixa</small><b>{summary.patient_flow.checkout}</b></span><span><small>Finalizados</small><b className="green">{summary.patient_flow.finished}</b></span></div>
        {summary.queue.length > 0 ? <div className="dashboard-list">{summary.queue.map((item) => <QueueRow item={item} key={item.id} />)}</div>
          : <p className="queue-empty">Ninguém na fila agora.</p>}
      </Panel>
      <Panel className="dashboard-card"><SectionTitle title="Financeiro" />
        {!summary.financials_available ? <EmptyState title="Sem dados integrados ainda" description="Receita, fechamento de caixa, repasses médicos e faturas B2B aparecerão aqui quando o módulo financeiro for conectado." />
          : <div />}
      </Panel>
    </div>
  </div>;
}

function QueueRow({ item }: { item: DashboardQueueItem }) {
  const meta = queueStatus[item.status] ?? { label: item.status, tone: 'info' as const };
  return <div><span>{item.patient_name ?? 'Paciente'}</span><time>{item.checked_in_at?.slice(11, 16) ?? '--:--'}</time><StatusPill tone={meta.tone}>{meta.label}</StatusPill></div>;
}
