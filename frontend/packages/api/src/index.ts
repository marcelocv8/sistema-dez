import type { AppointmentStatus, Permission, UUID } from '@dez/domain';
import type { components, paths } from './schema.generated';

export type ProblemDetails = {
  code: string;
  message: string;
  fields?: Record<string, string[]>;
  request_id: string;
};

export type CurrentUser = {
  id: UUID;
  name: string;
  email: string;
  role: 'ADMIN' | 'SUPERVISOR' | 'RECEPTION' | 'CASHIER' | 'MANAGER' | 'DOCTOR';
  roleLabel: string;
  permissions: Permission[];
};

export type PriceAgreement = { id: UUID; legacy_id: string; name: string; is_default: boolean };
export type PriceOptions = {
  agreements: PriceAgreement[];
  groups: Record<'CONSULTATION' | 'EXAM', string[]>;
};
export type TeamPriceItem = {
  id: UUID;
  procedure: { id: UUID; legacy_id: string; tuss_code: string | null; name: string };
  agreement: { id: UUID; legacy_id: string; name: string };
  unit: { id: UUID; legacy_id: string; name: string } | null;
  nature: 'PRIVATE' | 'PLAN';
  amount: string;
};
export type TeamPriceGroup = { group_name: string; items: TeamPriceItem[] };
export type TeamPriceResult = {
  agreement_id: UUID;
  query: string;
  types: Array<{ type: 'CONSULTATION' | 'EXAM'; groups: TeamPriceGroup[] }>;
};

export type CatalogResource = 'units' | 'agreements' | 'procedures' | 'professionals';
export type CatalogItem = {
  id: UUID;
  legacy_id: string | null;
  name: string;
  active: boolean;
  [key: string]: unknown;
};
export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };
export type CatalogList = { data: CatalogItem[]; meta: PageMeta };
export type CatalogOption = { id: UUID; name: string; legacy_id: string | null };
export type CatalogLinkOptions = Record<CatalogResource, CatalogOption[]>;
export type CatalogLink = {
  id: UUID;
  unit: CatalogOption;
  procedure: CatalogOption;
  professional: CatalogOption;
  agreement: CatalogOption;
  active: boolean;
  updated_at: string;
};
export type CatalogLinkInput = {
  unit_id: UUID;
  procedure_id: UUID;
  professional_id: UUID;
  agreement_id: UUID;
  active: boolean;
};
export type ImportIssue = {
  id: UUID;
  code: string;
  entity_type: string;
  legacy_id: string | null;
  source_file: string;
  payload: Record<string, unknown>;
  resolution_status: 'OPEN' | 'RESOLVED' | 'IGNORED' | 'UNMATCHED';
  resolution_note: string | null;
};
export type TeamUser = {
  id: string;
  name: string;
  email: string;
  role: CurrentUser['role'];
  role_label: string;
  active: boolean;
  permissions: Permission[];
};
export type AuditEntry = {
  id: number;
  entity_type: string;
  entity_key: string | null;
  action: string;
  actor_id: string | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  created_at: string;
};

export type Appointment = {
  id: UUID;
  offering_id?: UUID;
  unit?: { id: UUID; name: string };
  agreement?: { id: UUID; name: string; is_default?: boolean };
  cancellation_reason?: string | null;
  scheduled_at: string;
  ends_at: string;
  status: AppointmentStatus;
  kind: string;
  origin: string;
  patient_name: string | null;
  professional: { id: UUID; name: string } | null;
  procedure: { id: UUID; name: string; group_name: string | null };
  price: string;
  payment_status: 'PENDING' | 'PAID' | 'AUTHORIZED';
  paid_at: string | null;
  payment_method_code: string | null;
  receipt_number: number | null;
  care_started_at: string | null;
};

export type QueuePriority = { code: string; label: string };
export type DashboardQueueItem = {
  id: UUID;
  patient_name: string | null;
  procedure_name: string | null;
  checked_in_at: string | null;
  status: string;
};
export type QueueItem = {
  id: UUID;
  appointment_id: UUID;
  ticket: string;
  suggested_position: number;
  original_sequence: number;
  priority_code: string | null;
  priority_label: string | null;
  priority_rank: number | null;
  priority_reason: string | null;
  status: string;
  checked_in_at: string | null;
  called_at: string | null;
  patient_name: string | null;
  payment_status: 'PENDING' | 'PAID' | 'AUTHORIZED' | null;
  payment_method: { code: string; name: string } | null;
  procedure_name: string | null;
  professional_name: string | null;
  unit_name: string | null;
};
export type QueueDay = { date: string; queue: QueueItem[]; priorities: QueuePriority[] };
export type QueueCall = { id: UUID; ticket: string; patient_name: string | null; procedure_name: string | null; professional_name: string | null; called_at: string | null };
export type ScheduleConflictItem = { appointment_id: string; scheduled_at: string; patient_name: string | null; status: string };
export type ScheduleSaveResult<T> = { data: T; conflict_id: string | null; conflicts: ScheduleConflictItem[] };
export type ScheduleConflict = {
  id: UUID;
  service_offering_id: UUID;
  procedure_name: string | null;
  professional_name: string | null;
  reason: string;
  affected: ScheduleConflictItem[];
  affected_count: number;
  status: 'OPEN' | 'RESOLVED';
  created_at: string;
  resolution_note: string | null;
  resolved_at: string | null;
};
export type WindowImportReport = { created: number; validated: number; dry_run: boolean };
export type WindowImportRow = {
  effective_from: string;
  effective_to: string | null;
  weekday: number;
  starts_at: string;
  ends_at: string;
  slot_minutes?: number | null;
  daily_capacity_override?: number | null;
};

