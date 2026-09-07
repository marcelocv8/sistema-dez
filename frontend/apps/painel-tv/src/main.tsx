import { StrictMode, useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BellRing, Clock3, MapPin, Volume2, VolumeX } from 'lucide-react';
import { BrandLogo, StatusPill } from '@dez/ui';
import '@dez/ui/styles.css';
import './styles.css';

type DisplayCall = { ticket: string; first_name: string | null; procedure_name: string | null; professional_name: string | null; called_at: string | null };
type DisplayData = { unit: { id: string; name: string }; date: string; current: DisplayCall | null; next: DisplayCall[] };
type Unit = { id: string; name: string };

const apiBase = import.meta.env.VITE_API_URL ?? '';
const storedUnit = () => new URLSearchParams(window.location.search).get('unit_id') ?? localStorage.getItem('dez-tv-unit') ?? '';

function speak(text: string) {
  try {
    window.speechSynthesis.cancel();
    const voice = new SpeechSynthesisUtterance(text);
    voice.lang = 'pt-BR';
    window.speechSynthesis.speak(voice);
  } catch {
    /* TTS indisponível: painel segue visual */
  }
}

function beep() {
  try {
    const context = new AudioContext();
    const oscillator = context.createOscillator();
    oscillator.connect(context.destination);
    oscillator.frequency.value = 880;
    oscillator.start();
    setTimeout(() => { oscillator.stop(); void context.close(); }, 600);
  } catch {
    /* áudio indisponível */
  }
}

function destinationOf(call: DisplayCall): string {
  return call.professional_name ?? call.procedure_name ?? 'Recepção';
}

function App() {
  const [units, setUnits] = useState<Unit[]>([]);
  const [unitId, setUnitId] = useState(storedUnit);
  const [data, setData] = useState<DisplayData | null>(null);
  const [now, setNow] = useState(() => new Date());
  const [sound, setSound] = useState(false);
  const [error, setError] = useState('');
  const lastCall = useRef('');

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  useEffect(() => {
    fetch(`${apiBase}/api/v1/public/units`).then(async (response) => {
      if (!response.ok) throw new Error();
      setUnits((await response.json()).data as Unit[]);
    }).catch(() => setError('Não foi possível carregar as unidades. Confira o VITE_API_URL.'));
  }, []);

  const load = useCallback(async () => {
    if (!unitId) return;
    try {
      const response = await fetch(`${apiBase}/api/v1/public/display?unit_id=${unitId}`);
      if (!response.ok) throw new Error();
      const payload = (await response.json()).data as DisplayData;
      setData(payload);
      setError('');
      const key = payload.current ? `${payload.current.ticket}-${payload.current.called_at}` : '';
      if (key && key !== lastCall.current) {
        lastCall.current = key;
        if (sound && payload.current) {
          beep();
          speak(`Senha ${payload.current.ticket.split('').join(' ')}, ${payload.current.first_name ?? 'paciente'}, dirija-se a ${destinationOf(payload.current)}`);
        }
      }
    } catch {
      setError('Painel sem conexão com a API. Tentando de novo...');
    }
  }, [unitId, sound]);

  useEffect(() => {
    if (!unitId) return;
    localStorage.setItem('dez-tv-unit', unitId);
    void load();
    const id = setInterval(() => void load(), 10000);
    return () => clearInterval(id);
  }, [unitId, load]);

  const pick = (id: string) => {
    lastCall.current = '';
    setData(null);
    setUnitId(id);
  };

  if (!unitId) {
    return <main className="tv-app"><header><BrandLogo inverted /><div><MapPin />Clínica DEZ</div><time>{now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</time></header>
      <section className="tv-stage"><article className="current-call"><p>Configurar painel</p><h2>Escolha a unidade</h2>
        <div>{units.map((unit) => <button key={unit.id} onClick={() => pick(unit.id)}>{unit.name}</button>)}</div>
        {error ? <p>{error}</p> : null}
        <small>Ou abra com ?unit_id= na URL.</small></article></section><footer>Cuidado acessível, acolhimento de verdade <span>•</span> Clínica DEZ</footer></main>;
  }

  const current = data?.current ?? null;
  const upcoming = [...(data?.next ?? [])];
  return <main className="tv-app"><header><BrandLogo inverted /><div><MapPin />{data?.unit.name ?? 'Clínica DEZ'}</div><time>{now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</time></header>
    <section className="tv-stage">
      <article className="current-call"><span className="sound">{sound ? <Volume2 /> : <VolumeX />}</span>
        <p>{current ? 'Chamando agora' : 'Aguarde sua senha'}</p>
        {current ? <><h1>{current.ticket}</h1><h2>{current.first_name ?? 'Paciente'}</h2>
          <div className="destination"><span>Dirija-se a</span><b>{destinationOf(current)}</b><small>{current.procedure_name ?? ''}</small></div></>
          : <><h2>Nenhuma chamada no momento</h2><p>Acompanhe sua senha no painel.</p></>}
        {error ? <p>{error}</p> : null}
        <button onClick={() => setSound((value) => !value)} aria-label="Ativar ou desativar som"><BellRing />{sound ? 'Som ativado' : 'Ativar som e voz'}</button>
      </article>
      <aside><div className="queue-title"><Clock3 /><span><b>Próximas senhas</b>Acompanhe sua vez</span></div>
        {upcoming.length === 0 ? <p>Ninguém aguardando.</p> : null}
        {upcoming.map((item) => <div className="past-call" key={item.ticket}><strong>{item.ticket}</strong><span><b>{item.first_name ?? 'Paciente'}</b><small>{destinationOf(item)}</small></span><StatusPill tone="info">{item.procedure_name ?? 'Atendimento'}</StatusPill></div>)}
        <div className="tv-tip"><b>Fique atento</b><p>Tenha seu documento em mãos e aguarde a senha no painel.</p></div>
      </aside>
    </section><footer>Cuidado acessível, acolhimento de verdade <span>•</span> Clínica DEZ</footer></main>;
}

createRoot(document.getElementById('root')!).render(<StrictMode><App /></StrictMode>);
