<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CatalogLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCatalogLinkRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('catalogLink')?->getKey();

        return [
            'unit_id' => ['required', Rule::exists('units', 'id')->where('active', true)],
            'procedure_id' => ['required', Rule::exists('procedures', 'id')->where('active', true)],
            'professional_id' => ['required', Rule::exists('professionals', 'id')->where('active', true)],
            'agreement_id' => ['required', Rule::exists('agreements', 'id')->where('active', true)],
            'active' => ['required', 'boolean'],
            'relationship' => [
                Rule::unique(CatalogLink::class, 'unit_id')
                    ->where('procedure_id', $this->input('procedure_id'))
                    ->where('professional_id', $this->input('professional_id'))
                    ->where('agreement_id', $this->input('agreement_id'))
                    ->ignore($id),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['relationship' => $this->input('unit_id')]);
    }
}
