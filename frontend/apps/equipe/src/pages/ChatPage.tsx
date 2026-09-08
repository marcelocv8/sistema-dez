import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient, useInfiniteQuery } from '@tanstack/react-query';
import { api, apiErrorMessage, type ChatConversationSummary } from '@dez/api';
import { Alert, Button, EmptyState, LoadingState, Panel, TextField } from '@dez/ui';

export default function ChatPage() {
  const client = useQueryClient();
  const [activeId, setActiveId] = useState('');
  const [draft, setDraft] = useState('');
  const [cpf, setCpf] = useState('');
  const [attachedPatient, setAttachedPatient] = useState<{ id: string; name: string } | null>(null);
  const [newMode, setNewMode] = useState(false);
  const [newTitle, setNewTitle] = useState('');
  const [newMembers, setNewMembers] = useState<string[]>([]);
  const [error, setError] = useState('');
  const bottom = useRef<HTMLDivElement | null>(null);

  const conversations = useQuery({ queryKey: ['chat-conversations'], queryFn: api.chatConversations, refetchInterval: 10000 });
  const participants = useQuery({ queryKey: ['chat-participants'], queryFn: api.chatParticipants, enabled: newMode });
  const messages = useInfiniteQuery({
    queryKey: ['chat-messages', activeId],
    initialPageParam: undefined as string | undefined,
    queryFn: ({ pageParam }) => api.chatMessages(activeId, undefined, pageParam),
    getNextPageParam: (lastPage) => lastPage.length === 200 ? lastPage[0].id : undefined,
    enabled: Boolean(activeId),
    refetchInterval: 5000,
  });

  const messageRows = [...new Map((messages.data?.pages.slice().reverse().flat() ?? []).map((message) => [message.id, message])).values()];

  useEffect(() => {
    if (!activeId && (conversations.data?.length ?? 0) > 0) setActiveId(conversations.data![0].id);
  }, [activeId, conversations.data]);

  useEffect(() => {
    if (typeof bottom.current?.scrollIntoView === 'function') bottom.current.scrollIntoView({ block: 'end' });
  }, [messageRows.length]);

  const invalidate = () => Promise.all([
    client.invalidateQueries({ queryKey: ['chat-conversations'] }),
    client.invalidateQueries({ queryKey: ['chat-messages'] }),
    client.invalidateQueries({ queryKey: ['chat-unread'] }),
  ]);
  const send = useMutation({
    mutationFn: () => api.sendChatMessage(activeId, { body: draft, patient_id: attachedPatient?.id }),
    onSuccess: async () => {
      setDraft('');
      setAttachedPatient(null);
      setCpf('');
      setError('');
      await invalidate();
    },
    onError: (e) => setError(apiErrorMessage(e)),
  });
  const openConversation = useMutation({
    mutationFn: (id: string) => api.markChatRead(id),
    onSuccess: () => client.invalidateQueries({ queryKey: ['chat-unread'] }),
  });
  const create = useMutation({
    mutationFn: () => api.createChatConversation({
      type: newMembers.length > 1 ? 'GROUP' : 'DIRECT',
      title: newMembers.length > 1 ? newTitle : undefined,
      participant_ids: newMembers.map(Number),
    }),
    onSuccess: async (conversation) => {
      setNewMode(false);
      setNewTitle('');
      setNewMembers([]);
      setActiveId(conversation.id);
      await invalidate();
    },
    onError: (e) => setError(apiErrorMessage(e)),
  });
  const lookup = useMutation({
    mutationFn: () => api.chatLookupPatient(cpf.replace(/\D/g, '')),
    onSuccess: (found) => {
      if (found) setAttachedPatient(found);
      else setError('Paciente não encontrado pelo CPF.');
    },
    onError: (e) => setError(apiErrorMessage(e)),
  });

  const active = (conversations.data ?? []).find((item) => item.id === activeId);
  const title = active ? (active.type === 'GROUP' ? active.title : active.participants.map((p) => p.name).join(', ')) : 'Bate-papo';
  const pickConversation = (conversation: ChatConversationSummary) => {
    setActiveId(conversation.id);
    openConversation.mutate(conversation.id);
  };

  return <div className="page chat-page">
    <div className="page-heading"><div><h1>Bate-papo</h1><p>Médico ↔ recepção e demais papéis — sem WhatsApp pessoal.</p></div>
      <Button variant="outline" onClick={() => setNewMode((value) => !value)}>{newMode ? 'Fechar nova conversa' : 'Nova conversa'}</Button></div>
    {error ? <Alert tone="danger" title="Atenção">{error}</Alert> : null}
    {newMode ? <Panel><h2>Nova conversa</h2>
      <div className="catalog-form-grid">
        <TextField label="Título do grupo (se 2+ pessoas)" value={newTitle} onChange={(event) => setNewTitle(event.target.value)} />
      </div>
      <div className="chat-people">{(participants.data ?? []).map((person) => {
        const selected = newMembers.includes(String(person.id));
        return <button type="button" key={person.id} className={selected ? 'selected' : ''} onClick={() => setNewMembers((current) => selected ? current.filter((id) => id !== String(person.id)) : [...current, String(person.id)])}>
          <b>{person.name}</b><small>{person.role_label ?? person.role}</small>
        </button>;
      })}</div>
      <div className="catalog-form-actions"><Button disabled={newMembers.length === 0 || create.isPending} onClick={() => create.mutate()}>
        {create.isPending ? 'Criando...' : newMembers.length > 1 ? `Criar grupo (${newMembers.length})` : 'Abrir conversa 1:1'}
      </Button></div>
    </Panel> : null}
    <div className="chat-layout">
      <Panel className="chat-list">
        <h2>Conversas</h2>
        {conversations.isLoading ? <LoadingState label="Carregando" /> : null}
        {!conversations.isLoading && (conversations.data ?? []).length === 0 ? <EmptyState title="Nenhuma conversa" description="Comece uma conversa com alguém da equipe." /> : null}
        {(conversations.data ?? []).map((conversation) => <button key={conversation.id} className={`chat-row ${conversation.id === activeId ? 'active' : ''}`} onClick={() => pickConversation(conversation)}>
          <strong>{conversation.type === 'GROUP' ? conversation.title : conversation.participants.map((p) => p.name).join(', ')}</strong>
          <small>{conversation.participants.map((p) => p.name.split(' ')[0]).join(', ')}</small>
          {conversation.unread > 0 ? <b className="chat-badge">{conversation.unread}</b> : null}
        </button>)}
      </Panel>
      <Panel className="chat-thread">
        <h2>{title}</h2>
        {!activeId ? <EmptyState title="Selecione uma conversa" description="Escolha uma conversa à esquerda ou comece uma nova." /> : null}
        {activeId && messages.isLoading ? <LoadingState label="Carregando mensagens" /> : null}
        {messages.hasNextPage ? <Button variant="outline" disabled={messages.isFetchingNextPage} onClick={() => messages.fetchNextPage()}>Mensagens anteriores</Button> : null}
        <div className="chat-messages">{messageRows.map((message) => <div key={message.id} className={`chat-bubble ${message.mine ? 'mine' : ''}`}>
          <small>{message.author.name}{message.patient ? ` · 🩺 ${message.patient.name}` : ''}</small>
          <p>{message.body}</p>
          <time>{message.created_at.slice(11, 16)}</time>
        </div>)}
          <div ref={bottom} />
        </div>
        {active ? <form className="chat-composer" onSubmit={(event) => { event.preventDefault(); send.mutate(); }}>
          {attachedPatient ? <p role="status">Vinculado a: {attachedPatient.name}</p> : null}
          <div className="inline-pair">
            <TextField label="CPF do paciente (opcional)" value={cpf} onChange={(event) => setCpf(event.target.value)} />
            <Button type="button" variant="outline" disabled={cpf.replace(/\D/g, '').length !== 11 || lookup.isPending} onClick={() => lookup.mutate()}>Vincular</Button>
          </div>
          <div className="inline-pair">
            <TextField label="Mensagem" value={draft} minLength={1} maxLength={2000} onChange={(event) => setDraft(event.target.value)} />
            <Button type="submit" disabled={!draft.trim() || send.isPending}>Enviar</Button>
          </div>
        </form> : null}
      </Panel>
    </div>
  </div>;
}
