<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BookAppointment;
use App\Enums\AsoStatus;
use App\Enums\AsoType;
use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Appointment;
use App\Models\MtAso;
use App\Models\MtAsoEvent;
use App\Models\MtFunction;
use App\Models\MtRisk;
use App\Models\MtSector;
use App\Models\Report;
use App\Models\ReportEvent;
use App\Models\ReportTemplate;
use App\Services\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MtController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'agreements' => Agreement::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'risks' => MtRisk::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'sectors' => MtSector::query()->with('agreement:id,name')->where('active', true)
                ->orderBy('name')->get(['id', 'agreement_id', 'name']),
            'functions' => MtFunction::query()->with(['sector:id,name', 'risk:id,name'])->where('active', true)
                ->orderBy('name')->get(['id', 'agreement_id', 'sector_id', 'risk_id', 'name']),
        ]]);
    }

    public function risksIndex(): JsonResponse
    {
        $risks = MtRisk::query()
            ->when(request('include_inactive') !== 'true', fn ($query) => $query->where('active', true))
            ->orderBy('name')->get();

        return response()->json(['data' => $risks->map(fn (MtRisk $risk): array => $this->serializeRisk($risk))->all()]);
    }

    public function risksStore(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200', 'unique:mt_risks,name']]);

        $risk = MtRisk::query()->create(['name' => $data['name'], 'active' => true]);
        $this->audit->record($request, $risk, 'mt.risk.created', null, $risk->attributesToArray());

        return response()->json(['data' => $this->serializeRisk($risk)], 201);
    }

    public function risksUpdate(Request $request, MtRisk $mtRisk): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200', Rule::unique('mt_risks', 'name')->ignore($mtRisk->id)],
            'active' => ['sometimes', 'boolean'],
        ]);

        $before = $mtRisk->attributesToArray();
        $mtRisk->fill($data)->save();
        $this->audit->record($request, $mtRisk, 'mt.risk.updated', $before, $mtRisk->attributesToArray());

        return response()->json(['data' => $this->serializeRisk($mtRisk->fresh())]);
    }

    public function sectorsIndex(Request $request): JsonResponse
    {
        $data = $request->validate(['agreement_id' => ['nullable', 'uuid', 'exists:agreements,id']]);

        $sectors = MtSector::query()->with('agreement:id,name')
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->when($request->boolean('include_inactive') !== true, fn ($query) => $query->where('active', true))
            ->orderBy('name')->get();

        return response()->json(['data' => $sectors->map(fn (MtSector $sector): array => $this->serializeSector($sector))->all()]);
    }

    public function sectorsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        $existing = MtSector::query()->where('agreement_id', $data['agreement_id'])->where('name', $data['name'])->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['name' => 'Este setor já existe nesta empresa.']);
        }

        $sector = MtSector::query()->create($data + ['active' => true]);
        $this->audit->record($request, $sector, 'mt.sector.created', null, $sector->attributesToArray());

        return response()->json(['data' => $this->serializeSector($sector->fresh('agreement:id,name'))], 201);
    }

    public function sectorsUpdate(Request $request, MtSector $mtSector): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $before = $mtSector->attributesToArray();
        $mtSector->fill($data)->save();
        $this->audit->record($request, $mtSector, 'mt.sector.updated', $before, $mtSector->attributesToArray());

        return response()->json(['data' => $this->serializeSector($mtSector->fresh(['agreement:id,name']))]);
    }

    public function functionsIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
            'sector_id' => ['nullable', 'uuid', 'exists:mt_sectors,id'],
        ]);

        $functions = MtFunction::query()->with(['sector:id,name', 'risk:id,name'])
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->when($data['sector_id'] ?? null, fn ($query, $id) => $query->where('sector_id', $id))
            ->when($request->boolean('include_inactive') !== true, fn ($query) => $query->where('active', true))
            ->orderBy('name')->get();

        return response()->json(['data' => $functions->map(fn (MtFunction $function): array => $this->serializeFunction($function))->all()]);
    }

    public function functionsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'sector_id' => ['nullable', 'uuid', 'exists:mt_sectors,id'],
            'risk_id' => ['nullable', 'uuid', 'exists:mt_risks,id'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        if (isset($data['sector_id'])) {
            $sectorAgreement = MtSector::query()->whereKey($data['sector_id'])->value('agreement_id');
            if ($sectorAgreement !== $data['agreement_id']) {
                throw ValidationException::withMessages(['sector_id' => 'O setor pertence a outra empresa.']);
            }
        }

        $existing = MtFunction::query()->where('agreement_id', $data['agreement_id'])->where('name', $data['name'])->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['name' => 'Esta função já existe nesta empresa.']);
        }

        $function = MtFunction::query()->create($data + ['active' => true]);
        $this->audit->record($request, $function, 'mt.function.created', null, $function->attributesToArray());

        return response()->json(['data' => $this->serializeFunction($function->fresh(['sector:id,name', 'risk:id,name']))], 201);
    }

    public function functionsUpdate(Request $request, MtFunction $mtFunction): JsonResponse
    {
        $data = $request->validate([
            'sector_id' => ['nullable', 'uuid', 'exists:mt_sectors,id'],
            'risk_id' => ['nullable', 'uuid', 'exists:mt_risks,id'],
            'name' => ['sometimes', 'string', 'max:200'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['sector_id']) && $data['sector_id'] !== null) {
            $sectorAgreement = MtSector::query()->whereKey($data['sector_id'])->value('agreement_id');
            if ($sectorAgreement !== $mtFunction->agreement_id) {
                throw ValidationException::withMessages(['sector_id' => 'O setor pertence a outra empresa.']);
            }
        }

        $before = $mtFunction->attributesToArray();
        $mtFunction->fill($data)->save();
        $this->audit->record($request, $mtFunction, 'mt.function.updated', $before, $mtFunction->attributesToArray());

        return response()->json(['data' => $this->serializeFunction($mtFunction->fresh(['sector:id,name', 'risk:id,name']))]);
    }

    public function asosIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
            'status' => ['nullable', Rule::enum(AsoStatus::class)],
            'type' => ['nullable', Rule::enum(AsoType::class)],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $asos = MtAso::query()->with([
            'patient:id,name,cpf_last_four,birth_date',
            'agreement:id,name',
            'sector:id,name',
            'function:id,name',
            'risks:id,name',
            'appointment:id,scheduled_at,status',
            'report:id,status',
        ])
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($data['due_from'] ?? null, fn ($query, $date) => $query->whereDate('expires_at', '>=', $date))
            ->when($data['due_to'] ?? null, fn ($query, $date) => $query->whereDate('expires_at', '<=', $date))
            ->when($data['q'] ?? null, fn ($query, $term) => $query->whereHas('patient', fn ($patient) => $patient->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])))
            ->where('status', '!=', AsoStatus::Cancelado)
            ->orderBy('expires_at')->orderBy('patient_id')
            ->paginate(50, page: (int) ($data['page'] ?? 1));

        return response()->json(['data' => [
            'items' => collect($asos->items())->map(fn (MtAso $aso): array => $this->serializeAso($aso))->all(),
            'page' => $asos->currentPage(),
            'pages' => $asos->lastPage(),
            'total' => $asos->total(),
        ]]);
    }

    public function asosDue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($data['days'] ?? config('sistema_dez.mt_due_warning_days'));
        $limit = CarbonImmutable::today(config('sistema_dez.timezone'))->addDays($days);

        $asos = MtAso::query()->with([
            'patient:id,name,cpf_last_four', 'agreement:id,name', 'sector:id,name', 'function:id,name',
        ])
            ->where('type', AsoType::Periodico)
            ->whereIn('status', [AsoStatus::Emitido->value, AsoStatus::Agendado->value, AsoStatus::Pendente->value])
            ->when($data['agreement_id'] ?? null, fn ($query, $id) => $query->where('agreement_id', $id))
            ->whereDate('expires_at', '<=', $limit)
            ->orderBy('expires_at')->get();

        $today = CarbonImmutable::today(config('sistema_dez.timezone'));

        return response()->json(['data' => [
            'days' => $days,
            'items' => $asos->map(fn (MtAso $aso): array => [
                ...$this->serializeAso($aso),
                'overdue' => $aso->expires_at !== null && $aso->expires_at->isBefore($today),
                'days_until_due' => $aso->expires_at !== null ? $today->diffInDays($aso->expires_at, false) : null,
            ])->all(),
        ]]);
    }

    public function asosStore(Request $request): JsonResponse
    {
        $data = $this->validateAso($request);

        $aso = DB::transaction(function () use ($request, $data): MtAso {
            $aso = MtAso::query()->create([
                ...collect($data)->except(['risks'])->all(),
                'status' => AsoStatus::Pendente,
                'created_by' => $request->user()->id,
            ]);

            if (isset($data['risks'])) {
                $aso->risks()->sync($data['risks']);
            }

            $aso->events()->create([
                'event' => MtAsoEvent::CREATED,
                'by_user_id' => $request->user()->id,
                'payload' => ['type' => $aso->type->value],
            ]);

            return $aso;
        });

        $this->audit->record($request, $aso, 'mt.aso.created', null, $aso->attributesToArray());

        return response()->json(['data' => $this->serializeAso($aso->fresh($this->asoRelations()))], 201);
    }

    public function asosUpdate(Request $request, MtAso $mtAso): JsonResponse
    {
        $data = $this->validateAso($request, $mtAso);

        $aso = DB::transaction(function () use ($request, $mtAso, $data): MtAso {
            $locked = MtAso::query()->lockForUpdate()->findOrFail($mtAso->id);
            abort_if($locked->status === AsoStatus::Cancelado, 422, 'ASO cancelado não pode ser editado.');

            $before = $locked->attributesToArray();
            $locked->fill(collect($data)->except(['risks'])->all())->save();

            if (array_key_exists('risks', $data)) {
                $locked->risks()->sync($data['risks'] ?? []);
            }

            $locked->events()->create([
                'event' => MtAsoEvent::UPDATED,
                'by_user_id' => $request->user()->id,
                'payload' => ['fields' => array_keys($data)],
            ]);

            $this->audit->record($request, $locked, 'mt.aso.updated', $before, $locked->attributesToArray());

            return $locked;
        });

        return response()->json(['data' => $this->serializeAso($aso->fresh($this->asoRelations()))]);
    }

    public function asosCancel(Request $request, MtAso $mtAso): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $aso = DB::transaction(function () use ($request, $mtAso, $data): MtAso {
            $locked = MtAso::query()->lockForUpdate()->findOrFail($mtAso->id);
            abort_if($locked->status === AsoStatus::Cancelado, 422, 'ASO já está cancelado.');

            $before = $locked->attributesToArray();
            $locked->forceFill(['status' => AsoStatus::Cancelado])->save();
            $locked->events()->create([
                'event' => MtAsoEvent::CANCELLED,
                'by_user_id' => $request->user()->id,
                'payload' => ['reason' => $data['reason']],
            ]);

            $this->audit->record($request, $locked, 'mt.aso.cancelled', $before, $locked->attributesToArray());

            return $locked;
        });

        return response()->json(['data' => $this->serializeAso($aso->fresh($this->asoRelations()))]);
    }

    public function asosExtend(Request $request, MtAso $mtAso): JsonResponse
    {
        $data = $request->validate(['expires_at' => ['required', 'date', 'after_or_equal:today']]);

        $aso = DB::transaction(function () use ($request, $mtAso, $data): MtAso {
            $locked = MtAso::query()->lockForUpdate()->findOrFail($mtAso->id);
            abort_if($locked->status === AsoStatus::Cancelado, 422, 'ASO cancelado não pode ser prorrogado.');

            $before = $locked->attributesToArray();
            $locked->forceFill(['expires_at' => $data['expires_at']])->save();
            $locked->events()->create([
                'event' => MtAsoEvent::EXTENDED,
                'by_user_id' => $request->user()->id,
                'payload' => ['from' => $before['expires_at'] ?? null, 'to' => $data['expires_at']],
            ]);

            $this->audit->record($request, $locked, 'mt.aso.extended', $before, $locked->attributesToArray());

            return $locked;
        });

        return response()->json(['data' => $this->serializeAso($aso->fresh($this->asoRelations()))]);
    }

    public function asosLinkExam(Request $request, MtAso $mtAso): JsonResponse
    {
        $data = $request->validate(['appointment_id' => ['required', 'uuid', 'exists:appointments,id']]);

        $aso = DB::transaction(function () use ($request, $mtAso, $data): MtAso {
            $locked = MtAso::query()->lockForUpdate()->findOrFail($mtAso->id);
            abort_if($locked->status === AsoStatus::Cancelado, 422, 'ASO cancelado.');

            $appointment = Appointment::query()->findOrFail($data['appointment_id']);
            abort_if($appointment->patient_id !== $locked->patient_id, 422, 'O atendimento pertence a outro paciente.');

            $before = $locked->attributesToArray();
            $locked->forceFill(['appointment_id' => $appointment->id])->save();
            if ($locked->status === AsoStatus::Pendente) {
                $locked->forceFill(['status' => AsoStatus::Agendado])->save();
            }

            $locked->events()->create([
                'event' => MtAsoEvent::EXAM_LINKED,
                'by_user_id' => $request->user()->id,
                'payload' => ['appointment_id' => $appointment->id, 'scheduled_at' => $appointment->scheduled_at?->toIso8601String()],
            ]);

            $this->audit->record($request, $locked, 'mt.aso.exam_linked', $before, $locked->attributesToArray());

            return $locked;
        });

        return response()->json(['data' => $this->serializeAso($aso->fresh($this->asoRelations()))]);
    }

    public function batchSchedule(Request $request, BookAppointment $booking): JsonResponse
    {
        $data = $request->validate([
            'offering_id' => ['required', 'uuid', 'exists:service_offerings,id'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.patient_id' => ['required', 'uuid', 'distinct', 'exists:patients,id'],
            'items.*.starts_at' => ['required', 'date'],
        ]);

        $results = [];
        foreach ($data['items'] as $index => $item) {
            try {
                $appointment = $booking->handle([
                    'offering_id' => $data['offering_id'],
                    'patient_id' => $item['patient_id'],
                    'starts_at' => $item['starts_at'],
                    'idempotency_key' => (string) Str::uuid(),
                ], $request->user());

                $results[] = ['index' => $index, 'patient_id' => $item['patient_id'], 'status' => 'ok', 'appointment_id' => $appointment->id];
            } catch (ValidationException $exception) {
                $results[] = [
                    'index' => $index,
                    'patient_id' => $item['patient_id'],
                    'status' => 'error',
                    'error' => collect($exception->errors())->flatten()->implode(' '),
                ];
            }
        }

        return response()->json(['data' => [
            'scheduled' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'ok')),
            'failed' => count(array_filter($results, fn (array $row): bool => $row['status'] === 'error')),
            'results' => $results,
        ]]);
    }

    public function asosEmit(Request $request, MtAso $mtAso): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => ['nullable', 'uuid', 'exists:appointments,id'],
            'template_id' => ['nullable', 'uuid', 'exists:report_templates,id'],
            'expires_at' => ['nullable', 'date'],
        ]);

        abort_if($mtAso->status === AsoStatus::Cancelado, 422, 'ASO cancelado não pode ser emitido.');

        $result = DB::transaction(function () use ($request, $mtAso, $data): array {
            $locked = MtAso::query()->lockForUpdate()->findOrFail($mtAso->id);
            abort_if($locked->report_id !== null, 422, 'ASO já possui laudo emitido.');
            $locked->loadMissing('agreement:id,name', 'patient:id,name,birth_date');

            $appointment = $locked->appointment_id !== null
                ? Appointment::query()->with(['patient:id,name,birth_date', 'serviceOffering:id,procedure_id,professional_id'])->findOrFail($locked->appointment_id)
                : (($data['appointment_id'] ?? null) !== null
                    ? Appointment::query()->with(['patient:id,name,birth_date', 'serviceOffering:id,procedure_id,professional_id'])->findOrFail($data['appointment_id'])
                    : throw ValidationException::withMessages(['appointment_id' => 'Vincule um atendimento ao ASO antes de emitir.']));

            abort_if($appointment->patient_id !== $locked->patient_id, 422, 'O atendimento pertence a outro paciente.');

            $template = isset($data['template_id'])
                ? ReportTemplate::query()->findOrFail($data['template_id'])
                : null;

            if ($template !== null) {
                abort_unless($template->active && $template->legacy_type === ReportTemplate::TYPE_REPORT, 422, 'Modelo inativo ou não é um laudo.');
                $content = $template->content_html;
            } else {
                $content = $this->defaultAsoContent($locked, $appointment);
            }

            $content = $this->hydrate($content, $locked, $appointment);
            $realizedAt = $locked->realized_at ?? CarbonImmutable::parse($appointment->scheduled_at)->toDateString();
            $expiresAt = $data['expires_at'] ?? $locked->expires_at
                ?? ($locked->type === AsoType::Periodico ? CarbonImmutable::parse($realizedAt)->addYear()->toDateString() : null);

            $report = Report::query()->create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'procedure_id' => $appointment->serviceOffering?->procedure_id,
                'doctor_id' => $appointment->serviceOffering?->professional_id,
                'template_id' => $template?->id,
                'content_html' => $content,
                'status' => Report::STATUS_RASCUNHO,
                'typed_by' => $request->user()->id,
            ]);
            $report->events()->create([
                'event' => ReportEvent::CREATED,
                'by_user_id' => $request->user()->id,
                'payload' => ['source' => 'mt_aso', 'mt_aso_id' => $locked->id],
            ]);

            $before = $locked->attributesToArray();
            $locked->forceFill([
                'appointment_id' => $appointment->id,
                'report_id' => $report->id,
                'status' => AsoStatus::Emitido,
                'realized_at' => $realizedAt,
                'expires_at' => $expiresAt,
                'issued_at' => now(),
            ])->save();

            $locked->events()->create([
                'event' => MtAsoEvent::ISSUED,
                'by_user_id' => $request->user()->id,
                'payload' => ['report_id' => $report->id, 'appointment_id' => $appointment->id],
            ]);

            $this->audit->record($request, $locked, 'mt.aso.issued', $before, $locked->attributesToArray());

            return [$locked->fresh($this->asoRelations()), $report];
        });

        [$aso, $report] = $result;

        return response()->json(['data' => [
            'aso' => $this->serializeAso($aso),
            'report' => ['id' => $report->id, 'status' => $report->status, 'content_html' => $report->content_html],
        ]], 201);
    }

    public function companyReport(Request $request, Agreement $agreement): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $timezone = config('sistema_dez.timezone');
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today($timezone);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subMonths(12);

        $asos = MtAso::query()->with(['patient:id,name,cpf_last_four', 'agreement:id,name', 'sector:id,name', 'function:id,name', 'risks:id,name'])
            ->where('agreement_id', $agreement->id)
            ->where('status', '!=', AsoStatus::Cancelado)
            ->get();

        $today = CarbonImmutable::today($timezone);
        $warningLimit = $today->addDays((int) config('sistema_dez.mt_due_warning_days'));

        $byStatus = $asos->countBy(fn (MtAso $aso): string => $aso->status->value);
        $byType = $asos->countBy(fn (MtAso $aso): string => $aso->type->value);

        $overdue = $asos->filter(fn (MtAso $aso): bool => $aso->expires_at !== null && $aso->expires_at->isBefore($today))->values();
        $dueSoon = $asos->filter(fn (MtAso $aso): bool => $aso->expires_at !== null && $aso->expires_at->greaterThanOrEqualTo($today) && $aso->expires_at->lessThanOrEqualTo($warningLimit))->values();

        $linkedAppointments = MtAso::query()->where('agreement_id', $agreement->id)
            ->whereHas('appointment', fn ($query) => $query->whereBetween('scheduled_at', [$from->utc(), $to->utc()]))
            ->where('status', '!=', AsoStatus::Cancelado)
            ->count();

        return response()->json(['data' => [
            'agreement' => ['id' => $agreement->id, 'name' => $agreement->name],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_asos' => $asos->count(),
            'by_status' => $byStatus,
            'by_type' => $byType,
            'linked_appointments_in_period' => $linkedAppointments,
            'overdue' => $overdue->map(fn (MtAso $aso): array => $this->serializeAso($aso))->all(),
            'due_soon' => $dueSoon->map(fn (MtAso $aso): array => $this->serializeAso($aso))->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function validateAso(Request $request, ?MtAso $aso = null): array
    {
        return $request->validate([
            'patient_id' => [$aso === null ? 'required' : 'sometimes', 'uuid', 'exists:patients,id'],
            'agreement_id' => [$aso === null ? 'required' : 'sometimes', 'uuid', 'exists:agreements,id'],
            'sector_id' => ['nullable', 'uuid', 'exists:mt_sectors,id'],
            'function_id' => ['nullable', 'uuid', 'exists:mt_functions,id'],
            'type' => ['required', Rule::enum(AsoType::class)],
            'realized_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'appointment_id' => ['nullable', 'uuid', 'exists:appointments,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'risks' => ['sometimes', 'array'],
            'risks.*' => ['uuid', 'exists:mt_risks,id'],
        ]);
    }

    /** @return list<string> */
    private function asoRelations(): array
    {
        return [
            'patient:id,name,cpf_last_four,birth_date',
            'agreement:id,name',
            'sector:id,name',
            'function:id,name',
            'risks:id,name',
            'appointment:id,scheduled_at,status',
            'report:id,status',
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRisk(MtRisk $risk): array
    {
        return ['id' => $risk->id, 'name' => $risk->name, 'active' => $risk->active, 'legacy_id' => $risk->legacy_id];
    }

    /** @return array<string, mixed> */
    private function serializeSector(MtSector $sector): array
    {
        return [
            'id' => $sector->id,
            'agreement' => $sector->agreement?->only(['id', 'name']),
            'name' => $sector->name,
            'active' => $sector->active,
            'legacy_id' => $sector->legacy_id,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeFunction(MtFunction $function): array
    {
        return [
            'id' => $function->id,
            'name' => $function->name,
            'sector' => $function->sector?->only(['id', 'name']),
            'risk' => $function->risk?->only(['id', 'name']),
            'active' => $function->active,
            'legacy_id' => $function->legacy_id,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAso(MtAso $aso): array
    {
        return [
            'id' => $aso->id,
            'patient' => $aso->patient?->only(['id', 'name', 'cpf_last_four', 'birth_date']),
            'agreement' => $aso->agreement?->only(['id', 'name']),
            'sector' => $aso->sector?->only(['id', 'name']),
            'function' => $aso->function?->only(['id', 'name']),
            'risks' => $aso->relationLoaded('risks') ? $aso->risks->map(fn (MtRisk $risk) => $risk->only(['id', 'name']))->all() : [],
            'type' => $aso->type->value,
            'type_label' => $aso->type->label(),
            'status' => $aso->status->value,
            'status_label' => $aso->status->label(),
            'realized_at' => $aso->realized_at?->toDateString(),
            'expires_at' => $aso->expires_at?->toDateString(),
            'issued_at' => $aso->issued_at?->toIso8601String(),
            'appointment' => $aso->relationLoaded('appointment') && $aso->appointment !== null
                ? ['id' => $aso->appointment->id, 'scheduled_at' => $aso->appointment->scheduled_at?->toIso8601String(), 'status' => $aso->appointment->status]
                : null,
            'report' => $aso->relationLoaded('report') && $aso->report !== null
                ? ['id' => $aso->report->id, 'status' => $aso->report->status]
                : null,
            'notes' => $aso->notes,
            'legacy_id' => $aso->legacy_id,
            'created_at' => $aso->created_at?->toIso8601String(),
        ];
    }

    private function hydrate(string $content, MtAso $aso, Appointment $appointment): string
    {
        $patient = $aso->patient;
        $risks = $aso->risks->pluck('name')->implode(', ');
        $replacements = [
            '{{PACIENTE_NOME}}' => e((string) $patient?->name),
            '{{PACIENTE_IDADE}}' => $patient?->birth_date !== null ? (string) (int) $patient->birth_date->diffInYears(now(config('sistema_dez.timezone'))) : '____',
            '{{DATA_HOJE}}' => now(config('sistema_dez.timezone'))->format('d/m/Y'),
            '{{EMPRESA}}' => e((string) $aso->agreement?->name),
            '{{SETOR}}' => e((string) $aso->sector?->name),
            '{{FUNCAO}}' => e((string) $aso->function?->name),
            '{{RISCO}}' => e($risks),
            '{{TIPO_ASO}}' => e($aso->type->label()),
            '{{DATA_ASO}}' => $aso->realized_at?->format('d/m/Y') ?? now(config('sistema_dez.timezone'))->format('d/m/Y'),
            '{{VALIDADE_ASO}}' => $aso->expires_at?->format('d/m/Y') ?? '____',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function defaultAsoContent(MtAso $aso, Appointment $appointment): string
    {
        return '<h2>Atestado de Saúde Ocupacional (ASO)</h2>'
            .'<p><strong>Empregado(a):</strong> {{PACIENTE_NOME}}</p>'
            .'<p><strong>Empresa:</strong> {{EMPRESA}}</p>'
            .'<p><strong>Setor:</strong> {{SETOR}}</p>'
            .'<p><strong>Função:</strong> {{FUNCAO}}</p>'
            .'<p><strong>Riscos ocupacionais:</strong> {{RISCO}}</p>'
            .'<p><strong>Tipo de exame:</strong> {{TIPO_ASO}}</p>'
            .'<p><strong>Data do exame:</strong> {{DATA_ASO}}</p>'
            .'<p><strong>Válido até:</strong> {{VALIDADE_ASO}}</p>'
            .'<p>Resultado: <strong>APTO</strong> <em>(revisar antes de finalizar)</em></p>';
    }
}