export type PaymentMethodCode = 'ESPECIE' | 'DEBITO' | 'CREDITO' | 'PIX';
export type CashRegister = {
  id: UUID;
  unit_id: UUID;
  unit?: { id: UUID; name: string };
  shift: string;
  float_amount: string;
  counted_cash: string | null;
  cash_difference: string | null;
  close_note: string | null;
  status: 'ABERTO' | 'FECHADO';
  opened_at: string;
  closed_at: string | null;
  last_receipt_number: number;
};
export type CashSummaryMethod = { method: string; name: string; count: number; gross: string; fee: string; net: string };
export type CashSummary = { receipt_count: number; by_method: CashSummaryMethod[]; float_amount: string; expected_cash: string; especie_in: string; sangria_total: string; void_total: string };
export type CloseBatchItem = { id: UUID; counted_cash: string; note?: string };
export type CloseBatchResult = {
  closed: Array<{ id: UUID; status: string; expected_cash: string; counted_cash: string; cash_difference: string }>;
  failed: Array<{ id: UUID; code: number; message?: string; errors?: Record<string, string[]> }>;
};
export type SangriaInput = { amount: string; description: string; proof_reference?: string };
export type CashMovement = {
  id: UUID;
  type: 'RECEBIMENTO' | 'SANGRIA' | 'ESTORNO';
  receipt_number: number;
  gross_amount: string;
  fee_amount: string;
  net_amount: string;
  appointment_id: UUID | null;
  proof_reference: string | null;
  payment_method: { id: UUID; code: string; name: string };
};
export type CommissionType = 'PERCENT' | 'FIXED';
export type PayoutStatus = 'OPEN' | 'CONFIRMED' | 'CONTESTED' | 'PAID';
export type ProfessionalCommission = {
  id: UUID;
  professional_id: UUID;
  procedure_id: UUID;
  procedure?: { id: UUID; name: string };
  commission_type: CommissionType;
  commission_value: string;
};
export type PayoutItem = {
  id: UUID;
  appointment_id: UUID;
  gross_amount: string;
  commission_amount: string;
  rule: string;
  appointment?: { id: UUID; scheduled_at: string; price_snapshot: string; payment_status: string; status: string; serviceOffering?: { procedure?: { id: UUID; name: string } } };
};
export type Payout = {
  id: UUID;
  professional_id: UUID;
  professional?: { id: UUID; name: string };
  period_from: string;
  period_to: string;
  status: PayoutStatus;
  subtotal_gross: string;
  total_commission: string;
  contest_note: string | null;
  proof_reference: string | null;
  items?: PayoutItem[];
  items_count?: number;
  payment_method?: { id: UUID; code: string; name: string } | null;
};
export type DailyReport = {
  date: string;
  unit_id: UUID;
  registers: Array<CashRegister & { summary: CashSummary; receipt_gaps: number[] }>;
  totals: { expected_cash: string; counted_cash: string; cash_difference: string; sangria_total: string; void_total: string };
  receipt_gaps: Array<{ cash_register_id: UUID; missing_receipts: number[] }>;
};
export type CashRegisterWithSummary = CashRegister & { summary: CashSummary };
export type CheckoutReceipt = {
  appointment_id: UUID;
  payment_status: 'PAID';
  paid_at: string;
  receipt_number: number;
  cash_register_id: UUID;
  method: { code: string; name: string };
  gross_amount: string;
  fee_amount: string;
  net_amount: string;
};

export type B2bInvoiceStatus = 'EMITIDA' | 'ENVIADA' | 'PAGA';
export type B2bComputedStatus = B2bInvoiceStatus | 'VENCIDA';
export type B2bInvoiceItem = {
  id: UUID;
  appointment_id: UUID;
  scheduled_at: string;
  patient_name: string;
  procedure_name: string;
  gross_amount: string;
};
export type B2bInvoice = {
  id: UUID;
  agreement: { id: UUID; name: string | null };
  competence: string;
  status: B2bInvoiceStatus;
  computed_status: B2bComputedStatus;
  overdue: boolean;
  subtotal_gross: string;
  settled_amount: string;
  remaining_amount: string;
  issued_at: string;
  due_at: string;
  sent_at: string | null;
  settled_at: string | null;
  proof_reference: string | null;
  items_count: number;
  items: B2bInvoiceItem[];
};

export type AttendanceDay = {
  user_id: number;
  user_name: string | null;
  roles: string[];
  date: string;
  logins: string[];
  first_login: string | null;
  last_login: string | null;
  relogins: number;
  relogin_after_exit: boolean;
  exit_hour: string;
};
export type AttendanceReport = { exit_hour: string; days: AttendanceDay[] };
export type AttendanceExport = { filename: string; csv: string };

export type ChatParticipant = { id: number; name: string; role: string | null; role_label?: string | null };
export type ChatConversationSummary = {
  id: UUID;
  type: 'DIRECT' | 'GROUP';
  title: string | null;
  participants: ChatParticipant[];
  last_message_at: string | null;
  unread: number;
};
export type ChatMessageItem = {
  id: UUID;
  author: { id: number; name: string };
  body: string;
  created_at: string;
  patient: { id: UUID; name: string } | null;
  mine: boolean;
};

export type BudgetStatus = 'RASCUNHO' | 'ENVIADO' | 'ACEITO' | 'RECUSADO' | 'PERDIDO';
export type BudgetItemInput = { procedure_id: UUID; amount: string; quantity?: number };
export type BudgetItem = { id: UUID; procedure_id: UUID; procedure_name: string; amount: string; quantity: number };
export type Budget = {
  id: UUID;
  patient: { id: UUID; name: string } | null;
  agreement_id: UUID | null;
  agreement: { id: UUID; name: string } | null;
  status: BudgetStatus;
  subtotal_gross: string;
  has_prescription: boolean;
  sent_at: string | null;
  resolved_at: string | null;
  resolution_note: string | null;
  created_at: string | null;
  items: BudgetItem[];
};
export type BudgetPriceOption = { procedure_id: UUID; procedure_name: string | null; type: string | null; amount: string };

export type RoutineType = 'CONFIRMACAO' | 'ORCAMENTO_RETORNO' | 'ANIVERSARIANTE' | 'FATURA_VENCENDO' | 'MANUAL';
export type RoutineTaskItem = {
  id: UUID;
  type: RoutineType;
  title: string;
  detail: string | null;
  ref_type: string | null;
  ref_id: string | null;
  assigned_to: number | null;
  assignee_name: string | null;
  due_date: string;
  status: 'PENDENTE' | 'FEITO';
  note: string | null;
  done_by: number | null;
  done_by_name: string | null;
  done_at: string | null;
};
export type RoutineDay = { date: string; scope: 'mine' | 'all'; tasks: RoutineTaskItem[] };

