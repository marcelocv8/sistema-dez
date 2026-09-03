<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ServiceOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicAppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = config('sistema_dez.timezone');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at->setTimezone($timezone)->toIso8601String(),
            'recommended_arrival_at' => $this->recommended_arrival_at?->setTimezone($timezone)->toIso8601String(),
            'booking_mode' => $this->serviceOffering->booking_mode->value,
            'service' => [
                'offering_id' => $this->service_offering_id,
                'procedure_id' => $this->serviceOffering->procedure_id,
                'procedure_name' => $this->serviceOffering->procedure->name,
            ],
            'price' => $this->price_snapshot,
            'management_token' => $this->when($this->resource->getAttribute('management_token') !== null, $this->resource->getAttribute('management_token')),
            'management_expires_at' => $this->management_expires_at?->toIso8601String(),
            'can_cancel' => $this->status === 'CONFIRMED' && $this->scheduled_at->isFuture(),
            'can_reschedule' => $this->status === 'CONFIRMED' && $this->scheduled_at->isFuture()
                && ServiceOffering::query()->whereKey($this->service_offering_id)->publiclyAvailable()->exists(),
            'unit' => ['id' => $this->serviceOffering->unit->id, 'name' => $this->serviceOffering->unit->name],
            'professional' => $this->serviceOffering->professional?->only(['id', 'name']),
            'agreement' => $this->serviceOffering->agreement->only(['id', 'name']),
        ];
    }
}
