import { useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { api } from '@dez/api';
import { Bold, CheckCircle2, CloudUpload, Download, FileClock, Italic, List, Printer, Redo2, Save, Stethoscope, Underline, Undo2 } from 'lucide-react';
import { Button, IconButton, Panel, StatusPill } from '@dez/ui';

const history = [['24/05/2025 10:15','Laudo','Rascunho'],['24/05/2025 09:10','Anamnese','Concluído'],['24/05/2025 09:00','Sinais vitais','Concluído'],['20/05/2025 14:30','Resultado de exame','Concluído'],['15/04/2025 11:20','Laudo','Concluído']];
const editorTools = [{ Icon: Bold, label: 'Negrito' }, { Icon: Italic, label: 'Itálico' }, { Icon: Underline, label: 'Sublinhado' }, { Icon: List, label: 'Lista' }, { Icon: Undo2, label: 'Desfazer' }, { Icon: Redo2, label: 'Refazer' }];

export default function ReportPage() {
  const [saveLabel, setSaveLabel] = useState('Rascunho salvo');
  const [published, setPublished] = useState(false);
  const saveTimer = useRef<number | null>(null);
  const publish = useMutation({ mutationFn: () => api.publishReport('1'), onSuccess: () => setPublished(true) });
  function scheduleSave() { setSaveLabel('Salvando...'); if (saveTimer.current) window.clearTimeout(saveTimer.current); saveTimer.current = window.setTimeout(() => setSaveLabel('Rascunho salvo'), 700); }
  return <div className="report-page"><header className="patient-header"><span className="patient-icon">MS</span><div><h1>Maria de Souza</h1><p>Feminino, 45 anos · 321.654.987-00</p></div><dl><div><dt>Atendimento</dt><dd>24/05/2025 10:15</dd></div><div><dt>Convênio</dt><dd>Unimed Empresarial</dd></div><div><dt>Médico</dt><dd>Dr. João Oliveira</dd></div></dl><span className="saved-state"><CheckCircle2 size={19}/><b>{saveLabel}</b></span></header>
    <div className="report-workspace"><aside className="report-history"><h2>Histórico</h2>{history.map(([date,title,status],i) => <button className={i===0?'active':''} key={date}><i/><time>{date}</time><strong>{title}</strong><StatusPill tone={status==='Rascunho'?'warning':'success'}>{status}</StatusPill></button>)}</aside>
      <main className="editor-shell"><header><h2>Laudo</h2><Button variant="outline">Modelos</Button></header><div className="editor-toolbar" role="toolbar" aria-label="Formatação"><select aria-label="Estilo do texto"><option>Parágrafo</option><option>Título 1</option><option>Título 2</option></select>{editorTools.map(({ Icon,label }) => <IconButton key={label} label={label}><Icon size={16}/></IconButton>)}</div><article className="report-editor" contentEditable suppressContentEditableWarning onInput={scheduleSave}>
        <h3>IDENTIFICAÇÃO</h3><p><b>Paciente:</b> Maria de Souza<br/><b>Data de nascimento:</b> 12/03/1980<br/><b>Atendimento:</b> 24/05/2025<br/><b>Médico solicitante:</b> Dr. João Oliveira (CRM 123456/CE)</p>
        <h3>HISTÓRICO CLÍNICO</h3><p>Paciente relata cefaleia recorrente há 6 meses, de intensidade moderada, localizada em região frontal, associada a náuseas ocasionais.</p>
        <h3>EXAME FÍSICO</h3><p>Estado geral: bom<br/>PA: 120×80 mmHg<br/>FC: 78 bpm<br/>FR: 16 irpm<br/>Temperatura: 36,6 °C</p>
        <h3>IMPRESSÃO DIAGNÓSTICA</h3><p>Cefaleia tensional.</p><h3>CONDUTA</h3><p>Orientações gerais, uso de analgésico conforme necessidade e reavaliação em 30 dias.</p><h3>CID</h3><p>G44.2 – Cefaleia tensional</p>
      </article><footer><span>Palavras: 91 · Caracteres: 612</span><span><Save size={14}/>Salvo automaticamente</span></footer></main>
      <aside className="report-actions"><Panel className="draft-card"><FileClock size={20}/><div><b>Rascunho</b><span>Salvo automaticamente. Você pode continuar editando.</span></div></Panel><Button variant="primary" icon={<Printer size={19}/>} onClick={() => window.print()}>Imprimir laudo <kbd>Ctrl + P</kbd></Button><Button variant="outline" icon={<Stethoscope size={19}/>}>Prescrição Memed</Button><Button variant={published?'success':'purple'} icon={published?<CheckCircle2 size={19}/>:<CloudUpload size={19}/>} disabled={publish.isPending} onClick={() => publish.mutate()}>{published?'Resultado publicado':'Publicar resultado'}</Button><Button variant="outline" icon={<Download size={19}/>}>Baixar PDF</Button><Button variant="outline" icon={<FileClock size={19}/>}>Ver versões anteriores</Button><div className="secure-note"><CheckCircle2 size={20}/><span><b>Publicação segura</b>O paciente acessa o resultado pelo portal com credencial temporária.</span></div></aside>
    </div>
  </div>;
}