export type ReportTemplateItem = {
  id: UUID;
  legacy_type: 'LAUDO' | 'LINHA';
  nome: string;
  doctor: { id: UUID; name: string } | null;
  procedure: { id: UUID; name: string } | null;
  legacy_procedure: string | null;
  preview: string;
  content_html: string | null;
};
export type ReportEventItem = {
  id: UUID;
  event: 'CRIADO' | 'SALVO' | 'FINALIZADO' | 'IMPRESSO' | 'REIMPRESSO' | 'ASSINATURA_MANUAL' | 'ADENDO' | 'RETENCAO_LIMPA';
  by: { id: number; name: string } | null;
  created_at: string | null;
};
export type Report = {
  id: UUID;
  appointment_id: UUID;
  patient_id: UUID;
  patient: { id: UUID; name: string; birth_date: string | null } | null;
  procedure: { id: UUID; name: string } | null;
  doctor: { id: number | UUID; name: string } | null;
  template_id: UUID | null;
  status: 'RASCUNHO' | 'FINALIZADO';
  content_html: string | null;
  adendo: string | null;
  finalized_at: string | null;
  retention_until: string | null;
  signed_manual: boolean;
  signed_manual_at: string | null;
  print_count: number;
  last_printed_at: string | null;
  portal_published?: boolean;
  portal_published_at?: string | null;
  created_at: string | null;
  events: ReportEventItem[];
};

export type ProntuarioNote = {
  id: UUID;
  appointment_id: UUID | null;
  kind: 'ANAMNESE' | 'EVOLUCAO' | 'INTERCORRENCIA' | 'ALTA';
  content_html: string;
  doctor: { id: number; name: string } | null;
  created_by: { id: number; name: string } | null;
  updated_by: { id: number; name: string } | null;
  retention_until: string | null;
  created_at: string | null;
  updated_at: string | null;
};
export type ProntuarioAlert = {
  id: UUID;
  kind: 'ALERGIA' | 'CONDICAO' | 'OUTRO';
  title: string;
  detail: string | null;
  active: boolean;
  created_by: { id: number; name: string } | null;
  deactivated_by: { id: number; name: string } | null;
  deactivated_at: string | null;
  deactivation_reason: string | null;
  created_at: string | null;
};
export type ProntuarioTimeline = {
  patient: { id: UUID; name: string; birth_date: string | null };
  alerts: ProntuarioAlert[];
  notes: ProntuarioNote[];
  reports: {
    id: UUID;
    procedure: { id: UUID; name: string } | null;
    doctor: { id: number; name: string } | null;
    status: 'RASCUNHO' | 'FINALIZADO';
    signed_manual: boolean;
    print_count: number;
    portal_published: boolean;
    finalized_at: string | null;
    created_at: string | null;
  }[];
  appointments: {
    id: UUID;
    scheduled_at: string | null;
    status: string;
    kind: string;
    origin: string;
    procedure: { id: UUID; name: string } | null;
    professional: { id: UUID; name: string } | null;
    unit: { id: UUID; name: string } | null;
    payment_status: string;
    payment_method: { id: number; name: string } | null;
    receipt_number: number | null;
  }[];
};
export type PortalPublication = {
  report_id: UUID;
  portal_published: boolean;
  portal_published_at: string | null;
  login: string | null;
  password: string | null;
  expires_at: string | null;
  portal_url: string;
  folha_html: string;
};
export type PortalResult = {
  id: UUID;
  procedure: { id: UUID; name: string } | null;
  doctor: { id: number; name: string } | null;
  finalized_at: string | null;
  signed_manual: boolean;
  content_html: string | null;
};



export type DashboardSummary = {
  date: string;
  patient_flow: { waiting: number; in_care: number; checkout: number; finished: number };
  queue: DashboardQueueItem[];
  financials_available: boolean;
  revenue: string;
  cash_expected: string;
  cash_difference: string;
  commissions: string;
  b2b_invoices: string;
};

export type BookingMode = 'FIXED_SLOT' | 'ARRIVAL_BLOCK' | 'WALK_IN_INFO';
export type ScheduleWindow = { id: UUID; effective_from: string; effective_to: string | null; weekday: number; starts_at: string; ends_at: string; slot_minutes: number | null; daily_capacity_override: number | null; active: boolean };
export type ScheduleWindowInput = Omit<ScheduleWindow, 'id'>;
export type ScheduleException = { id: UUID; date: string; starts_at: string | null; ends_at: string | null; reason: string; blocked: boolean };
export type ScheduleExceptionInput = Omit<ScheduleException, 'id'>;
export type ServiceOfferingInput = { catalog_link_id: UUID; procedure_price_id: UUID; booking_mode: BookingMode; modality: 'PRESENTIAL' | 'TELECONSULTATION'; duration_override_minutes: number | null; daily_capacity: number | null; arrival_block_minutes: number; arrival_block_capacity: number; active: boolean };
export type ServiceOffering = ServiceOfferingInput & { id: UUID; price: string; online_enabled: boolean; online_paused: boolean; published_at?: string | null; updated_at: string; unit: CatalogOption; procedure: CatalogOption; professional: CatalogOption | null; agreement: CatalogOption; schedule_windows: ScheduleWindow[]; schedule_exceptions: ScheduleException[] };
export type SchedulingOptions = { units: CatalogOption[]; links: CatalogLink[]; prices: Array<{ id: UUID; nature: string; amount: string; valid_from: string | null; valid_until: string | null }> };
export type PatientLookup = { id: UUID; name: string; birth_date: string | null; phone: string | null; email: string | null; cpf_last_four: string };
export type Modality = components['schemas']['Modality'];
export type PublicOffering = {
  id: UUID;
  unit: { id: UUID; name: string };
  professional: { id: UUID; name: string } | null;
  agreement: { id: UUID; name: string };
  modality: Modality;
  booking_mode: BookingMode;
  price: string;
  duration_minutes: number;
};
export type PublicService = {
  id: UUID;
  name: string;
  type: 'CONSULTATION' | 'EXAM';
  group: string | null;
  duration_minutes: number;
  offerings: PublicOffering[];
};
export type AvailabilitySlot = {
  starts_at: string;
  ends_at: string;
  recommended_arrival_at: string | null;
  remaining_capacity: number;
};
export type BlockedPeriod = { starts_at: string; ends_at: string; reason: string };
export type AvailabilityRow = {
  date: string;
  offering_id: UUID;
  booking_mode: BookingMode;
  professional: { id: UUID; name: string } | null;
  operating_window: { starts_at: string; ends_at: string };
  slots: AvailabilitySlot[];
  blocked_periods?: BlockedPeriod[];
};
export type BookAppointmentInput = components['schemas']['BookAppointment'];
export type PublicAppointment = {
  id: UUID;
  status: string;
  scheduled_at: string;
  recommended_arrival_at: string | null;
  booking_mode: BookingMode;
  service: { offering_id: UUID; procedure_id: UUID; procedure_name: string };
  price: string;
  management_token?: string;
  management_expires_at?: string;
  can_cancel: boolean;
  can_reschedule: boolean;
  unit: { id: UUID; name: string };
  professional: { id: UUID; name: string } | null;
  agreement: { id: UUID; name: string };
};

