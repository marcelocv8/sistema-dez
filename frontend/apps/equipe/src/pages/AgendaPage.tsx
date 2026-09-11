import { useMemo, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { api, type Appointment, type CurrentUser } from '@dez/api';
import { formatClinicDate } from '@dez/domain';
import { Button, EmptyState, IconButton, LoadingState, Panel, StatusPill, SelectField } from '@dez/ui';
import { AppointmentEditor } from './AppointmentEditor';

const statusLabel: Record<Appointment['status'], { label: string; tone: 'success' | 'warning' | 'purple' | 'danger' }> = {
  CONFIRMED: { label: 'Confirmado', tone: 'success' },
  CHECKED_IN: { label: 'Check-in realizado', tone: 'purple' },
  DONE: { label: 'Concluído', tone: 'warning' },
  CANCELLED: { label: 'Cancelado', tone: 'danger' },
};
const allStatuses = Object.entries(statusLabel) as [Appointment['status'], { label: string; tone: 'success' | 'warning' | 'purple' | 'danger' }][];
const monthTitleFormatter = new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric' });
const weekDayFormatter = new Intl.DateTimeFormat('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' });

function todayIso(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function shiftDate(iso: string, days: number): string {
  const date = new Date(`${iso}T12:00:00`);
  date.setDate(date.getDate() + days);
  return date.toISOString().slice(0, 10);
}

function shiftMonth(iso: string, months: number): string {
  const base = new Date(`${iso}T12:00:00`);
  return new Date(base.getFullYear(), base.getMonth() + months, 1, 12).toISOString().slice(0, 10);
}

function weekRange(iso: string): { from: string; to: string; days: string[] } {
  const base = new Date(`${iso}T12:00:00`);
  const monday = new Date(base);
  monday.setDate(base.getDate() - ((base.getDay() + 6) % 7));
  const days = Array.from({ length: 7 }, (_, index) => {
    const day = new Date(monday);
    day.setDate(monday.getDate() + index);
    return day.toISOString().slice(0, 10);
  });
  return { from: days[0], to: days[6], days };
}

function monthRange(iso: string): { from: string; to: string } {
  const base = new Date(`${iso}T12:00:00`);
  const from = `${base.getFullYear()}-${String(base.getMonth() + 1).padStart(2, '0')}-01`;
  const last = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate();
  return { from, to: `${base.getFullYear()}-${String(base.getMonth() + 1).padStart(2, '0')}-${String(last).padStart(2, '0')}` };
}

function monthCells(iso: string): Array<{ day: number; iso: string; outside: boolean }> {
  const base = new Date(`${iso}T12:00:00`);
  const year = base.getFullYear();
  const month = base.getMonth();
  const firstWeekday = (new Date(year, month, 1).getDay() + 6) % 7;
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const cells: Array<number | null> = Array.from({ length: firstWeekday }, () => null);
  cells.push(...Array.from({ length: daysInMonth }, (_, index) => index + 1));
  while (cells.length % 7 !== 0) cells.push(null);
  return cells.map((day) => day === null
    ? { day: 0, iso: '', outside: true }
    : { day, iso: `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`, outside: false });
}

export default function AgendaPage({ user }: { user: CurrentUser }) {
  const queryClient = useQueryClient();
  const [date, setDate] = useState(todayIso);
  const [view, setView] = useState<'Dia' | 'Semana' | 'Mês'>('Dia');
  const [hidden, setHidden] = useState<ReadonlySet<Appointment['status']>>(new Set());
  const [professionalId, setProfessionalId] = useState('');
  const [editor, setEditor] = useState<Appointment | 'new' | null>(null);
  const offerings = useQuery({ queryKey: ['service-offerings'], queryFn: api.serviceOfferings });
  const range = view === 'Dia' ? { from: date, to: date } : view === 'Semana' ? weekRange(date) : monthRange(date);
  const { data, isLoading, isError } = useQuery({ queryKey: ['appointments', view, range.from, range.to, professionalId], queryFn: () => api.appointments(date, { professional_id: professionalId || undefined, from: range.from, to: range.to }) });
  const professionals = [...new Map((offerings.data ?? []).flatMap((item) => item.professional ? [[item.professional.id, item.professional] as const] : [])).values()];
  const canManage = user.permissions.includes('appointments.manage');
  // O modal edita sempre a cópia mais recente da lista: após check-in,
  // cobrança ou remarcação, a remontagem troca o estado sem fechar a tela.
  const editorAppointment = !editor || editor === 'new' ? editor
    : (data?.data.appointments.find((item) => item.id === editor.id) ?? editor);
  const editorKey = editorAppointment === 'new' || !editorAppointment ? 'new'
    : `${editorAppointment.id}-${editorAppointment.status}-${editorAppointment.payment_status}-${editorAppointment.care_started_at ?? ''}`;

  const appointments = useMemo(() => (data?.data.appointments ?? []).filter((item) => !hidden.has(item.status)), [data, hidden]);
  const byDay = useMemo(() => {
    const map = new Map<string, Appointment[]>();
    appointments.forEach((item) => {
      const day = item.scheduled_at.slice(0, 10);
      map.set(day, [...(map.get(day) ?? []), item]);
    });
    return map;
  }, [appointments]);
  const slots = useMemo(() => [...new Set(appointments.map((item) => item.scheduled_at.slice(11, 16)))].sort(), [appointments]);
  const byDoctor = useMemo(() => {
    const map = new Map<string, Appointment[]>();
    appointments.forEach((item) => {
      const name = item.professional?.name ?? 'Sem profissional';
      map.set(name, [...(map.get(name) ?? []), item]);
    });
    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b, 'pt-BR'));
  }, [appointments]);
  const slotHeights = new Map(slots.map((slot) => [slot, Math.max(1, ...byDoctor.map(([, items]) => items.filter((item) => item.scheduled_at.slice(11, 16) === slot).length)) * 70]));

  if (isLoading) return <LoadingState label="Carregando agenda" />;

  return <div className="page agenda-page">
    <div className="page-heading"><div><h1>Agenda</h1><p>{formatClinicDate(`${date}T12:00:00`, { weekday: 'long' })} · Piloto interno</p></div>{canManage ? <Button onClick={() => setEditor('new')}>Novo agendamento</Button> : null}</div>
    {editor && canManage ? <AppointmentEditor key={editorKey} appointment={editorAppointment === 'new' || !editorAppointment ? undefined : editorAppointment} initialDate={date} offerings={offerings.data ?? []} onClose={() => setEditor(null)} onSaved={async () => { await Promise.all([queryClient.invalidateQueries({ queryKey: ['appointments'] }), queryClient.invalidateQueries({ queryKey: ['team-availability'] })]); }} /> : null}
    <div className="agenda-layout">
      <aside>
        <Panel className="mini-calendar">
          <div className="calendar-header"><b>{monthTitleFormatter.format(new Date(`${date}T12:00:00`))}</b>
            <span>
              <IconButton label="Mês anterior" onClick={() => setDate((current) => { const next = new Date(`${current.slice(0, 7)}-01T12:00:00`); next.setMonth(next.getMonth() - 1); return next.toISOString().slice(0, 10); })}><ChevronLeft size={16} /></IconButton>
              <IconButton label="Próximo mês" onClick={() => setDate((current) => { const next = new Date(`${current.slice(0, 7)}-01T12:00:00`); next.setMonth(next.getMonth() + 1); return next.toISOString().slice(0, 10); })}><ChevronRight size={16} /></IconButton>
            </span>
          </div>
          <div className="calendar-week"><b>SEG</b><b>TER</b><b>QUA</b><b>QUI</b><b>SEX</b><b>SÁB</b><b>DOM</b></div>
          <div className="calendar-week">{monthCells(date).map((cell, index) => cell.outside
            ? <span key={index} />
            : <button key={cell.iso} className={cell.iso === date ? 'selected' : ''} onClick={() => setDate(cell.iso)}>{cell.day}</button>)}</div>
        </Panel>
        <Panel className="agenda-filters"><h2>Status</h2>
          <SelectField label="Profissional da agenda" value={professionalId} onChange={(event) => setProfessionalId(event.target.value)}><option value="">Todos</option>{professionals.map((professional) => <option key={professional.id} value={professional.id}>{professional.name}</option>)}</SelectField>
          <div className="filter-status">{allStatuses.map(([status, meta]) => <label key={status}>
            <input type="checkbox" checked={!hidden.has(status)} onChange={(event) => setHidden((current) => {
              const next = new Set(current);
              if (event.target.checked) next.delete(status); else next.add(status);
              return next;
            })} />
            <StatusPill tone={meta.tone}>{meta.label}</StatusPill>
          </label>)}</div>
        </Panel>
      </aside>
      <Panel className="agenda-board">
        <header className="agenda-toolbar">
          <div className="segmented">{(['Dia', 'Semana', 'Mês'] as const).map((item) => <button key={item} className={view === item ? 'active' : ''} onClick={() => setView(item)}>{item}</button>)}</div>
          <div className="date-controls">
            <Button variant="outline" onClick={() => setDate(todayIso())}>Hoje</Button>
            <IconButton label={view === 'Dia' ? 'Dia anterior' : view === 'Semana' ? 'Semana anterior' : 'Mês anterior'} onClick={() => setDate((current) => view === 'Dia' ? shiftDate(current, -1) : view === 'Semana' ? shiftDate(current, -7) : shiftMonth(current, -1))}><ChevronLeft size={18} /></IconButton>
            <IconButton label={view === 'Dia' ? 'Próximo dia' : view === 'Semana' ? 'Próxima semana' : 'Próximo mês'} onClick={() => setDate((current) => view === 'Dia' ? shiftDate(current, 1) : view === 'Semana' ? shiftDate(current, 7) : shiftMonth(current, 1))}><ChevronRight size={18} /></IconButton>
          </div>
        </header>
        {isError ? <EmptyState title="Não foi possível carregar a agenda" description="Tente novamente em instantes." /> : null}
        {view !== 'Dia' ? <AgendaRangeView view={view} date={date} byDay={byDay} onPickDay={(day) => { setDate(day); setView('Dia'); }} /> : null}
        {view === 'Dia' && !isError && appointments.length === 0 ? <EmptyState title="Nenhum agendamento para este dia" description="Os agendamentos aparecem aqui conforme são confirmados." /> : null}
        {view === 'Dia' && byDoctor.length > 0 ? <div className="agenda-grid" style={{ gridTemplateColumns: `56px repeat(${byDoctor.length},minmax(180px,1fr))`, minWidth: 56 + byDoctor.length * 180 }}>
          <div className="time-column"><span className="doctor-spacer" />{slots.map((slot) => <time key={slot} style={{ height: slotHeights.get(slot) }}>{slot}</time>)}</div>
          {byDoctor.map(([doctor, items]) => <div className="doctor-column" key={doctor}>
            <div className="doctor-head"><span className={`doctor-avatar avatar-${doctor.length % 4}`}>{doctor.split(' ').slice(-1)[0][0]}</span><b>{doctor}</b></div>
            <div className="doctor-slots">{slots.map((slot) => <div className="slot" key={slot} style={{ height: slotHeights.get(slot) }}>
              {items.filter((item) => item.scheduled_at.slice(11, 16) === slot).map((item) => <AppointmentCard appointment={item} key={item.id} onClick={() => canManage && setEditor(item)} />)}
            </div>)}</div>
          </div>)}
        </div> : null}
      </Panel>
    </div>
  </div>;
}

