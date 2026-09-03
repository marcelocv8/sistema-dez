<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Modality;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListAvailabilityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'procedure_id' => ['required', 'uuid', 'exists:procedures,id'],
            'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'agreement_id' => ['required', 'uuid', 'exists:agreements,id'],
            'modality' => ['required', Rule::enum(Modality::class)],
            'professional_id' => ['nullable', 'uuid', 'exists:professionals,id'],
            'offering_id' => ['nullable', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $days = $this->date('from')->startOfDay()->diffInDays($this->date('to')->startOfDay());
            $today = CarbonImmutable::today(config('sistema_dez.timezone'));
            if ($this->input('from') < $today->toDateString() || $this->input('to') > $today->addDays(config('sistema_dez.public_booking_horizon_days') - 1)->toDateString()) {
                $validator->errors()->add('to', 'Escolha datas dentro dos próximos 30 dias.');
            }
            if ($days >= config('sistema_dez.public_booking_horizon_days')) {
                $validator->errors()->add('to', 'O período consultado não pode ultrapassar 30 dias.');
            }
        }];
    }
}
