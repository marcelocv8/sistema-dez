import { delay, http, HttpResponse } from 'msw';

const publicServices = [
  { id: 'proc-clinica', name: 'Clínica Geral', type: 'CONSULTATION', group: 'Consultas', duration_minutes: 30, offerings: [
    { id: 'off-clinica-ana', unit: { id: 'unit-centro', name: 'Unidade Centro' }, professional: { id: 'pro-ana', name: 'Dra. Ana Clara' }, agreement: { id: 'particular', name: 'Particular' }, modality: 'PRESENTIAL', booking_mode: 'FIXED_SLOT', price: '89.00', duration_minutes: 30 },
    { id: 'off-clinica-bruno', unit: { id: 'unit-centro', name: 'Unidade Centro' }, professional: { id: 'pro-bruno', name: 'Dr. Bruno Silva' }, agreement: { id: 'particular', name: 'Particular' }, modality: 'PRESENTIAL', booking_mode: 'ARRIVAL_BLOCK', price: '79.00', duration_minutes: 30 },
  ]},
  { id: 'proc-cardio', name: 'Cardiologia', type: 'CONSULTATION', group: 'Consultas', duration_minutes: 40, offerings: [
    { id: 'off-cardio', unit: { id: 'unit-centro', name: 'Unidade Centro' }, professional: { id: 'pro-camila', name: 'Dra. Camila Torres' }, agreement: { id: 'particular', name: 'Particular' }, modality: 'PRESENTIAL', booking_mode: 'FIXED_SLOT', price: '129.00', duration_minutes: 40 },
  ]},
  { id: 'proc-tele', name: 'Teleconsulta clínica', type: 'CONSULTATION', group: 'Telemedicina', duration_minutes: 30, offerings: [
    { id: 'off-tele', unit: { id: 'unit-centro', name: 'Clínica DEZ' }, professional: { id: 'pro-diego', name: 'Dr. Diego Lima' }, agreement: { id: 'particular', name: 'Particular' }, modality: 'TELECONSULTATION', booking_mode: 'FIXED_SLOT', price: '69.00', duration_minutes: 30 },
  ]},
  { id: 'proc-ecg', name: 'Eletrocardiograma', type: 'EXAM', group: 'Exames', duration_minutes: 20, offerings: [
    { id: 'off-ecg', unit: { id: 'unit-centro', name: 'Unidade Centro' }, professional: null, agreement: { id: 'particular', name: 'Particular' }, modality: 'PRESENTIAL', booking_mode: 'WALK_IN_INFO', price: '45.00', duration_minutes: 20 },
  ]},
] as const;

const appointments = [
  ['1', 'Maria Souza', 'Dra. Ana Clara', 'Clínica Geral', '08:00', '08:45', 'CONFIRMED'],
  ['2', 'João Pereira', 'Dra. Ana Clara', 'Clínica Geral', '09:00', '09:45', 'CHECKED_IN'],
  ['3', 'Beatriz Nunes', 'Dr. Bruno Silva', 'Cardiologia', '10:00', '10:45', 'CONFIRMED'],
  ['4', 'Camila Ferreira', 'Dra. Camila Torres', 'Dermatologia', '13:00', '13:45', 'CHECKED_IN'],
  ['5', 'Bruno Nascimento', 'Dr. Diego Lima', 'Ortopedia', '13:00', '13:45', 'CONFIRMED'],
] as const;

const adminPermissions = ['prices.view', 'catalog.view', 'catalog.manage', 'catalog-links.view', 'catalog-links.manage', 'team.view', 'team.manage', 'imports.view', 'imports.resolve', 'audit.view', 'schedule.view'];
const catalogMock = {
  procedures: [{ id: 'procedure-145', legacy_id: '145', name: 'US CALCANEO DIREITO', type: 'EXAM', group_name: 'ULTRASSONOGRAFIA', tuss_code: '40901222', duration_minutes: 30, legacy: false, online_enabled: false, active: true, classification_status: 'ATIVO' }],
  agreements: [{ id: 'agreement-49', legacy_id: '49', name: 'PARTICULAR M', type: 'PRIVATE', is_default: true, online_enabled: false, active: true, classification_status: 'ATIVO' }],
  professionals: [{ id: 'professional-10', legacy_id: '10', name: 'DRA. ANA CLARA', council_acronym: 'CRM', council_number: '12345', online_enabled: false, active: true, classification_status: 'ATIVO' }],
  units: [{ id: 'unit-1', legacy_id: '1', name: 'CLINICA DEZ MESSEJANA', timezone: 'America/Fortaleza', cnpj: null, active: true }],
};

