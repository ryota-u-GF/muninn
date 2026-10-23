<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPasswordRequest extends FormRequest
{
    /** パスワードの最低文字数 */
    public const MIN_LENGTH = 12;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:255', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => 'パスワード'];
    }
}
