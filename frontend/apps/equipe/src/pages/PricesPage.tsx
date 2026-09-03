import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { api, type TeamPriceGroup } from '@dez/api';
import { formatMoney } from '@dez/domain';
import { Alert, EmptyState, LoadingState, Panel, SelectField, TextField } from '@dez/ui';

type PriceType = 'CONSULTATION' | 'EXAM';

export default function PricesPage() {
  const [query, setQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [type, setType] = useState<PriceType>('CONSULTATION');
  const [agreementId, setAgreementId] = useState('');
  const [group, setGroup] = useState('');
  const options = useQuery({ queryKey: ['price-options'], queryFn: api.priceOptions });

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedQuery(query.trim()), 350);
    return () => window.clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    if (!agreementId && options.data) {
      setAgreementId(options.data.agreements.find((agreement) => agreement.is_default)?.id ?? '');
    }
  }, [agreementId, options.data]);

  useEffect(() => setGroup(''), [type]);

  const prices = useQuery({
    queryKey: ['prices', debouncedQuery, type, group, agreementId],
    queryFn: () => api.prices({ q: debouncedQuery, type, group: group || undefined, agreement_id: agreementId || undefined }),
    enabled: agreementId !== '',
  });

  const groups = useMemo<TeamPriceGroup[]>(() => prices.data?.types.find((item) => item.type === type)?.groups ?? [], [prices.data, type]);

  return <div className="page prices-page">
    <div className="page-heading"><div><h1>Consulta de preços</h1><p>Valores vigentes do catálogo conciliado com o STG.</p></div></div>
    <Panel className="price-filters">
      <div className="price-search"><Search size={18} aria-hidden="true" /><TextField label="Procedimento, TUSS ou código" placeholder="Busque pelo nome, TUSS ou código" value={query} onChange={(event) => setQuery(event.target.value)} /></div>
      <SelectField label="Convênio" value={agreementId} onChange={(event) => setAgreementId(event.target.value)} disabled={options.isLoading}>
        <option value="">Selecione</option>
        {options.data?.agreements.map((agreement) => <option key={agreement.id} value={agreement.id}>{agreement.name}</option>)}
      </SelectField>
      <SelectField label="Subgrupo" value={group} onChange={(event) => setGroup(event.target.value)}>
        <option value="">Todos</option>
        {options.data?.groups[type].map((item) => <option key={item} value={item}>{item}</option>)}
      </SelectField>
    </Panel>
    <div className="price-tabs" role="tablist" aria-label="Tipo de procedimento">
      <button role="tab" aria-selected={type === 'CONSULTATION'} className={type === 'CONSULTATION' ? 'active' : ''} onClick={() => setType('CONSULTATION')}>Consultas</button>
      <button role="tab" aria-selected={type === 'EXAM'} className={type === 'EXAM' ? 'active' : ''} onClick={() => setType('EXAM')}>Exames</button>
    </div>
    {options.isError ? <Alert tone="danger" title="Não foi possível carregar os filtros">Tente novamente em instantes.</Alert> : null}
    {prices.isFetching ? <LoadingState label="Consultando preços vigentes" /> : null}
    {prices.isError ? <Alert tone="danger" title="Falha na consulta">Verifique sua conexão e tente novamente.</Alert> : null}
    {!prices.isFetching && prices.isSuccess && groups.length === 0 ? <EmptyState title="Nenhum preço encontrado" description="Revise o termo, tipo, subgrupo ou convênio selecionado." /> : null}
    <div className="price-groups">{groups.map((priceGroup) => <Panel key={priceGroup.group_name} className="price-group">
      <header><h2>{priceGroup.group_name}</h2><span>{priceGroup.items.length} {priceGroup.items.length === 1 ? 'resultado' : 'resultados'}</span></header>
      <div className="price-table" role="table" aria-label={priceGroup.group_name}>
        {priceGroup.items.map((item) => <div className="price-row" role="row" key={item.id}>
          <span><strong>{item.procedure.name}</strong><small>Cód. {item.procedure.legacy_id}{item.procedure.tuss_code ? ` · TUSS ${item.procedure.tuss_code}` : ''}</small></span>
          <span><small>{item.agreement.name}</small>{item.unit ? <small>{item.unit.name}</small> : <small>Todas as unidades</small>}</span>
          <b>{formatMoney(item.amount)}</b>
        </div>)}
      </div>
    </Panel>)}</div>
  </div>;
}
