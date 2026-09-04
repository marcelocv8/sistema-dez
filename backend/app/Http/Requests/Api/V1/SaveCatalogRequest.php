<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCatalogRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $id = $this->route('id');
        $table = match ($resource) {
            'units' => 'units',
            'agreements' => 'agreements',
            'procedures' => 'procedures',
            'professionals' => 'professionals',
            default => 'invalid_resource',
        };
        $common = [
            'legacy_id' => ['nullable', 'string', 'max:80', Rule::unique($table, 'legacy_id')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ];

        return match ($resource) {
            'units' => $common + [
                'operational_enabled' => ['sometimes', 'boolean'],
                'timezone' => ['required', 'timezone:all'],
                'cnpj' => ['nullable', 'digits:14', Rule::unique('units', 'cnpj')->ignore($id)],
            ],
            'agreements' => $common + [
                'requires_authorization' => ['sometimes', 'boolean'],
                'type' => ['required', Rule::in(['PRIVATE', 'AGREEMENT', 'B2B', 'HEALTH_PLAN'])],
                'is_default' => ['required', 'boolean'],
                'online_enabled' => ['required', 'boolean'],
            ],
            'procedures' => $common + [
                'type' => ['required', Rule::in(['CONSULTATION', 'EXAM'])],
                'group_name' => ['nullable', 'string', 'max:255'],
                'tuss_code' => ['nullable', 'string', 'max:30'],
                'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
                'legacy' => ['required', 'boolean'],
                'online_enabled' => ['required', 'boolean'],
            ],
            'professionals' => $common + [
                'council_acronym' => ['nullable', 'string', 'max:20'],
                'council_number' => ['nullable', 'string', 'max:40'],
                'commission_type' => ['required', Rule::in(['PERCENT', 'FIXED'])],
                'commission_value' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('professionals', 'user_id')->ignore($id)],
                'online_enabled' => ['required', 'boolean'],
            ],
            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((string) $this->route('resource') !== 'professionals') {
                return;
            }
            if (($this->input('commission_type') === 'PERCENT') && (float) $this->input('commission_value', 0) > 100) {
                $validator->errors()->add('commission_value', 'Percentual de comissão não pode passar de 100.');
            }
        });
    }
}
