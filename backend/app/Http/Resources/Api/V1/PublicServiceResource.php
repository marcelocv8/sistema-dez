<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'group' => $this->group_name,
            'duration_minutes' => $this->duration_minutes,
            'offerings' => $this->serviceOfferings->map(fn ($offering) => [
                'id' => $offering->id,
                'unit' => ['id' => $offering->unit->id, 'name' => $offering->unit->name],
                'professional' => $offering->professional ? ['id' => $offering->professional->id, 'name' => $offering->professional->name] : null,
                'agreement' => ['id' => $offering->agreement->id, 'name' => $offering->agreement->name],
                'modality' => $offering->modality->value,
                'booking_mode' => $offering->booking_mode->value,
                'price' => $offering->price,
                'duration_minutes' => $offering->duration_override_minutes ?? $this->duration_minutes,
            ]),
        ];
    }
}
