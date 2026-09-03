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
  role: 'ADMIN' | 'SUPERVISOR' | 'RECEPTION';
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
  agreement?: { id: UUID; name: string };
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
};

export type QueueItem = {
  id: UUID;
  patient_name: string | null;
  procedure_name: string | null;
  checked_in_at: string | null;
  status: string;
};

export type DashboardSummary = {
  date: string;
  patient_flow: { waiting: number; in_care: number; checkout: number; finished: number };
  queue: QueueItem[];
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
export type ServiceOffering = ServiceOfferingInput & { id: UUID; price: string; online_enabled: boolean; published_until?: string | null; published_at?: string | null; unit: CatalogOption; procedure: CatalogOption; professional: CatalogOption | null; agreement: CatalogOption; schedule_windows: ScheduleWindow[]; schedule_exceptions: ScheduleException[] };
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
export type AvailabilityRow = {
  date: string;
  offering_id: UUID;
  booking_mode: BookingMode;
  professional: { id: UUID; name: string } | null;
  operating_window: { starts_at: string; ends_at: string };
  slots: AvailabilitySlot[];
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
  const xsrfToken = init?.credentials === 'omit' || method === 'GET' || method === 'HEAD' ? undefined : readCookie('XSRF-TOKEN');
  const response = await fetch(`${apiBase}${path}`, {
    ...init,
    credentials: init?.credentials ?? 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
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
  appointments: (date?: string, filters: { professional_id?: string; unit_id?: string; status?: string; from?: string; to?: string } = {}) => {
    const query = `?${queryString({ date, ...filters })}`;
    return request<ApiData<{ date: string; appointments: Appointment[] }>>(`/api/v1/team/appointments${query}`);
  },
  serviceOfferings: async () => (await request<ApiData<ServiceOffering[]>>('/api/v1/team/service-offerings')).data,
  schedulingOptions: async (catalog_link_id?: string) => (await request<ApiData<SchedulingOptions>>(`/api/v1/team/service-offering-options?${queryString({ catalog_link_id })}`)).data,
  saveServiceOffering: async (input: ServiceOfferingInput, id?: string) => (await request<ApiData<ServiceOffering>>(`/api/v1/team/service-offerings${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) })).data,
  deactivateServiceOffering: (id: string) => request(`/api/v1/team/service-offerings/${id}`, { method: 'DELETE' }),
  publishOffering: (id: string, published_until: string) => request(`/api/v1/team/service-offerings/${id}/publication`, { method: 'PUT', body: JSON.stringify({ published_until, confirmed: true }) }),
  unpublishOffering: (id: string) => request(`/api/v1/team/service-offerings/${id}/publication`, { method: 'DELETE' }),
  saveScheduleWindow: (offeringId: string, input: ScheduleWindowInput, id?: string) => request<ApiData<ScheduleWindow>>(`/api/v1/team/service-offerings/${offeringId}/schedule-windows${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) }),
  deactivateScheduleWindow: (offeringId: string, id: string) => request(`/api/v1/team/service-offerings/${offeringId}/schedule-windows/${id}`, { method: 'DELETE' }),
  saveScheduleException: (offeringId: string, input: ScheduleExceptionInput, id?: string) => request<ApiData<ScheduleException>>(`/api/v1/team/service-offerings/${offeringId}/schedule-exceptions${id ? `/${id}` : ''}`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(input) }),
  removeScheduleException: (offeringId: string, id: string) => request(`/api/v1/team/service-offerings/${offeringId}/schedule-exceptions/${id}`, { method: 'DELETE' }),
  teamAvailability: async (filters: { offering_id: string; from: string; to: string; exclude_appointment_id?: string }) => (await request<ApiData<AvailabilityRow[]>>(`/api/v1/team/availability?${queryString(filters)}`)).data,
  lookupPatient: async (cpf: string) => (await request<ApiData<PatientLookup | null>>('/api/v1/team/patients/lookup', { method: 'POST', body: JSON.stringify({ cpf }) })).data,
  bookInternal: async (input: BookAppointmentInput) => (await request<ApiData<Appointment>>('/api/v1/team/appointments', { method: 'POST', body: JSON.stringify(input) })).data,
  rescheduleAppointment: async (id: string, input: { offering_id: string; starts_at: string; reason: string }) => (await request<ApiData<Appointment>>(`/api/v1/team/appointments/${id}/reschedule`, { method: 'PATCH', body: JSON.stringify(input) })).data,
  cancelAppointment: async (id: string, reason: string) => (await request<ApiData<Appointment>>(`/api/v1/team/appointments/${id}/cancel`, { method: 'PATCH', body: JSON.stringify({ reason }) })).data,
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
