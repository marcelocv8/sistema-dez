<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Report;
use Illuminate\Validation\ValidationException;

class ReportEligibility
{
    public static function assertCanComplete(Report $report): void
    {
        $appointment = Appointment::query()->lockForUpdate()->find($report->appointment_id);
        if ($report->purged_at !== null || $appointment === null || ! in_array($appointment->status, ['CHECKED_IN', 'DONE'], true)
            || ! in_array($appointment->payment_status, ['PAID', 'AUTHORIZED'], true) || $appointment->care_started_at === null) {
            throw ValidationException::withMessages(['report' => 'Finalização/publicação exige atendimento iniciado, não cancelado e pago ou autorizado B2B.']);
        }
    }
}
