import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, apiErrorMessage, type AnalyticsSummary } from '@dez/api';
import { Alert, EmptyState, Panel, SectionTitle, SelectField, TextField } from '@dez/ui';

function isoDaysAgo(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().slice(0, 10);
}

export default function RelatoriosPage() {
  const [from, setFrom] = useState(isoDaysAgo(29));
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10));
  const [agreementId, setAgreementId] = useState('');

  const analytics = useQuery({
    queryKey: ['analytics', from, to, agreementId],
    queryFn: () => api.analytics({ from, to, agreement_id: agreementId || undefined }),
  });
  const options = useQuery({ queryKey: ['mt-options'], queryFn: api.mtOptions });

  return (
    <div className="page">
      <div className="page-heading"><div><h1>Relatórios & BI</h1><p>Dados na mão por período (§6.13): produção, receita, no-show, caixa, B2B e ASOs.</p></div></div>
      <Panel>
        <div className="catalog-form-grid">
          <TextField label="De" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          <TextField label="Até" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
          <SelectField label="Empresa" value={agreementId} onChange={(event) => setAgreementId(event.target.value)}>
            <option value="">Todas</option>
            {(options.data?.agreements ?? []).map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
          </SelectField>
        </div>
        <div className="export-row">
          <a className="dez-button dez-button--secondary" href={api.analyticsExportUrl('production', { from, to })} download>CSV produção</a>
          <a className="dez-button dez-button--secondary" href={api.analyticsExportUrl('revenue', { from, to })} download>CSV receita</a>
          <a className="dez-button dez-button--secondary" href={api.analyticsExportUrl('b2b_open', {})} download>CSV B2B em aberto</a>
          <a className="dez-button dez-button--secondary" href={api.analyticsExportUrl('asos_due', {})} download>CSV ASOs a vencer</a>
        </div>
      </Panel>
      {analytics.isLoading ? <Panel><p>Calculando…</p></Panel> : null}
      {analytics.isError ? <Alert tone="danger" title="Erro">{apiErrorMessage(analytics.error)}</Alert> : null}
      {analytics.data ? <Summary data={analytics.data} /> : null}
    </div>
  );
}

function Summary({ data }: { data: AnalyticsSummary }) {
  return (
    <div className="stack-gap">
      <Panel>
        <SectionTitle title={`Período ${data.period.from} a ${data.period.to}`} />
        <div className="kpi-grid">
          <Kpi label="Receita (R$)" value={data.revenue} />
          <Kpi label="Ticket médio" value={data.ticket_avg} />
          <Kpi label="No-show" value={`${data.appointments.no_show_rate}%`} />
          <Kpi label="Agendados → realizados" value={`${data.appointments.scheduled} → ${data.appointments.done}`} />
          <Kpi label="ASOs a vencer" value={String(data.asos_due)} />
          <Kpi label="Fila: tempo médio" value={`${data.queue.avg_care_minutes} min`} />
        </div>
      </Panel>
      <div className="two-col">
        <Panel>
          <SectionTitle title="Produção por médico" />
          {data.production_by_doctor.length === 0 ? <EmptyState title="Sem atendimentos" description="Nenhum atendimento realizado no período." /> : null}
          {data.production_by_doctor.length > 0 ? (
            <table className="data-table">
              <thead><tr><th>Médico</th><th>Atend.</th><th>Receita</th></tr></thead>
              <tbody>{data.production_by_doctor.map((row) => <tr key={row.doctor_id}><td>{row.doctor}</td><td>{row.appointments}</td><td>{row.revenue.toFixed(2)}</td></tr>)}</tbody>
            </table>
          ) : null}
        </Panel>
        <Panel>
          <SectionTitle title="Receita por convênio" />
          {data.revenue_by_agreement.length === 0 ? <EmptyState title="Sem receita" description="Nenhum pagamento no período." /> : null}
          {data.revenue_by_agreement.length > 0 ? (
            <table className="data-table">
              <thead><tr><th>Convênio</th><th>Pagtos</th><th>Total</th></tr></thead>
              <tbody>{data.revenue_by_agreement.map((row) => <tr key={row.agreement_id}><td>{row.agreement}</td><td>{row.count}</td><td>{row.total.toFixed(2)}</td></tr>)}</tbody>
            </table>
          ) : null}
          <SectionTitle title="Mix de pagamento" />
          <ul className="plain-list">{data.payment_mix.map((mix) => <li key={mix.method}>{mix.method}: {mix.count} × {mix.total.toFixed(2)}</li>)}</ul>
        </Panel>
      </div>
      <div className="two-col">
        <Panel>
          <SectionTitle title="Caixa" />
          <ul className="plain-list">
            <li>Quebra acumulada: {data.cash.break_total}</li>
            <li>Sangrias: {data.cash.sangrias}</li>
            <li>Repasses pagos: {data.cash.payouts_paid}</li>
            <li>Repasses em aberto: {data.cash.payouts_open}</li>
          </ul>
        </Panel>
        <Panel>
          <SectionTitle title={`B2B em aberto (${data.b2b_open.count})`} />
          <ul className="plain-list">
            {Object.entries(data.b2b_open.aging).map(([bucket, total]) => <li key={bucket}>{agingLabel(bucket)}: {Number(total).toFixed(2)}</li>)}
          </ul>
        </Panel>
      </div>
    </div>
  );
}

function agingLabel(bucket: string): string {
  return { a_vencer: 'A vencer', vencida_30: 'Vencida até 30d', vencida_60: 'Vencida 31-60d', vencida_90: 'Vencida 61-90d', vencida_90_mais: 'Vencida 90d+' }[bucket] ?? bucket;
}

function Kpi({ label, value }: { label: string; value: string }) {
  return <div className="kpi"><span className="kpi-label">{label}</span><span className="kpi-value">{value}</span></div>;
}
