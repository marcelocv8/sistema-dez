<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Modality;
use App\Enums\ProcedureType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublicServicesRequest extends FormRequest
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
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
            'modality' => ['nullable', Rule::enum(Modality::class)],
            'type' => ['nullable', Rule::enum(ProcedureType::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
