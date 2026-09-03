<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ImportIssueResolutionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveImportIssueRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_status' => ['required', Rule::in(ImportIssueResolutionStatus::values())],
            'resolution_note' => ['required_unless:resolution_status,OPEN', 'nullable', 'string', 'max:2000'],
        ];
    }
}
