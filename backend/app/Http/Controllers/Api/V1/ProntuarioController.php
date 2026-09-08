<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\ProntuarioEvent;
use App\Models\Report;
use App\Services\AiAssistance;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProntuarioController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit, private readonly AiAssistance $ai) {}

    public function timeline(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
        ]);
        $patient = Patient::query()->findOrFail($data['patient_id']);

        $notes = $patient->clinicalNotes()->with(['createdBy:id,name', 'updatedBy:id,name', 'professional.user:id,name'])
            ->orderByDesc('created_at')->limit(50)->get();
        $reports = Report::query()->with(['procedure:id,name', 'doctor.user:id,name'])
            ->where('patient_id', $patient->id)->orderByDesc('created_at')->limit(50)->get();
        $appointments = $patient->appointments()->with([
            'serviceOffering.procedure:id,name', 'serviceOffering.professional:id,name',
            'serviceOffering.unit:id,name', 'paymentMethod:id,code,name', 'cashMovement:id,receipt_number',
        ])->orderByDesc('scheduled_at')->limit(30)->get();

        ProntuarioEvent::query()->create([
            'patient_id' => $patient->id,
            'event' => ProntuarioEvent::VIEWED,
            'by_user_id' => $request->user()->id,
            'payload' => null,
        ]);

        return response()->json(['data' => [
            'patient' => ['id' => $patient->id, 'name' => $patient->name, 'birth_date' => $patient->birth_date?->toDateString()],
            'alerts' => $patient->alerts()->with(['createdBy:id,name', 'deactivatedBy:id,name'])
                ->orderByDesc('active')->orderByDesc('created_at')->limit(50)->get()
                ->map(fn (PatientAlert $alert): array => $this->serializeAlert($alert))->all(),
            'notes' => $notes->map(fn (ClinicalNote $note): array => $this->serializeNote($note))->all(),
            'reports' => $reports->map(fn (Report $report): array => [
                'id' => $report->id,
                'procedure' => $report->procedure?->only(['id', 'name']),
                'doctor' => $report->doctor?->user?->only(['id', 'name']),
                'status' => $report->status,
                'signed_manual' => $report->signed_manual_at !== null,
                'print_count' => $report->print_count,
                'portal_published' => $report->portal_published_at !== null && $report->portal_unpublished_at === null,
                'finalized_at' => $report->finalized_at?->toIso8601String(),
                'created_at' => $report->created_at?->toIso8601String(),
            ])->all(),
            'appointments' => $appointments->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => $appointment->status,
                'kind' => $appointment->kind,
                'origin' => $appointment->origin,
                'procedure' => $appointment->serviceOffering?->procedure?->only(['id', 'name']),
                'professional' => $appointment->serviceOffering?->professional?->only(['id', 'name']),
                'unit' => $appointment->serviceOffering?->unit?->only(['id', 'name']),
                'payment_status' => $appointment->payment_status,
                'payment_method' => $appointment->paymentMethod?->only(['id', 'name']),
                'receipt_number' => $appointment->cashMovement?->receipt_number,
            ])->all(),
        ]]);
    }

    public function storeNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => ['required', 'uuid', 'exists:appointments,id'],
            'kind' => ['nullable', Rule::in([ClinicalNote::KIND_ANAMNESE, ClinicalNote::KIND_EVOLUCAO, ClinicalNote::KIND_INTERCORRENCIA, ClinicalNote::KIND_ALTA])],
            'content_html' => ['required', 'string', 'min:3', 'max:2000000'],
        ]);

        $note = ClinicalNote::query()->create([
            'appointment_id' => $data['appointment_id'],
            'patient_id' => Appointment::query()->whereKey($data['appointment_id'])->value('patient_id'),
            'professional_id' => $request->user()->professional?->id,
            'kind' => $data['kind'] ?? ClinicalNote::KIND_EVOLUCAO,
            'content_html' => $data['content_html'],
            'created_by' => $request->user()->id,
            'retention_until' => now()->addYears(20)->toDateString(),
        ]);
        $this->logEvent($note->patient_id, $note->appointment_id, $note->id, ProntuarioEvent::NOTE_CREATED, $request);
        $this->audit->record($request, $note, 'prontuario.note.created', null, $note->attributesToArray());

        return response()->json(['data' => $this->serializeNote($note->fresh(['createdBy:id,name']))], 201);
    }

    public function updateNote(Request $request, ClinicalNote $clinicalNote): JsonResponse
    {
        $data = $request->validate([
            'content_html' => ['required', 'string', 'min:3', 'max:2000000'],
        ]);
        $before = $clinicalNote->attributesToArray();
        $clinicalNote->forceFill(['content_html' => $data['content_html'], 'updated_by' => $request->user()->id])->save();
        $this->logEvent($clinicalNote->patient_id, $clinicalNote->appointment_id, $clinicalNote->id, ProntuarioEvent::NOTE_UPDATED, $request);
        $this->audit->record($request, $clinicalNote, 'prontuario.note.updated', $before, $clinicalNote->attributesToArray());

        return response()->json(['data' => $this->serializeNote($clinicalNote->fresh(['createdBy:id,name', 'updatedBy:id,name']))]);
    }

    public function addAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'kind' => ['nullable', Rule::in([PatientAlert::KIND_ALERGIA, PatientAlert::KIND_CONDICAO, PatientAlert::KIND_OUTRO])],
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'detail' => ['nullable', 'string', 'max:500'],
        ]);
        $alert = PatientAlert::query()->create([
            'patient_id' => $data['patient_id'],
            'kind' => $data['kind'] ?? PatientAlert::KIND_ALERGIA,
            'title' => $data['title'],
            'detail' => $data['detail'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $this->logEvent($data['patient_id'], null, $alert->id, ProntuarioEvent::ALERT_ADDED, $request);
        $this->audit->record($request, $alert, 'prontuario.alert.added', null, $alert->attributesToArray());

        return response()->json(['data' => $this->serializeAlert($alert->fresh(['createdBy:id,name']))], 201);
    }

    public function deactivateAlert(Request $request, PatientAlert $patientAlert): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $before = $patientAlert->attributesToArray();
        $patientAlert->forceFill([
            'active' => false,
            'deactivated_by' => $request->user()->id,
            'deactivated_at' => now(),
            'deactivation_reason' => $data['reason'],
        ])->save();
        $this->logEvent($patientAlert->patient_id, null, $patientAlert->id, ProntuarioEvent::ALERT_DEACTIVATED, $request);
        $this->audit->record($request, $patientAlert, 'prontuario.alert.deactivated', $before, $patientAlert->attributesToArray());

        return response()->json(['data' => $this->serializeAlert($patientAlert->fresh(['deactivatedBy:id,name']))]);
    }

    public function aiStatus(Request $request): JsonResponse
    {
        return response()->json(['data' => ['enabled' => $this->ai->enabled(), 'model' => $this->ai->model()]]);
    }

    public function aiSuggest(Request $request): JsonResponse
    {
        if (! $this->ai->enabled()) {
            return response()->json(['message' => 'IA não configurada nesta instalação (DEEPSEEK_API_KEY ausente).'], 503);
        }
        $data = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'uuid', 'exists:appointments,id'],
            'question' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $patient = Patient::query()->findOrFail($data['patient_id']);
        $appointment = isset($data['appointment_id']) ? Appointment::query()->findOrFail($data['appointment_id']) : null;

        ['ai_request' => $aiRequest, 'response_text' => $responseText] = $this->ai->suggest($patient, $appointment, $data['question'], (int) $request->user()->id);
        $this->logEvent($patient->id, $appointment?->id, $aiRequest->id, ProntuarioEvent::AI_REQUESTED, $request);
        $this->audit->record($request, $aiRequest, 'prontuario.ai.requested', null, ['ai_request_id' => $aiRequest->id, 'status' => $aiRequest->status]);

        if ($responseText === null) {
            throw ValidationException::withMessages(['ai' => 'A IA não respondeu: '.($aiRequest->error_message ?? 'erro desconhecido.')]);
        }

        return response()->json(['data' => [
            'ai_request_id' => $aiRequest->id,
            'model' => $aiRequest->model,
            'suggestion_html' => AiAssistance::MARKER.nl2br(e($responseText)),
        ]]);
    }

    private function logEvent(string $patientId, ?string $appointmentId, ?string $subjectId, string $event, Request $request): void
    {
        ProntuarioEvent::query()->create([
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'subject_id' => $subjectId,
            'event' => $event,
            'by_user_id' => $request->user()->id,
            'payload' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeNote(ClinicalNote $note): array
    {
        return [
            'id' => $note->id,
            'appointment_id' => $note->appointment_id,
            'kind' => $note->kind,
            'content_html' => $note->content_html,
            'doctor' => $note->relationLoaded('professional') && $note->professional !== null ? $note->professional->user?->only(['id', 'name']) : null,
            'created_by' => $note->relationLoaded('createdBy') ? $note->createdBy?->only(['id', 'name']) : null,
            'updated_by' => $note->relationLoaded('updatedBy') ? $note->updatedBy?->only(['id', 'name']) : null,
            'retention_until' => $note->retention_until?->toDateString(),
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAlert(PatientAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'kind' => $alert->kind,
            'title' => $alert->title,
            'detail' => $alert->detail,
            'active' => $alert->active,
            'created_by' => $alert->relationLoaded('createdBy') ? $alert->createdBy?->only(['id', 'name']) : null,
            'deactivated_by' => $alert->relationLoaded('deactivatedBy') ? $alert->deactivatedBy?->only(['id', 'name']) : null,
            'deactivated_at' => $alert->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $alert->deactivation_reason,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
