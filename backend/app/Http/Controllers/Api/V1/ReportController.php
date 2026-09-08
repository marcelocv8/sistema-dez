<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Report;
use App\Models\ReportEvent;
use App\Models\ReportTemplate;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function templates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'procedure_id' => ['nullable', 'uuid'],
            'doctor_id' => ['nullable', 'uuid'],
            'legacy_type' => ['nullable', 'in:LAUDO,LINHA'],
        ]);

        $templates = ReportTemplate::query()->with(['doctor:user_id,id,name', 'procedure:id,name'])
            ->where('active', true)
            ->when($data['legacy_type'] ?? null, fn ($query, $type) => $query->where('legacy_type', $type))
            ->when($data['procedure_id'] ?? null, fn ($query, $id) => $query->where(function ($query) use ($id) {
                $query->where('procedure_id', $id)->orWhereNull('procedure_id');
            }))
            ->when($data['doctor_id'] ?? null, fn ($query, $id) => $query->where(function ($query) use ($id) {
                $query->where('doctor_id', $id)->orWhereNull('doctor_id');
            }))
            ->when($data['q'] ?? null, fn ($query, $term) => $query->where(function ($query) use ($term) {
                $query->whereRaw('lower(nome) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(legacy_procedure) like ?', ['%'.mb_strtolower($term).'%']);
            }))
            ->orderBy('legacy_type')->orderBy('nome')->limit(200)->get();

        return response()->json(['data' => $templates->map(fn (ReportTemplate $template): array => [
            'id' => $template->id,
            'legacy_type' => $template->legacy_type,
            'nome' => $template->nome,
            'doctor' => $template->doctor?->only(['id', 'name']),
            'procedure' => $template->procedure?->only(['id', 'name']),
            'legacy_procedure' => $template->legacy_procedure,
            'preview' => mb_substr(trim(strip_tags($template->content_html)), 0, 120),
            'content_html' => $template->legacy_type === ReportTemplate::TYPE_LINE ? $template->content_html : null,
        ])->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => ['required', 'uuid', 'exists:appointments,id'],
            'template_id' => ['required', 'uuid', 'exists:report_templates,id'],
        ]);

        $report = DB::transaction(function () use ($request, $data): Report {
            $appointment = Appointment::query()->with(['patient:id,name,birth_date', 'serviceOffering:id,procedure_id,professional_id'])->findOrFail($data['appointment_id']);
            $template = ReportTemplate::query()->findOrFail($data['template_id']);
            abort_unless($template->active, 422, 'Modelo inativo.');

            $report = Report::query()->create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'procedure_id' => $appointment->serviceOffering?->procedure_id,
                'doctor_id' => $appointment->serviceOffering?->professional_id,
                'template_id' => $template->id,
                'content_html' => $this->hydrate($template->content_html, $appointment),
                'status' => Report::STATUS_RASCUNHO,
                'typed_by' => $request->user()->id,
            ]);
            $report->events()->create(['event' => ReportEvent::CREATED, 'by_user_id' => $request->user()->id, 'payload' => ['template_id' => $template->id]]);
            $this->audit->record($request, $report, 'report.created', null, $report->attributesToArray());

            return $report;
        }, 3);

        return response()->json(['data' => $this->serialize($report->fresh(['patient:id,name', 'procedure:id,name', 'doctor.user:id,name']))], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => ['nullable', 'uuid'],
            'patient_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:RASCUNHO,FINALIZADO'],
        ]);
        abort_if(! isset($data['appointment_id']) && ! isset($data['patient_id']), 422, 'Informe appointment_id ou patient_id.');

        $reports = Report::query()->with(['procedure:id,name', 'doctor.user:id,name'])
            ->when($data['appointment_id'] ?? null, fn ($query, $id) => $query->where('appointment_id', $id))
            ->when($data['patient_id'] ?? null, fn ($query, $id) => $query->where('patient_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $reports->map(fn (Report $report): array => $this->serialize($report))->all()]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
        ]);

        $reports = Report::query()->with(['procedure:id,name', 'doctor.user:id,name'])
            ->where('patient_id', $data['patient_id'])
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $reports->map(fn (Report $report): array => $this->serialize($report))->all()]);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        return response()->json(['data' => $this->serialize($report->load(['patient:id,name,birth_date', 'procedure:id,name', 'doctor.user:id,name', 'events.byUser:id,name', 'events']))]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'content_html' => ['required', 'string', 'max:2000000'],
        ]);
        if ($report->status !== Report::STATUS_RASCUNHO) {
            throw ValidationException::withMessages(['report' => 'Somente rascunhos podem ser editados; use o adendo.']);
        }
        $before = $report->attributesToArray();
        $report->forceFill(['content_html' => $data['content_html']])->save();
        $report->events()->create(['event' => ReportEvent::SAVED, 'by_user_id' => $request->user()->id]);

        return response()->json(['data' => $this->serialize($report->fresh())]);
    }

    public function finalize(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== Report::STATUS_RASCUNHO) {
            throw ValidationException::withMessages(['report' => 'Laudo já finalizado.']);
        }
        $report = DB::transaction(function () use ($request, $report): Report {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            $before = $locked->attributesToArray();
            $locked->forceFill([
                'status' => Report::STATUS_FINALIZADO,
                'content_backup' => $locked->content_html,
                'finalized_at' => now(),
                'retention_until' => now()->addYears((int) config('sistema_dez.report_retention_years'))->toDateString(),
            ])->save();
            $locked->events()->create(['event' => ReportEvent::FINALIZED, 'by_user_id' => $request->user()->id]);
            $this->audit->record($request, $locked, 'report.finalized', $before, $locked->attributesToArray());

            return $locked;
        }, 3);

        return response()->json(['data' => $this->serialize($report->fresh())]);
    }

    public function append(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'adendo' => ['required', 'string', 'min:3', 'max:20000'],
        ]);
        $report = DB::transaction(function () use ($request, $report, $data): Report {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            $adendo = e($data['adendo']);
            $before = $locked->attributesToArray();
            $locked->forceFill([
                'adendo' => $adendo,
                'content_html' => $locked->content_html.'<div class="laudo-adendo"><p><strong>ADENDO</strong></p><p>'.nl2br($adendo).'</p></div>',
            ])->save();
            $locked->events()->create(['event' => ReportEvent::APPENDED, 'by_user_id' => $request->user()->id]);
            $this->audit->record($request, $locked, 'report.appended', $before, $locked->attributesToArray());

            return $locked;
        }, 3);

        return response()->json(['data' => $this->serialize($report->fresh())]);
    }

    public function signManual(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== Report::STATUS_FINALIZADO) {
            throw ValidationException::withMessages(['report' => 'Somente laudos finalizados recebem registro de assinatura manual.']);
        }
        $before = $report->attributesToArray();
        $report->forceFill(['signed_manual_by' => $request->user()->id, 'signed_manual_at' => now()])->save();
        $report->events()->create(['event' => ReportEvent::SIGNED_MANUAL, 'by_user_id' => $request->user()->id]);
        $this->audit->record($request, $report, 'report.signed-manual', $before, $report->attributesToArray());

        return response()->json(['data' => $this->serialize($report->fresh())]);
    }

    public function print(Request $request, Report $report): JsonResponse
    {
        $event = $report->print_count === 0 ? ReportEvent::PRINTED : ReportEvent::REPRINTED;
        $report->forceFill([
            'print_count' => $report->print_count + 1,
            'last_print_by' => $request->user()->id,
            'last_printed_at' => now(),
        ])->save();
        $report->events()->create(['event' => $event, 'by_user_id' => $request->user()->id]);

        return response()->json(['data' => [
            'print_html' => $this->printHtml($report->load(['patient', 'procedure:id,name', 'doctor.user', 'doctor'])),
            'print_count' => $report->print_count,
        ]]);
    }

    private function hydrate(string $content, Appointment $appointment): string
    {
        $patient = $appointment->patient;
        $replacements = [
            '{{PACIENTE_NOME}}' => e((string) $patient?->name),
            '{{PACIENTE_IDADE}}' => $patient?->birth_date !== null ? (string) (int) now(config('sistema_dez.timezone'))->diffInYears($patient->birth_date) : '____',
            '{{DATA_HOJE}}' => now(config('sistema_dez.timezone'))->format('d/m/Y'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /** @return array<string, mixed> */
    private function serialize(Report $report): array
    {
        return [
            'id' => $report->id,
            'appointment_id' => $report->appointment_id,
            'patient_id' => $report->patient_id,
            'patient' => $report->relationLoaded('patient') ? $report->patient?->only(['id', 'name', 'birth_date']) : null,
            'procedure' => $report->procedure?->only(['id', 'name']),
            'doctor' => $report->relationLoaded('doctor') && $report->doctor !== null && $report->doctor->relationLoaded('user')
                ? $report->doctor->user?->only(['id', 'name']) ?? ['id' => $report->doctor->id, 'name' => $report->doctor->name]
                : null,
            'template_id' => $report->template_id,
            'status' => $report->status,
            'content_html' => $report->content_html,
            'adendo' => $report->adendo,
            'finalized_at' => $report->finalized_at?->toIso8601String(),
            'retention_until' => $report->retention_until?->toDateString(),
            'signed_manual' => $report->signed_manual_at !== null,
            'signed_manual_at' => $report->signed_manual_at?->toIso8601String(),
            'print_count' => $report->print_count,
            'last_printed_at' => $report->last_printed_at?->toIso8601String(),
            'portal_published' => $report->portal_published_at !== null && $report->portal_unpublished_at === null,
            'portal_published_at' => $report->portal_published_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
            'events' => $report->relationLoaded('events') ? $report->events->map(fn (ReportEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'by' => $event->byUser?->only(['id', 'name']),
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all() : [],
        ];
    }

    private function printHtml(Report $report): string
    {
        $patient = $report->patient;
        $doctor = $report->doctor;
        $doctorName = $doctor?->user?->name ?? $doctor?->name;
        $doctorCouncil = $doctor !== null && $doctor->council_acronym !== null
            ? ' — '.e((string) $doctor->council_acronym).' '.e((string) ($doctor->council_number ?? ''))
            : '';
        $signature = $report->signed_manual_at !== null
            ? '<p class="assinatura-registrada">Assinatura manual registrada em '.$report->signed_manual_at->format('d/m/Y H:i').' — conferência pela recepção.</p>'
            : '<p class="assinatura-registrada">Assinatura manual pendente.</p>';
        $age = $patient?->birth_date !== null ? (int) now(config('sistema_dez.timezone'))->diffInYears($patient->birth_date) : null;

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Laudo</title>'
            .'<style>body{font-family:Arial,Helvetica,sans-serif;font-size:12pt;margin:32px}header{border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:12px}'
            .'header h1{margin:0;font-size:14pt}.cabecalho{font-size:10pt;color:#333;margin-bottom:12px}.assinatura-registrada{font-size:9pt;color:#555;margin-top:24px}'
            .'.laudo-adendo{border-top:1px dashed #666;margin-top:16px;padding-top:8px}@media print{.nao-imprimir{display:none}}</style></head><body>'
            .'<header><h1>Clínica DEZ</h1><p>Laudo médico</p></header>'
            .'<div class="cabecalho"><strong>Paciente:</strong> '.e((string) $patient?->name)
            .($age !== null ? ' &nbsp; <strong>Idade:</strong> '.$age : '')
            .($report->procedure?->name !== null ? ' &nbsp; <strong>Exame:</strong> '.e($report->procedure->name) : '')
            .($doctorName !== null ? ' &nbsp; <strong>Médico:</strong> '.e($doctorName).$doctorCouncil : '')
            .' &nbsp; <strong>Data:</strong> '.($report->finalized_at ?? $report->created_at)?->format('d/m/Y')
            .'</div>'
            .'<div class="laudo-conteudo">'.$report->content_html.'</div>'
            .$signature.'</body></html>';
    }
}