function AgendaRangeView({ view, date, byDay, onPickDay }: { view: 'Semana' | 'Mês'; date: string; byDay: Map<string, Appointment[]>; onPickDay: (day: string) => void }) {
  if (view === 'Semana') {
    const { days } = weekRange(date);
    return <div className="agenda-week">{days.map((day) => {
      const items = byDay.get(day) ?? [];
      return <button key={day} className="agenda-day" onClick={() => onPickDay(day)}>
        <b>{weekDayFormatter.format(new Date(`${day}T12:00:00`))}</b>
        <span>{items.length === 0 ? 'Sem fichas' : `${items.length} ficha(s)`}</span>
        {items.slice(0, 4).map((item) => <small key={item.id}>{item.scheduled_at.slice(11, 16)} · {item.patient_name ?? item.procedure.name}</small>)}
      </button>;
    })}</div>;
  }
  return <div className="agenda-month">
    <div className="calendar-week"><b>SEG</b><b>TER</b><b>QUA</b><b>QUI</b><b>SEX</b><b>SÁB</b><b>DOM</b></div>
    <div className="calendar-week">{monthCells(date).map((cell, index) => {
      if (cell.outside) return <span key={index} />;
      const count = (byDay.get(cell.iso) ?? []).length;
      return <button key={cell.iso} className={cell.iso === date ? 'selected' : ''} onClick={() => onPickDay(cell.iso)}>{cell.day}{count > 0 ? <i>{count}</i> : null}</button>;
    })}</div>
  </div>;
}

