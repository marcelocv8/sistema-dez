import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiErrorMessage, type CurrentUser, type WhatsappLine } from '@dez/api';
import { Alert, Button, Panel } from '@dez/ui';
import { useState } from 'react';

const HEALTH_LABEL: Record<string, string> = {
  conectado: 'Conectada',
  aguardando_pareamento: 'Aguardando pareamento',
  desconectado: 'Desconectada',
  banido: 'Banida',
};

export default function WhatsAppPage({ user }: { user: CurrentUser }) {
  const lines = useQuery({ queryKey: ['whatsapp-lines'], queryFn: api.whatsappLines });
  const queryClient = useQueryClient();
  const [savingId, setSavingId] = useState<number | null>(null);
  const select = useMutation({
    mutationFn: async (conexaoId: number | null) => api.selectWhatsappLine(conexaoId),
    onMutate: (conexaoId) => setSavingId(conexaoId),
    onSettled: () => {
      setSavingId(null);
      void queryClient.invalidateQueries({ queryKey: ['whatsapp-lines'] });
    },
  });

  const data = lines.data;

  return (
    <div className="page whatsapp-page">
      <div className="page-heading"><div><h1>WhatsApp</h1><p>Canal do paciente via fork AppChat (§6.12.1): confirmações D-2, mensagem de agendamento e respostas.</p></div></div>

      {lines.isLoading ? <Panel><p>Carregando linhas…</p></Panel> : null}
      {lines.isError ? <Alert tone="danger" title="Não carregou">{apiErrorMessage(lines.error)}</Alert> : null}

      {data && !data.enabled ? (
        <Panel>
          <p><strong>Integração desligada.</strong></p>
          <p>Defina <code>WHATSAPP_ENABLED=true</code> e <code>APPCHAT_API_KEY</code> no backend para ativar o canal (T2/T3). Nada é enviado enquanto estiver desligado.</p>
        </Panel>
      ) : null}

      {data?.enabled ? (
        <>
          {data.error ? <Alert tone="warning" title="AppChat indisponível">{data.error}</Alert> : null}
          <Panel>
            <h2>Linha de envio</h2>
            <p>A linha escolhida envia as mensagens do Sistema DEZ. Sem seleção, usa automaticamente a única linha conectada.</p>
            {data.lines.length === 0 && !data.error ? <p>Nenhuma linha WhatsApp cadastrada no AppChat. Cadastre e pareie no app do AppChat — ela aparece aqui de imediato.</p> : null}
            <ul className="whatsapp-lines">
              {data.lines.map((line: WhatsappLine) => {
                const selected = data.selected === line.id;
                return (
                  <li key={line.id} className={selected ? 'whatsapp-line whatsapp-line--selected' : 'whatsapp-line'}>
                    <div>
                      <strong>{line.numero}</strong>
                      <span className="whatsapp-line-status">{HEALTH_LABEL[line.status_saude] ?? line.status_saude} · {line.provedor}</span>
                    </div>
                    {selected
                      ? <span className="whatsapp-line-selected">Linha em uso</span>
                      : <Button variant="outline" disabled={savingId !== null || line.status_saude !== 'conectado'} onClick={() => select.mutate(line.id)}>Usar esta linha</Button>}
                  </li>
                );
              })}
            </ul>
          </Panel>
          <Panel>
            <h2>Como funciona</h2>
            <ul className="whatsapp-notes">
              <li><strong>Reserva:</strong> todo agendamento (site e recepção) envia confirmação imediata ao paciente com telefone.</li>
              <li><strong>D-2:</strong> o comando agendado envia o pedido de confirmação 2 dias antes (§6.2.4).</li>
              <li><strong>2ª camada:</strong> quem não responder vira rotina de ligação no dia seguinte (§6.17); respondeu SIM/NÃO, a rotina não nasce.</li>
              <li><strong>Credencial:</strong> o AppChat entrega os eventos com um token de agente (escopo <code>whatsapp.events</code>) — gerencie em Cadastros → Agentes.</li>
            </ul>
          </Panel>
        </>
      ) : null}
    </div>
  );
}
