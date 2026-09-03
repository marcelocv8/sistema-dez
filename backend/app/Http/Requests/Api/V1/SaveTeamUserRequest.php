<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveTeamUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('teamUser');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->getKey())],
            'role' => ['required', Rule::enum(UserRole::class)],
            'active' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)->letters()->numbers()],
        ];
    }
}
