<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PortalAccess;
use App\Models\ProntuarioEvent;
use App\Models\Report;
use App\Models\ReportEvent;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function publish(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== Report::STATUS_FINALIZADO) {
            throw ValidationException::withMessages(['report' => 'Somente laudos finalizados podem ser publicados no portal.']);
        }
        $data = $request->validate([
            'force_new_credential' => ['nullable', 'boolean'],
        ]);

        [$report, $password] = $this->publishReport($request, $report, (bool) ($data['force_new_credential'] ?? false));

        return response()->json(['data' => $this->serializePublication($report, $password)]);
    }

    public function folha(Request $request, Report $report): JsonResponse
    {
        abort_if($report->portal_published_at === null, 404, 'Laudo não publicado no portal.');

        return response()->json(['data' => $this->serializePublication($report, null)]);
    }

    public function revokePublication(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if ($report->portal_published_at === null || $report->portal_unpublished_at !== null) {
            throw ValidationException::withMessages(['report' => 'Laudo não está publicado no portal.']);
        }
        $before = $report->attributesToArray();
        $report->forceFill(['portal_unpublished_at' => now()])->save();
        $report->events()->create(['event' => ReportEvent::UNPUBLISHED, 'by_user_id' => $request->user()->id, 'payload' => ['reason' => $data['reason']]]);
        ProntuarioEvent::query()->create([
            'patient_id' => $report->patient_id, 'appointment_id' => $report->appointment_id, 'subject_id' => $report->id,
            'event' => ProntuarioEvent::PUBLISHED, 'by_user_id' => $request->user()->id,
            'payload' => ['action' => 'REVOGADO', 'reason' => $data['reason']],
        ]);
        $this->audit->record($request, $report, 'report.portal.unpublished', $before, $report->attributesToArray());

        return response()->json(['data' => ['id' => $report->id, 'portal_published' => false]]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:30'],
        ]);
        $access = PortalAccess::query()->where('login', mb_strtolower($data['login']))->first();
        if ($access === null || ! $access->matchesPassword($data['password']) || ! $access->isActive()) {
            throw ValidationException::withMessages(['login' => 'Credencial inválida, expirada ou revogada.']);
        }
        $token = $access->issueToken();

        return response()->json(['data' => [
            'token' => $token,
            'expires_at' => $access->expires_at->toIso8601String(),
            'patient_name' => $access->patient?->name,
        ]]);
    }

    public function results(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? (string) $request->header('X-Portal-Token');
        abort_if($token === '', 401, 'Autentique-se no portal.');
        $access = PortalAccess::query()->where('token_hash', hash('sha256', $token))->first();
        if ($access === null || ! $access->isActive()) {
            abort(401, 'Credencial inválida, expirada ou revogada.');
        }
        $access->forceFill(['access_count' => $access->access_count + 1, 'last_accessed_at' => now()])->save();

        $reports = Report::query()->with(['procedure:id,name', 'doctor.user:id,name'])
            ->where('portal_access_id', $access->id)
            ->where('status', Report::STATUS_FINALIZADO)
            ->whereNotNull('portal_published_at')
            ->whereNull('portal_unpublished_at')
            ->orderByDesc('finalized_at')->get();

        return response()->json(['data' => [
            'patient_name' => $access->patient?->name,
            'expires_at' => $access->expires_at->toIso8601String(),
            'results' => $reports->map(fn (Report $report): array => [
                'id' => $report->id,
                'procedure' => $report->procedure?->only(['id', 'name']),
                'doctor' => $report->doctor?->user?->only(['id', 'name']),
                'finalized_at' => $report->finalized_at?->toIso8601String(),
                'signed_manual' => $report->signed_manual_at !== null,
                'content_html' => $report->content_html,
            ])->all(),
        ]]);
    }

    /**
     * Publica o laudo no portal, reutilizando credencial ativa do paciente
     * (ou emitindo uma nova quando não existe / force_new_credential).
     *
     * @return array{0: Report, 1: string|null}
     */
    private function publishReport(Request $request, Report $report, bool $forceNew): array
    {
        $password = null;
        $access = null;
        if (! $forceNew) {
            $access = PortalAccess::query()
                ->where('patient_id', $report->patient_id)
                ->whereNull('revoked_at')->where('expires_at', '>', now())
                ->orderByDesc('created_at')->first();
        }
        if ($access === null) {
            $patient = Patient::query()->findOrFail($report->patient_id);
            /** @var array{access: PortalAccess, password: string} $issued */
            $issued = PortalAccess::issue($patient, (int) config('sistema_dez.portal.ttl_days'), (int) $request->user()->id);
            $access = $issued['access'];
            $password = $issued['password'];
        }

        $before = $report->attributesToArray();
        $report->forceFill([
            'portal_access_id' => $access->id,
            'portal_published_at' => now(),
            'portal_published_by' => $request->user()->id,
            'portal_unpublished_at' => null,
        ])->save();
        $report->events()->create(['event' => ReportEvent::PUBLISHED, 'by_user_id' => $request->user()->id, 'payload' => ['portal_access_id' => $access->id]]);
        ProntuarioEvent::query()->create([
            'patient_id' => $report->patient_id, 'appointment_id' => $report->appointment_id, 'subject_id' => $report->id,
            'event' => ProntuarioEvent::PUBLISHED, 'by_user_id' => $request->user()->id, 'payload' => ['portal_access_id' => $access->id],
        ]);
        $this->audit->record($request, $report, 'report.portal.published', $before, $report->attributesToArray());

        return [$report, $password];
    }

    /** @return array<string, mixed> */
    private function serializePublication(Report $report, ?string $password): array
    {
        $access = $report->portalAccess()->first();
        $baseUrl = rtrim((string) config('sistema_dez.portal.base_url'), '/');
        $portalUrl = $baseUrl.config('sistema_dez.portal.path');

        return [
            'report_id' => $report->id,
            'portal_published' => $report->portal_published_at !== null && $report->portal_unpublished_at === null,
            'portal_published_at' => $report->portal_published_at?->toIso8601String(),
            'login' => $access?->login,
            'password' => $password,
            'expires_at' => $access?->expires_at?->toIso8601String(),
            'portal_url' => $portalUrl,
            'folha_html' => $this->folhaHtml($report, $access, $portalUrl, $password),
        ];
    }

    private function folhaHtml(Report $report, ?PortalAccess $access, string $portalUrl, ?string $password): string
    {
        $qrNote = 'QR Code: em breve (pendente) — use a URL abaixo.';
        $passwordLine = $password !== null
            ? '<strong>Senha:</strong> '.e($password)
            : '<strong>Senha:</strong> a mesma entregue na primeira folha (se perdida, gere uma nova credencial).';

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Folha de acesso ao resultado</title>'
            .'<style>body{font-family:Arial,Helvetica,sans-serif;font-size:12pt;margin:32px}header{border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:12px}'
            .'header h1{margin:0;font-size:14pt}.box{border:1px solid #333;border-radius:8px;padding:16px;margin:16px 0;font-size:12pt}'
            .'.box p{margin:6px 0}.muted{color:#555;font-size:10pt;margin-top:24px}</style></head><body>'
            .'<header><h1>Clínica DEZ</h1><p>Folha de acesso ao resultado do exame</p></header>'
            .'<div class="box">'
            .'<p><strong>Resultado:</strong> '.e((string) ($report->procedure?->name ?? 'Laudo médico')).'</p>'
            .'<p><strong>Acesse:</strong> '.e($portalUrl).'</p>'
            .'<p><strong>Login:</strong> '.e((string) $access?->login).'</p>'
            .'<p>'.$passwordLine.'</p>'
            .'<p><strong>Validade:</strong> '.$access?->expires_at?->format('d/m/Y').'</p>'
            .'<p class="muted">'.$qrNote.' A senha é pessoal e intransferível; após o prazo o acesso é encerrado.</p>'
            .'</div></body></html>';
    }
}
