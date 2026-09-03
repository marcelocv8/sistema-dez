<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProcedureType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTeamPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(ProcedureType::class)],
            'group' => ['nullable', 'string', 'max:255'],
            'agreement_id' => ['nullable', 'uuid', Rule::exists('agreements', 'id')->where('active', true)],
        ];
    }
}