function AppointmentCard({ appointment, onClick }: { appointment: Appointment; onClick: () => void }) {
  const meta = statusLabel[appointment.status] ?? statusLabel.CONFIRMED;
  const payment = appointment.payment_status === 'PAID'
    ? { label: `Pago${appointment.receipt_number ? ` · recibo ${appointment.receipt_number}` : ''}`, tone: 'success' as const }
    : appointment.payment_status === 'AUTHORIZED'
      ? { label: 'Autorizado B2B', tone: 'info' as const }
      : { label: 'Aguardando pagamento', tone: 'warning' as const };
  const confirmation = confirmationLabel(appointment.confirmation_status ?? null);
  return <button onClick={onClick} className={`appointment appointment--${meta.tone}`} title={`${appointment.procedure.name}${appointment.patient_name ? ` · ${appointment.patient_name}` : ''}`}>
    <time>{appointment.scheduled_at.slice(11, 16)} – {appointment.ends_at.slice(11, 16)}</time>
    <strong>{appointment.patient_name ?? appointment.procedure.name}</strong>
    <span>{appointment.procedure.name}</span>
    <StatusPill tone={payment.tone}>{payment.label}</StatusPill>
    {confirmation ? <StatusPill tone={confirmation.tone}>{confirmation.label}</StatusPill> : null}
    {appointment.care_started_at ? <span>Em atendimento</span> : null}
  </button>;
}

const CONFIRMATION_META: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' }> = {
  PENDING: { label: 'WhatsApp: aguardando', tone: 'warning' },
  CONFIRMED: { label: 'WhatsApp: confirmado', tone: 'success' },
  DECLINED: { label: 'WhatsApp: não vai', tone: 'danger' },
  NO_RESPONSE: { label: 'WhatsApp: sem resposta', tone: 'danger' },
};

function confirmationLabel(status: Appointment['confirmation_status']) {
  if (!status) return null;
  return CONFIRMATION_META[status] ?? null;
}
