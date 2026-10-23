<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasswordLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['email' => 'メールアドレス', 'password' => 'パスワード'];
    }
}
