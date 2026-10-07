<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