export const handlers = [
  http.get('/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
  http.post('/api/v1/auth/login', async () => HttpResponse.json({ data: { id: 'user-1', name: 'Rafael Mendes', email: 'rafael@dez.local', role: 'ADMIN', role_label: 'Administrador', permissions: adminPermissions } })),
  http.post('/api/v1/auth/logout', () => new HttpResponse(null, { status: 204 })),
  http.get('/api/v1/public/services', async ({ request }) => {
    await delay(180);
    const url = new URL(request.url);
    const search = url.searchParams.get('search')?.toLocaleLowerCase('pt-BR');
    const type = url.searchParams.get('type');
    const modality = url.searchParams.get('modality');
    const data = publicServices.filter((service) => (!search || service.name.toLocaleLowerCase('pt-BR').includes(search)) && (!type || service.type === type))
      .map((service) => ({ ...service, offerings: service.offerings.filter((offering) => !modality || offering.modality === modality) }))
      .filter((service) => service.offerings.length > 0);
    return HttpResponse.json({ data });
  }),
  http.get('/api/v1/public/availability', async ({ request }) => {
    await delay(220);
    const url = new URL(request.url);
    const procedureId = url.searchParams.get('procedure_id');
    const service = publicServices.find((item) => item.id === procedureId);
    const start = url.searchParams.get('from') ?? new Date().toISOString().slice(0, 10);
    const dates = Array.from({ length: 5 }, (_, index) => { const date = new Date(`${start}T12:00:00`); date.setDate(date.getDate() + index); return date.toISOString().slice(0, 10); });
    const data = (service?.offerings ?? []).flatMap((offering) => dates.slice(0, offering.booking_mode === 'ARRIVAL_BLOCK' ? 1 : 5).map((date) => ({
      date, offering_id: offering.id, booking_mode: offering.booking_mode, professional: offering.professional,
      operating_window: { starts_at: `${date}T08:00:00-03:00`, ends_at: `${date}T17:00:00-03:00` },
      slots: offering.booking_mode === 'WALK_IN_INFO' ? [] : (offering.booking_mode === 'ARRIVAL_BLOCK'
        ? [{ starts_at: `${date}T08:00:00-03:00`, ends_at: `${date}T11:00:00-03:00`, recommended_arrival_at: `${date}T07:45:00-03:00`, remaining_capacity: 7 }]
        : ['08:00','09:30','11:00','14:00','15:30'].map((time, index) => ({ starts_at: `${date}T${time}:00-03:00`, ends_at: `${date}T${String(Number(time.slice(0,2)) + 1).padStart(2,'0')}:${time.slice(3)}:00-03:00`, recommended_arrival_at: null, remaining_capacity: index === 2 ? 1 : 3 })))
    })));
    return HttpResponse.json({ data });
  }),
  http.post('/api/v1/public/appointments', async ({ request }) => {
    await delay(500);
    const body = await request.json() as { offering_id: string; starts_at: string };
    const offering = publicServices.flatMap((service) => service.offerings.map((item) => ({ service, item }))).find(({ item }) => item.id === body.offering_id);
    return HttpResponse.json({ data: { id: crypto.randomUUID(), status: 'CONFIRMED', scheduled_at: body.starts_at, recommended_arrival_at: null, booking_mode: offering?.item.booking_mode ?? 'FIXED_SLOT', service: { offering_id: body.offering_id, procedure_id: offering?.service.id ?? '', procedure_name: offering?.service.name ?? '' }, price: offering?.item.price ?? '0.00', management_token: crypto.randomUUID() } }, { status: 201 });
  }),
  http.get('/api/v1/me', async () => {
    await delay(120);
    return HttpResponse.json({ data: { id: 'user-1', name: 'Rafael Mendes', email: 'rafael@dez.local', role: 'ADMIN', role_label: 'Administrador', permissions: adminPermissions } });
  }),
  http.get('/api/v1/team/price-options', () => HttpResponse.json({ data: {
    agreements: [
      { id: 'agreement-49', legacy_id: '49', name: 'PARTICULAR M', is_default: true },
      { id: 'agreement-71', legacy_id: '71', name: 'SOU DEZ COMPLETO', is_default: false },
    ],
    groups: { CONSULTATION: ['CONSULTA'], EXAM: ['ULTRASSONOGRAFIA', 'LABORATORIAL'] },
  } })),
  http.get('/api/v1/team/prices', async ({ request }) => {
    await delay(100);
    const url = new URL(request.url);
    const type = (url.searchParams.get('type') ?? 'CONSULTATION') as 'CONSULTATION' | 'EXAM';
    const agreementId = url.searchParams.get('agreement_id') ?? 'agreement-49';
    const query = url.searchParams.get('q') ?? '';
    const isPlan = agreementId === 'agreement-71';
    const items = type === 'CONSULTATION' ? [{
      id: `price-consult-${agreementId}`,
      procedure: { id: 'procedure-100', legacy_id: '100', tuss_code: '10101012', name: 'CONSULTA CLÍNICA GERAL' },
      agreement: { id: agreementId, legacy_id: isPlan ? '71' : '49', name: isPlan ? 'SOU DEZ COMPLETO' : 'PARTICULAR M' },
      unit: isPlan ? null : { id: 'unit-1', legacy_id: '1', name: 'CLINICA DEZ MESSEJANA' },
      nature: isPlan ? 'PLAN' : 'PRIVATE', amount: isPlan ? '69.00' : '89.00',
    }] : [{
      id: `price-exam-${agreementId}`,
      procedure: { id: 'procedure-189', legacy_id: '189', tuss_code: '40901222', name: 'US TIREOIDE COM DOPPLER' },
      agreement: { id: agreementId, legacy_id: isPlan ? '71' : '49', name: isPlan ? 'SOU DEZ COMPLETO' : 'PARTICULAR M' },
      unit: null, nature: isPlan ? 'PLAN' : 'PRIVATE', amount: isPlan ? '110.00' : '145.00',
    }];
    return HttpResponse.json({ data: { agreement_id: agreementId, query, types: [{ type, groups: [{ group_name: type === 'CONSULTATION' ? 'CONSULTA' : 'ULTRASSONOGRAFIA', items }] }] } });
  }),
  http.get('/api/v1/team/catalog/:resource', ({ params }) => {
    const data = catalogMock[params.resource as keyof typeof catalogMock] ?? [];
    return HttpResponse.json({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
  }),
  http.post('/api/v1/team/catalog/:resource', async ({ request }) => HttpResponse.json({ data: { id: crypto.randomUUID(), ...(await request.json() as object), classification_status: 'MANUAL' } }, { status: 201 })),
  http.put('/api/v1/team/catalog/:resource/:id', async ({ params, request }) => HttpResponse.json({ data: { id: params.id, ...(await request.json() as object), classification_status: 'MANUAL' } })),
  http.delete('/api/v1/team/catalog/:resource/:id', ({ params }) => HttpResponse.json({ data: { id: params.id, active: false } })),
  http.get('/api/v1/team/catalog-link-options', () => HttpResponse.json({ data: catalogMock })),
  http.get('/api/v1/team/catalog-links', () => HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })),
  http.post('/api/v1/team/catalog-links', async ({ request }) => HttpResponse.json({ data: { id: crypto.randomUUID(), ...(await request.json() as object) } }, { status: 201 })),
  http.get('/api/v1/team/import-issues', () => HttpResponse.json({ data: [{ id: 'issue-1', code: 'LEGACY_ID_NOT_FOUND', entity_type: 'procedure', legacy_id: null, source_file: 'classif_procedimentos.csv', payload: { nome: 'PROCEDIMENTO SEM ID' }, resolution_status: 'OPEN', resolution_note: null }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, import_run_id: 'run-1' } })),
  http.put('/api/v1/team/import-issues/:id', async ({ params, request }) => HttpResponse.json({ data: { id: params.id, ...(await request.json() as object) } })),
  http.get('/api/v1/team/users', () => HttpResponse.json({ data: [{ id: 'user-1', name: 'Rafael Mendes', email: 'rafael@dez.local', role: 'ADMIN', role_label: 'Administrador', active: true, permissions: adminPermissions }] })),
  http.post('/api/v1/team/users', async ({ request }) => HttpResponse.json({ data: { id: crypto.randomUUID(), ...(await request.json() as object) } }, { status: 201 })),
  http.put('/api/v1/team/users/:id', async ({ params, request }) => HttpResponse.json({ data: { id: params.id, ...(await request.json() as object) } })),
  http.get('/api/v1/team/audit-logs', () => HttpResponse.json({ data: [{ id: 1, entity_type: 'App\\Models\\Procedure', entity_key: 'procedure-145', action: 'updated', actor_id: 'user-1', before: null, after: null, created_at: new Date().toISOString() }], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } })),
  http.get('/api/v1/dashboard', async () => {
    await delay(180);
    return HttpResponse.json({ data: {
      date: new Date().toISOString().slice(0, 10),
      patient_flow: { waiting: 8, in_care: 12, checkout: 5, finished: 28 },
      queue: appointments.slice(0, 5).map(([id, patientName, , specialty, startsAt], index) => ({
        id, patient_name: patientName, procedure_name: specialty,
        checked_in_at: `${new Date().toISOString().slice(0, 10)}T${String(8 + index).padStart(2, '0')}:15:00-03:00`,
        status: index === 1 ? 'IN_SERVICE' : 'WAITING',
      })),
      financials_available: false, revenue: '24560.00', cash_expected: '24600.00', cash_difference: '60.00', commissions: '18750.00', b2b_invoices: '32840.00',
    } });
  }),
  http.get('/api/v1/team/appointments', ({ request }) => {
    const url = new URL(request.url);
    const date = url.searchParams.get('date') ?? new Date().toISOString().slice(0, 10);
    return HttpResponse.json({ data: {
      date,
      appointments: appointments.map(([id, patientName, doctorName, specialty, startsAt, endsAt, status]) => ({
        id, scheduled_at: `${date}T${startsAt}:00-03:00`, ends_at: `${date}T${endsAt}:00-03:00`, status, kind: 'NEW', origin: 'ONLINE',
        patient_name: patientName, professional: { id: `pro-${id}`, name: doctorName },
        procedure: { id: `proc-${id}`, name: specialty, group_name: specialty }, price: '120.00',
      })),
    } });
  }),
  http.post('/api/v1/queue/:id/call', async () => { await delay(300); return HttpResponse.json({ ok: true }); }),
  http.post('/api/v1/reports/:id/publish', async () => { await delay(400); return HttpResponse.json({ publishedAt: new Date().toISOString() }); }),
  http.get('/api/v1/team/loyalty/plans', () => HttpResponse.json({ data: [{
    id: 'plan-1', name: 'SOU DEZ COMPLETO', monthly_fee: '89.90', due_day: 10, active: true,
    agreement: { id: 'agreement-71', name: 'SOU DEZ COMPLETO', is_default: false }, active_subscriptions_count: 1,
  }] })),
  http.get('/api/v1/team/loyalty/subscriptions', () => HttpResponse.json({ data: [loyaltySubscriptionMock] })),
  http.get('/api/v1/team/loyalty/subscriptions/:id', ({ params }) => HttpResponse.json({ data: { ...loyaltySubscriptionMock, id: params.id } })),
  http.post('/api/v1/team/loyalty/subscriptions', async () => HttpResponse.json({ data: loyaltySubscriptionMock }, { status: 201 })),
  http.patch('/api/v1/team/loyalty/subscriptions/:id', async ({ params, request }) => HttpResponse.json({ data: { ...loyaltySubscriptionMock, id: params.id, ...(await request.json() as object) } })),
  http.get('/api/v1/team/loyalty/dues', ({ request }) => {
    const url = new URL(request.url);
    const overdue = url.searchParams.get('overdue') === '1';
    const due = { ...loyaltyDueMock, overdue };
    return HttpResponse.json({ data: overdue ? [due] : [due, { ...loyaltyDueMock, id: 'due-2', status: 'PAGA', overdue: false, paid_at: new Date().toISOString() }] });
  }),
  http.post('/api/v1/team/loyalty/dues/:id/collect', async ({ params }) => HttpResponse.json({ data: { ...loyaltyDueMock, id: params.id, status: 'PAGA', overdue: false, paid_at: new Date().toISOString() } }, { status: 201 })),
  http.get('/api/v1/team/whatsapp/lines', () => HttpResponse.json({ data: {
    enabled: true,
    lines: [{ id: 7, numero: '5585888800010', status_saude: 'conectado', provedor: 'baileys' }],
    selected: null,
    error: null,
  } })),
  http.put('/api/v1/team/whatsapp/lines', async ({ request }) => {
    const body = await request.json() as { conexao_id: number | null };
    return HttpResponse.json({ data: { selected: body.conexao_id } });
  }),
];

