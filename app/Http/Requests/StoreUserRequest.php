<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '氏名', 'email' => 'メールアドレス', 'role' => '役割'];
    }
}
