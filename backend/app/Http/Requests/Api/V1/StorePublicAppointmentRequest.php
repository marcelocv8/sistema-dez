<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicAppointmentRequest extends FormRequest
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
            'idempotency_key' => ['required', 'uuid'],
            'offering_id' => ['required', 'uuid', 'exists:service_offerings,id'],
            'starts_at' => ['required', 'date'],
            'patient.name' => ['required', 'string', 'max:150'],
            'patient.cpf' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->isValidCpf((string) $value)) {
                    $fail('O CPF informado é inválido.');
                }
            }],
            'patient.birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'patient.phone' => ['required', 'string', 'max:30'],
            'patient.email' => ['nullable', 'email', 'max:254'],
            'privacy_consent' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('patient.cpf')) {
            $this->merge(['patient' => [
                ...$this->input('patient', []),
                'cpf' => preg_replace('/\D/', '', (string) $this->input('patient.cpf')),
            ]]);
        }
    }

    private function isValidCpf(string $cpf): bool
    {
        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $position) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += ((int) $cpf[$index]) * ($position + 1 - $index);
            }
            $digit = ($sum * 10) % 11;
            if ($digit === 10) {
                $digit = 0;
            }
            if ($digit !== (int) $cpf[$position]) {
                return false;
            }
        }

        return true;
    }
}
