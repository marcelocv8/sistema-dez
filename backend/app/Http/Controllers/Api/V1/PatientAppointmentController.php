<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\BuildAvailability;
use App\Actions\Scheduling\ChangeAppointment;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicAppointmentResource;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PatientAppointmentController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->authorizeToken($request, $id), $request);
    }

    public function availability(Request $request, string $id, BuildAvailability $availability): JsonResponse
    {
        $appointment = $this->authorizeToken($request, $id);
        $offering = $appointment->serviceOffering;
        $today = CarbonImmutable::today($offering->unit->timezone);
        $rows = $availability->handle(['offering_id' => $offering->id, 'exclude_appointment_id' => $appointment->id,
            'procedure_id' => $offering->procedure_id, 'unit_id' => $offering->unit_id, 'agreement_id' => $offering->agreement_id,
            'modality' => $offering->modality->value, 'from' => $today->toDateString(),
            'to' => $today->addDays(config('sistema_dez.public_booking_horizon_days') - 1)->toDateString()]);

        return response()->json(['data' => $rows])->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function reschedule(Request $request, string $id, ChangeAppointment $change): JsonResponse
    {
        $appointment = $this->authorizeToken($request, $id);
        $request->validate(['offering_id' => ['prohibited']]);
        $request->merge(['offering_id' => $appointment->service_offering_id]);

        return $this->respond($change->reschedule($request, $appointment, true), $request);
    }

    public function cancel(Request $request, string $id, ChangeAppointment $change): JsonResponse
    {
        $appointment = $this->authorizeToken($request, $id);

        return $this->respond($change->cancel($request, $appointment, true), $request);
    }

    private function authorizeToken(Request $request, string $id): Appointment
    {
        $token = $request->bearerToken();
        abort_unless(Str::isUuid($id) && is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token), 404, 'Link inválido ou expirado.');
        $appointment = Appointment::query()->whereKey($id)->where('origin', 'ONLINE')->first();
        abort_unless($appointment && $appointment->management_expires_at?->isFuture()
            && hash_equals((string) $appointment->management_token_hash, hash('sha256', $token)), 404, 'Link inválido ou expirado.');

        return $appointment;
    }

    private function respond(Appointment $appointment, Request $request): JsonResponse
    {
        return (new PublicAppointmentResource($appointment->load('serviceOffering.procedure', 'serviceOffering.unit', 'serviceOffering.professional', 'serviceOffering.agreement')))
            ->response()->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