const loyaltySubscriptionMock = {
  id: 'sub-1', status: 'ACTIVE', started_at: new Date().toISOString().slice(0, 10), ended_at: null, notes: null,
  plan: { id: 'plan-1', name: 'SOU DEZ COMPLETO', monthly_fee: '89.90', due_day: 10, agreement: { id: 'agreement-71', name: 'SOU DEZ COMPLETO' } },
  patient: { id: 'patient-1', name: 'Maria Sou DEZ', cpf_last_four: '5009' },
  dependents: [{ id: 'dep-1', active: true, patient: { id: 'patient-2', name: 'João Sou DEZ', cpf_last_four: '7697' } }],
  dues: [{ id: 'due-1', competence: new Date().toISOString().slice(0, 8) + '01', amount: '89.90', due_date: new Date().toISOString().slice(0, 10), status: 'PENDENTE', overdue: false, paid_at: null }],
  overdue_count: 0,
};

const loyaltyDueMock = {
  id: 'due-1', competence: new Date().toISOString().slice(0, 8) + '01', amount: '89.90', due_date: new Date().toISOString().slice(0, 10),
  status: 'PENDENTE', overdue: false, paid_at: null,
  subscription: { id: 'sub-1', plan_name: 'SOU DEZ COMPLETO', patient: { id: 'patient-1', name: 'Maria Sou DEZ', cpf_last_four: '5009' } },
};