type ApiData<T> = { data: T };

const apiBase = import.meta.env.VITE_API_URL ?? '';

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const method = init?.method?.toUpperCase() ?? 'GET';
  const isForm = typeof FormData !== 'undefined' && init?.body instanceof FormData;
  const xsrfToken = init?.credentials === 'omit' || method === 'GET' || method === 'HEAD' ? undefined : readCookie('XSRF-TOKEN');
  const response = await fetch(`${apiBase}${path}`, {
    ...init,
    credentials: init?.credentials ?? 'include',
    headers: {
      Accept: 'application/json',
      ...(isForm ? {} : { 'Content-Type': 'application/json' }),
      ...(xsrfToken ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrfToken) } : {}),
      ...init?.headers,
    },
  });
  if (!response.ok) {
    const problem = await response.json().catch(() => ({ message: 'Não foi possível concluir a solicitação.' }));
    throw { ...problem, status: response.status } as ProblemDetails & { status: number };
  }
  if (response.status === 204) return undefined as T;
  return response.json() as Promise<T>;
}

function readCookie(name: string): string | undefined {
  return document.cookie.split('; ').find((value) => value.startsWith(`${name}=`))?.split('=').slice(1).join('=');
}

export const api = {
  csrf: () => request<void>('/sanctum/csrf-cookie'),
  login: async (credentials: { email: string; password: string }) => {
    await api.csrf();
    const response = await request<ApiData<{
      id: UUID; name: string; email: string; role: CurrentUser['role']; role_label: string; permissions: Permission[]
    }>>('/api/v1/auth/login', { method: 'POST', body: JSON.stringify(credentials) });
    return { ...response.data, roleLabel: response.data.role_label };
  },
  logout: () => request<void>('/api/v1/auth/logout', { method: 'POST' }),
  currentUser: async () => {
    const response = await request<ApiData<{
      id: UUID; name: string; email: string; role: CurrentUser['role']; role_label: string; permissions: Permission[]
    }>>('/api/v1/me');
    return { ...response.data, roleLabel: response.data.role_label };
  },
  priceOptions: async () => (await request<ApiData<PriceOptions>>('/api/v1/team/price-options')).data,
  prices: async (filters: { q: string; type?: 'CONSULTATION' | 'EXAM'; group?: string; agreement_id?: UUID }) =>
    (await request<ApiData<TeamPriceResult>>(`/api/v1/team/prices?${queryString(filters)}`)).data,
  catalog: (resource: CatalogResource, filters: { q?: string; active?: string; page?: string } = {}) =>
    request<CatalogList>(`/api/v1/team/catalog/${resource}?${queryString(filters)}`),
  saveCatalog: async (resource: CatalogResource, input: Record<string, unknown>, id?: UUID) =>
    (await request<ApiData<CatalogItem>>(`/api/v1/team/catalog/${resource}${id ? `/${id}` : ''}`, {
      method: id ? 'PUT' : 'POST', body: JSON.stringify(input),
    })).data,
  deactivateCatalog: async (resource: CatalogResource, id: UUID) =>
    (await request<ApiData<CatalogItem>>(`/api/v1/team/catalog/${resource}/${id}`, { method: 'DELETE' })).data,
  catalogLinkOptions: async () => (await request<ApiData<CatalogLinkOptions>>('/api/v1/team/catalog-link-options')).data,
  catalogLinks: (filters: { q?: string; active?: string } = {}) => request<{ data: CatalogLink[]; meta: PageMeta }>(`/api/v1/team/catalog-links?${queryString(filters)}`),
  saveCatalogLink: async (input: CatalogLinkInput, id?: UUID) =>
    (await request<ApiData<CatalogLink>>(`/api/v1/team/catalog-links${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) })).data,
  deactivateCatalogLink: async (id: UUID) => (await request<ApiData<CatalogLink>>(`/api/v1/team/catalog-links/${id}`, { method: 'DELETE' })).data,
  importIssues: (status = 'OPEN') => request<{ data: ImportIssue[]; meta: PageMeta & { import_run_id: UUID | null } }>(`/api/v1/team/import-issues?status=${status}`),
  resolveImportIssue: async (id: UUID, input: { resolution_status: ImportIssue['resolution_status']; resolution_note: string | null }) =>
    (await request<ApiData<ImportIssue>>(`/api/v1/team/import-issues/${id}`, { method: 'PUT', body: JSON.stringify(input) })).data,
  teamUsers: () => request<ApiData<TeamUser[]>>('/api/v1/team/users').then((response) => response.data),
  saveTeamUser: async (input: Record<string, unknown>, id?: string) =>
    (await request<ApiData<TeamUser>>(`/api/v1/team/users${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) })).data,
  deactivateTeamUser: async (id: string) => (await request<ApiData<TeamUser>>(`/api/v1/team/users/${id}`, { method: 'DELETE' })).data,
  auditLogs: () => request<{ data: AuditEntry[]; meta: PageMeta }>('/api/v1/team/audit-logs'),
  dashboard: () => request<ApiData<DashboardSummary>>('/api/v1/dashboard'),
  appointments: (date?: string, filters: { professional_id?: string; unit_id?: string; patient_id?: string; status?: string; payment_status?: string; from?: string; to?: string } = {}) => {
    const query = `?${queryString({ date, ...filters })}`;
    return request<ApiData<{ date: string; appointments: Appointment[] }>>(`/api/v1/team/appointments${query}`);
  },
  serviceOfferings: async () => (await request<ApiData<ServiceOffering[]>>('/api/v1/team/service-offerings')).data,
  schedulingOptions: async (catalog_link_id?: string) => (await request<ApiData<SchedulingOptions>>(`/api/v1/team/service-offering-options?${queryString({ catalog_link_id })}`)).data,
  saveServiceOffering: async (input: ServiceOfferingInput, id?: string) => (await request<ApiData<ServiceOffering>>(`/api/v1/team/service-offerings${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) })).data,
  deactivateServiceOffering: (id: string) => request(`/api/v1/team/service-offerings/${id}`, { method: 'DELETE' }),
  setOfferingVisibility: (id: string, paused: boolean) => request<ApiData<ServiceOffering>>(`/api/v1/team/service-offerings/${id}/visibility`, { method: 'PATCH', body: JSON.stringify({ paused }) }),
  saveScheduleWindow: (offeringId: string, input: ScheduleWindowInput, id?: string) => request<ScheduleSaveResult<ScheduleWindow>>(`/api/v1/team/service-offerings/${offeringId}/schedule-windows${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) }),
  deactivateScheduleWindow: (offeringId: string, id: string) => request<ScheduleSaveResult<ScheduleWindow>>(`/api/v1/team/service-offerings/${offeringId}/schedule-windows/${id}`, { method: 'DELETE' }),
  saveScheduleException: (offeringId: string, input: ScheduleExceptionInput, id?: string) => request<ScheduleSaveResult<ScheduleException>>(`/api/v1/team/service-offerings/${offeringId}/schedule-exceptions${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) }),
  removeScheduleException: (offeringId: string, id: string) => request(`/api/v1/team/service-offerings/${offeringId}/schedule-exceptions/${id}`, { method: 'DELETE' }),
  importScheduleWindows: async (offeringId: string, input: { rows: WindowImportRow[]; dry_run?: boolean }) =>
    (await request<ApiData<WindowImportReport>>(`/api/v1/team/service-offerings/${offeringId}/schedule-windows/import`, { method: 'POST', body: JSON.stringify(input) })).data,
  scheduleConflicts: async (status = 'OPEN') => (await request<ApiData<ScheduleConflict[]>>(`/api/v1/team/schedule-conflicts?status=${status}`)).data,
  resolveScheduleConflict: async (id: string, resolution_note: string) =>
    (await request<ApiData<ScheduleConflict>>(`/api/v1/team/schedule-conflicts/${id}/resolve`, { method: 'POST', body: JSON.stringify({ resolution_note }) })).data,
  queue: async (filters: { unit_id?: string; date?: string } = {}) =>
    (await request<ApiData<QueueDay>>(`/api/v1/team/queue?${queryString(filters)}`)).data,
  reorderQueue: async (id: string, input: { priority_code: string | null; priority_reason: string }) =>
    (await request<ApiData<QueueItem>>(`/api/v1/team/queue/${id}/reorder`, { method: 'POST', body: JSON.stringify(input) })).data,
  callQueue: async (id: string) => (await request<ApiData<QueueItem>>(`/api/v1/team/queue/${id}/call`, { method: 'POST' })).data,
  queueHistory: async (filters: { unit_id?: string; date?: string } = {}) =>
    (await request<ApiData<QueueCall[]>>(`/api/v1/team/queue/history?${queryString(filters)}`)).data,
  teamAvailability: async (filters: { offering_id: string; from: string; to: string; exclude_appointment_id?: string }) => (await request<ApiData<AvailabilityRow[]>>(`/api/v1/team/availability?${queryString(filters)}`)).data,
  lookupPatient: async (cpf: string) => (await request<ApiData<PatientLookup | null>>('/api/v1/team/patients/lookup', { method: 'POST', body: JSON.stringify({ cpf }) })).data,
  bookInternal: async (input: BookAppointmentInput) => (await request<ApiData<Appointment>>('/api/v1/team/appointments', { method: 'POST', body: JSON.stringify(input) })).data,
  rescheduleAppointment: async (id: string, input: { offering_id: string; starts_at: string; reason: string }) => (await request<ApiData<Appointment>>(`/api/v1/team/appointments/${id}/reschedule`, { method: 'PATCH', body: JSON.stringify(input) })).data,
  cancelAppointment: async (id: string, reason: string) => (await request<ApiData<Appointment>>(`/api/v1/team/appointments/${id}/cancel`, { method: 'PATCH', body: JSON.stringify({ reason }) })).data,
  checkInAppointment: async (id: string) => (await request<ApiData<{ id: UUID; status: string }>>(`/api/v1/team/appointments/${id}/check-in`, { method: 'POST' })).data,
  startCareAppointment: async (id: string) => (await request<ApiData<{ id: UUID; status: string; care_started_at: string }>>(`/api/v1/team/appointments/${id}/start-care`, { method: 'POST' })).data,
  authorizeB2BAppointment: async (id: string, authorization_code: string) => (await request<ApiData<{ id: UUID; payment_status: string }>>(`/api/v1/team/appointments/${id}/authorize-b2b`, { method: 'POST', body: JSON.stringify({ authorization_code }) })).data,
  checkoutAppointment: async (id: string, payment_method_code: string) => (await request<ApiData<CheckoutReceipt>>(`/api/v1/team/appointments/${id}/checkout`, { method: 'POST', body: JSON.stringify({ payment_method_code }) })).data,
  cashRegisters: async (unit_id?: string, filters: { status?: 'ABERTO' | 'FECHADO'; from?: string; to?: string } = {}) => (await request<ApiData<CashRegister[]>>(`/api/v1/team/cash-registers?${queryString({ unit_id, ...filters })}`)).data,
  openCashRegister: async (input: { unit_id: UUID; shift: string; float_amount: string }) => (await request<ApiData<CashRegister>>('/api/v1/team/cash-registers', { method: 'POST', body: JSON.stringify(input) })).data,
  currentCashRegister: async (unit_id?: string) => (await request<{ data: CashRegisterWithSummary | null }>(`/api/v1/team/cash-registers/current?${queryString({ unit_id })}`)).data,
  cashDailyReport: async (unit_id: string, date: string) => (await request<ApiData<DailyReport>>(`/api/v1/team/cash-registers/daily-report?${queryString({ unit_id, date })}`)).data,
  closeCashRegister: async (id: string, input: { counted_cash: string; note?: string }) => (await request<ApiData<CashRegister>>(`/api/v1/team/cash-registers/${id}/close`, { method: 'POST', body: JSON.stringify(input) })).data,
  closeCashBatch: async (items: CloseBatchItem[]) => (await request<ApiData<CloseBatchResult>>('/api/v1/team/cash-registers/close-batch', { method: 'POST', body: JSON.stringify({ items }) })).data,
  createSangria: async (id: string, input: SangriaInput) => (await request<ApiData<CashMovement>>(`/api/v1/team/cash-registers/${id}/sangrias`, { method: 'POST', body: JSON.stringify(input) })).data,
  voidAppointmentPayment: async (id: string, reason: string) => (await request<ApiData<CashMovement>>(`/api/v1/team/appointments/${id}/void-payment`, { method: 'POST', body: JSON.stringify({ reason }) })).data,
  b2bInvoices: async (filters: { agreement_id?: string; status?: string; competence?: string } = {}) =>
    (await request<ApiData<B2bInvoice[]>>(`/api/v1/team/b2b-invoices?${queryString(filters)}`)).data,
  b2bInvoice: async (id: string) => (await request<ApiData<B2bInvoice>>(`/api/v1/team/b2b-invoices/${id}`)).data,
  generateB2bInvoice: async (input: { agreement_id: string; competence: string }) =>
    (await request<ApiData<B2bInvoice>>('/api/v1/team/b2b-invoices/generate', { method: 'POST', body: JSON.stringify(input) })).data,
  sendB2bInvoice: async (id: string) => (await request<ApiData<B2bInvoice>>(`/api/v1/team/b2b-invoices/${id}/send`, { method: 'POST' })).data,
  settleB2bInvoice: async (id: string, input: { amount: string; proof_reference: string }) =>
    (await request<ApiData<B2bInvoice>>(`/api/v1/team/b2b-invoices/${id}/settle`, { method: 'POST', body: JSON.stringify(input) })).data,
  attendanceReport: async (filters: { from: string; to: string; user_id?: string }) =>
    (await request<ApiData<AttendanceReport>>(`/api/v1/team/attendance?${queryString(filters)}`)).data,
  attendanceExport: async (filters: { from: string; to: string }) =>
    (await request<ApiData<AttendanceExport>>(`/api/v1/team/attendance/export?${queryString(filters)}`)).data,
  attendanceExitHour: async () => (await request<ApiData<{ exit_hour: string }>>('/api/v1/team/attendance/exit-hour')).data,
  saveAttendanceExitHour: async (exit_hour: string) =>
    (await request<ApiData<{ exit_hour: string }>>('/api/v1/team/attendance/exit-hour', { method: 'PUT', body: JSON.stringify({ exit_hour }) })).data,
  chatConversations: async () => (await request<ApiData<ChatConversationSummary[]>>('/api/v1/team/chat/conversations')).data,
  createChatConversation: async (input: { type: 'DIRECT' | 'GROUP'; title?: string; participant_ids: number[] }) =>
    (await request<ApiData<{ id: UUID; type: string; title: string | null }>>('/api/v1/team/chat/conversations', { method: 'POST', body: JSON.stringify(input) })).data,
  chatMessages: async (id: string, after?: string) =>
    (await request<ApiData<ChatMessageItem[]>>(`/api/v1/team/chat/conversations/${id}/messages${after ? `?after=${encodeURIComponent(after)}` : ''}`)).data,
  sendChatMessage: async (id: string, input: { body: string; patient_id?: string; appointment_id?: string }) =>
    (await request<ApiData<ChatMessageItem>>(`/api/v1/team/chat/conversations/${id}/messages`, { method: 'POST', body: JSON.stringify(input) })).data,
  markChatRead: async (id: string) => (await request<ApiData<{ ok: boolean }>>(`/api/v1/team/chat/conversations/${id}/read`, { method: 'POST' })).data,
  chatUnread: async () => (await request<ApiData<{ unread: number }>>('/api/v1/team/chat/unread')).data,
  chatParticipants: async () => (await request<ApiData<ChatParticipant[]>>('/api/v1/team/chat/participants')).data,
  chatLookupPatient: async (cpf: string) =>
    (await request<ApiData<{ id: UUID; name: string } | null>>(`/api/v1/team/chat/patients/lookup?cpf=${cpf}`)).data,
  budgets: async (filters: { status?: string; patient_id?: string } = {}) =>
    (await request<ApiData<Budget[]>>(`/api/v1/team/budgets?${queryString(filters)}`)).data,
  budgetPriceOptions: async (agreement_id: string, q?: string) =>
    (await request<ApiData<BudgetPriceOption[]>>(`/api/v1/team/budgets/price-options?${queryString({ agreement_id, q })}`)).data,
  createBudget: async (input: { patient_id: string; agreement_id?: string; items: BudgetItemInput[]; prescription?: File }) => {
    const form = new FormData();
    form.set('patient_id', input.patient_id);
    if (input.agreement_id) form.set('agreement_id', input.agreement_id);
    input.items.forEach((item, index) => {
      form.set(`items[${index}][procedure_id]`, item.procedure_id);
      form.set(`items[${index}][amount]`, item.amount);
      if (item.quantity) form.set(`items[${index}][quantity]`, String(item.quantity));
    });
    if (input.prescription) form.set('prescription', input.prescription);
    return (await request<ApiData<Budget>>('/api/v1/team/budgets', { method: 'POST', body: form })).data;
  },
  sendBudget: async (id: string) => (await request<ApiData<Budget>>(`/api/v1/team/budgets/${id}/send`, { method: 'POST' })).data,
  resolveBudget: async (id: string, input: { outcome: 'ACEITO' | 'RECUSADO' | 'PERDIDO'; note: string }) =>
    (await request<ApiData<Budget>>(`/api/v1/team/budgets/${id}/resolve`, { method: 'POST', body: JSON.stringify(input) })).data,
  budgetPrescriptionUrl: (id: string) => `${apiBase}/api/v1/team/budgets/${id}/prescription`,
  routines: async (filters: { date?: string; scope?: 'mine' | 'all' } = {}) =>
    (await request<ApiData<RoutineDay>>(`/api/v1/team/routines?${queryString(filters)}`)).data,
  generateRoutines: async (date?: string) =>
    (await request<ApiData<{ created: number; date: string }>>('/api/v1/team/routines/generate', { method: 'POST', body: JSON.stringify(date ? { date } : {}) })).data,
  tickRoutine: async (id: string, note?: string) =>
    (await request<ApiData<RoutineTaskItem>>(`/api/v1/team/routines/${id}/tick`, { method: 'POST', body: JSON.stringify({ note }) })).data,
  reopenRoutine: async (id: string, note: string) =>
    (await request<ApiData<RoutineTaskItem>>(`/api/v1/team/routines/${id}/reopen`, { method: 'POST', body: JSON.stringify({ note }) })).data,
  reportTemplates: async (filters: { q?: string; procedure_id?: string; doctor_id?: string; legacy_type?: 'LAUDO' | 'LINHA' } = {}) =>
    (await request<ApiData<ReportTemplateItem[]>>(`/api/v1/team/reports/templates?${queryString(filters)}`)).data,
  reports: async (filters: { appointment_id?: string; patient_id?: string; status?: string } = {}) =>
    (await request<ApiData<Report[]>>(`/api/v1/team/reports?${queryString(filters)}`)).data,
  reportHistory: async (patient_id: string) =>
    (await request<ApiData<Report[]>>(`/api/v1/team/reports/history?${queryString({ patient_id })}`)).data,
  createReport: async (input: { appointment_id: string; template_id: string }) =>
    (await request<ApiData<Report>>('/api/v1/team/reports', { method: 'POST', body: JSON.stringify(input) })).data,
  saveReport: async (id: string, content_html: string) =>
    (await request<ApiData<Report>>(`/api/v1/team/reports/${id}`, { method: 'PUT', body: JSON.stringify({ content_html }) })).data,
  finalizeReport: async (id: string) =>
    (await request<ApiData<Report>>(`/api/v1/team/reports/${id}/finalize`, { method: 'POST' })).data,
  appendReport: async (id: string, adendo: string) =>
    (await request<ApiData<Report>>(`/api/v1/team/reports/${id}/append`, { method: 'POST', body: JSON.stringify({ adendo }) })).data,
  signReportManual: async (id: string) =>
    (await request<ApiData<Report>>(`/api/v1/team/reports/${id}/sign-manual`, { method: 'POST' })).data,
  printReport: async (id: string) =>
    (await request<{ data: { print_html: string; print_count: number } }>(`/api/v1/team/reports/${id}/print`, { method: 'POST' })).data,
  publishReportPortal: async (id: string, force_new_credential = false) =>
    (await request<ApiData<PortalPublication>>(`/api/v1/team/reports/${id}/portal`, { method: 'POST', body: JSON.stringify(force_new_credential ? { force_new_credential: true } : {}) })).data,
  reportPortalFolha: async (id: string) =>
    (await request<ApiData<PortalPublication>>(`/api/v1/team/reports/${id}/portal/folha`)).data,
  revokeReportPortal: async (id: string, reason: string) =>
    (await request<ApiData<{ id: UUID; portal_published: boolean }>>(`/api/v1/team/reports/${id}/portal/revoke`, { method: 'POST', body: JSON.stringify({ reason }) })).data,
  prontuarioTimeline: async (patient_id: string) =>
    (await request<ApiData<ProntuarioTimeline>>(`/api/v1/team/prontuario?${queryString({ patient_id })}`)).data,
  createProntuarioNote: async (input: { appointment_id: string; kind?: string; content_html: string }) =>
    (await request<ApiData<ProntuarioNote>>('/api/v1/team/prontuario/notes', { method: 'POST', body: JSON.stringify(input) })).data,
  updateProntuarioNote: async (id: string, content_html: string) =>
    (await request<ApiData<ProntuarioNote>>(`/api/v1/team/prontuario/notes/${id}`, { method: 'PUT', body: JSON.stringify({ content_html }) })).data,
  addProntuarioAlert: async (input: { patient_id: string; kind?: string; title: string; detail?: string }) =>
    (await request<ApiData<ProntuarioAlert>>('/api/v1/team/prontuario/alerts', { method: 'POST', body: JSON.stringify(input) })).data,
  deactivateProntuarioAlert: async (id: string, reason: string) =>
    (await request<ApiData<ProntuarioAlert>>(`/api/v1/team/prontuario/alerts/${id}/deactivate`, { method: 'POST', body: JSON.stringify({ reason }) })).data,
  aiStatus: async () => (await request<ApiData<{ enabled: boolean; model: string }>>('/api/v1/team/prontuario/ai/status')).data,
  aiSuggest: async (input: { patient_id: string; appointment_id?: string; question: string }) =>
    (await request<ApiData<{ ai_request_id: UUID; model: string; suggestion_html: string }>>('/api/v1/team/prontuario/ai/suggest', { method: 'POST', body: JSON.stringify(input) })).data,
  portalLogin: async (input: { login: string; password: string }) =>
    (await request<ApiData<{ token: string; expires_at: string; patient_name: string | null }>>('/api/v1/public/portal/login', { method: 'POST', body: JSON.stringify(input), credentials: 'omit' })).data,
  portalResults: async (token: string) =>
    (await request<ApiData<{ patient_name: string | null; expires_at: string; results: PortalResult[] }>>('/api/v1/public/portal/results', { headers: { Authorization: `Bearer ${token}` }, credentials: 'omit' })).data,
  payouts: async (filters: { professional_id?: string; status?: PayoutStatus; from?: string; to?: string } = {}) =>
    (await request<ApiData<Payout[]>>(`/api/v1/team/payouts?${queryString(filters)}`)).data,
  payout: async (id: string) => (await request<ApiData<Payout>>(`/api/v1/team/payouts/${id}`)).data,
  generatePayout: async (input: { professional_id: string; from: string; to: string }) =>
    (await request<ApiData<Payout>>('/api/v1/team/payouts/generate', { method: 'POST', body: JSON.stringify(input) })).data,
  confirmPayout: async (id: string) => (await request<ApiData<Payout>>(`/api/v1/team/payouts/${id}/confirm`, { method: 'POST' })).data,
  contestPayout: async (id: string, note: string) => (await request<ApiData<Payout>>(`/api/v1/team/payouts/${id}/contest`, { method: 'POST', body: JSON.stringify({ note }) })).data,
  adjustPayoutItem: async (itemId: string, commission_amount: string) =>
    (await request<ApiData<PayoutItem>>(`/api/v1/team/payout-items/${itemId}/commission`, { method: 'PATCH', body: JSON.stringify({ commission_amount }) })).data,
  payPayout: async (id: string, input: { payment_method_code: 'ESPECIE' | 'PIX'; cash_register_id?: string; proof_reference?: string }) =>
    (await request<ApiData<Payout>>(`/api/v1/team/payouts/${id}/pay`, { method: 'POST', body: JSON.stringify(input) })).data,
  myProfessional: async () =>
    (await request<ApiData<{ id: UUID; name: string; commission_type: CommissionType; commission_value: string }>>('/api/v1/team/professionals/mine')).data,
  professionalCommissions: async (professionalId: string) =>
    (await request<ApiData<ProfessionalCommission[]>>(`/api/v1/team/professionals/${professionalId}/commissions`)).data,
  saveProfessionalCommission: async (professionalId: string, input: { procedure_id: string; commission_type: CommissionType; commission_value: string }) =>
    (await request<ApiData<ProfessionalCommission>>(`/api/v1/team/professionals/${professionalId}/commissions`, { method: 'POST', body: JSON.stringify(input) })).data,
  deleteProfessionalCommission: async (professionalId: string, commissionId: string) =>
    (await request(`/api/v1/team/professionals/${professionalId}/commissions/${commissionId}`, { method: 'DELETE' })),
  callPatient: (id: UUID) => request<{ ok: true }>(`/api/v1/queue/${id}/call`, { method: 'POST' }),
  publishReport: (id: UUID) => request<{ publishedAt: string }>(`/api/v1/reports/${id}/publish`, { method: 'POST' }),
};

export function apiErrorMessage(error: unknown): string {
  const problem = error as { message?: string; errors?: Record<string, string[]>; fields?: Record<string, string[]> };
  const fields = problem?.errors ?? problem?.fields;
  return fields ? Object.values(fields).flat().join(' ') : problem?.message ?? 'Não foi possível concluir. Tente novamente.';
}

function queryString(values: Record<string, string | undefined>): string {
  const params = new URLSearchParams();
  Object.entries(values).forEach(([key, value]) => value !== undefined && params.set(key, value));
  return params.toString();
}

export const publicApi = {
  services: async (filters: NonNullable<paths['/public/services']['get']['parameters']['query']> = {}) =>
    (await request<ApiData<PublicService[]>>(`/api/v1/public/services?${queryString(filters)}`, { credentials: 'omit' })).data,
  availability: async (filters: paths['/public/availability']['get']['parameters']['query'] & { offering_id?: string }) =>
    (await request<ApiData<AvailabilityRow[]>>(`/api/v1/public/availability?${queryString(filters)}`, { credentials: 'omit' })).data,
  book: async (input: BookAppointmentInput) =>
    (await request<ApiData<PublicAppointment>>('/api/v1/public/appointments', { credentials: 'omit', method: 'POST', body: JSON.stringify(input) })).data,
  appointment: async (id: string, token: string) => (await request<ApiData<PublicAppointment>>(`/api/v1/public/appointments/${id}`, { credentials: 'omit', headers: { Authorization: `Bearer ${token}` } })).data,
  managementAvailability: async (id: string, token: string) => (await request<ApiData<AvailabilityRow[]>>(`/api/v1/public/appointments/${id}/availability`, { credentials: 'omit', headers: { Authorization: `Bearer ${token}` } })).data,
  reschedule: async (id: string, token: string, starts_at: string, reason: string) => (await request<ApiData<PublicAppointment>>(`/api/v1/public/appointments/${id}/reschedule`, { credentials: 'omit', method: 'PATCH', headers: { Authorization: `Bearer ${token}` }, body: JSON.stringify({ starts_at, reason }) })).data,
  cancel: async (id: string, token: string, reason: string) => (await request<ApiData<PublicAppointment>>(`/api/v1/public/appointments/${id}/cancel`, { credentials: 'omit', method: 'PATCH', headers: { Authorization: `Bearer ${token}` }, body: JSON.stringify({ reason }) })).data,
};

export async function enableMocking(): Promise<void> {
  if (import.meta.env.DEV && import.meta.env.VITE_USE_MSW === 'true') {
    const { worker } = await import('./mocks/browser');
    await worker.start({ onUnhandledRequest: 'bypass', serviceWorker: { url: '/mockServiceWorker.js' } });
  }
}
