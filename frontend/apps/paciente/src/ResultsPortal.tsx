import { useEffect, useState } from 'react';
import { Alert, Button, EmptyState, LoadingState, Panel, TextField } from '@dez/ui';

const apiBase = import.meta.env.VITE_API_URL ?? '';

type PortalResult = {
  id: string;
  procedure: { id: string; name: string } | null;
  finalized_at: string | null;
  signed_manual: boolean;
  content_html: string | null;
};

export function ResultsPortal() {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [token, setToken] = useState<string | null>(null);
  const [patientName, setPatientName] = useState<string | null>(null);
  const [expiresAt, setExpiresAt] = useState<string | null>(null);
  const [results, setResults] = useState<PortalResult[] | null>(null);
  const [open, setOpen] = useState<PortalResult | null>(null);
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (token === null || results !== null) return;
    setPending(true);
    fetch(`${apiBase}/api/v1/public/portal/results`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) throw new Error(body?.message ?? 'Não foi possível carregar os resultados.');
        setPatientName(body.data.patient_name);
        setExpiresAt(body.data.expires_at);
        setResults(body.data.results);
      })
      .catch((exception: unknown) => setError(exception instanceof Error ? exception.message : 'Erro inesperado.'))
      .finally(() => setPending(false));
  }, [token, results]);

  const enter = async () => {
    setPending(true);
    setError('');
    try {
      const response = await fetch(`${apiBase}/api/v1/public/portal/login`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ login: login.trim(), password }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body?.errors?.login?.[0] ?? body?.message ?? 'Credencial inválida.');
      setToken(body.data.token);
      setExpiresAt(body.data.expires_at);
      setPatientName(body.data.patient_name);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Erro inesperado.');
    } finally {
      setPending(false);
    }
  };

  return <main className="patient-app portal-page"><header><BrandLogo /></header>
    <section className="patient-content">
      <p className="eyebrow">{token === null ? 'Portal de resultados' : 'Resultados de exames'}</p>
      <h1>{token === null ? 'Consulte o seu resultado.' : patientName !== null ? `Olá, ${patientName}.` : 'Seus resultados.'}</h1>
      {token === null ? <>
        <p className="muted">Use o login e a senha impressos na folha de acesso entregue pela recepção.</p>
        <Panel>
          <TextField label="Login" value={login} onChange={(event) => setLogin(event.target.value)} />
          <TextField label="Senha" type="password" value={password} onChange={(event) => setPassword(event.target.value)} />
          <Button disabled={login.trim() === '' || password === '' || pending} onClick={() => enter().catch(() => undefined)}>
            {pending ? 'Entrando...' : 'Entrar'}
          </Button>
          {error !== '' ? <Alert tone="danger" title="Acesso negado">{error}</Alert> : null}
        </Panel>
      </> : <>
        {expiresAt !== null ? <p className="muted">Acesso válido até {new Date(expiresAt).toLocaleDateString('pt-BR')}.</p> : null}
        {pending ? <LoadingState label="Carregando resultados" /> : null}
        {error !== '' ? <Alert tone="danger" title="Sessão expirada">{error}</Alert> : null}
        {results !== null && results.length === 0 ? <EmptyState title="Nenhum resultado disponível" description="Os resultados publicados pela clínica aparecem aqui." /> : null}
        {results !== null && results.length > 0 ? <div className="result-list">
          {results.map((result) => <article key={result.id}>
            <span aria-hidden="true">📄</span>
            <div>
              <b>{result.procedure?.name ?? 'Laudo'}</b>
              <small>{result.finalized_at !== null ? new Date(result.finalized_at).toLocaleDateString('pt-BR') : ''}{result.signed_manual ? ' · assinado' : ''}</small>
            </div>
            <Button variant="outline" onClick={() => setOpen(result)}>Abrir</Button>
          </article>)}
        </div> : null}
        {open !== null ? <Panel>
          <h2>{open.procedure?.name ?? 'Laudo'}</h2>
          <div className="report-editor portal-result" dangerouslySetInnerHTML={{ __html: open.content_html ?? '' }} />
          <Button variant="outline" onClick={() => setOpen(null)}>Fechar</Button>
        </Panel> : null}
      </>}
    </section>
  </main>;
}

function BrandLogo() {
  return <span className="brand-inline">Clínica DEZ</span>;
}
