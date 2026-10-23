<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SetPasswordRequest extends FormRequest
{
    /** パスワードの最低文字数 */
    public const MIN_LENGTH = 12;

    /** bcrypt が先頭 72 バイトまでしか見ないため、それを超える入力は受け付けない */
    public const MAX_BYTES = 72;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'password' => [
                'required',
                'string',
                'min:'.self::MIN_LENGTH,
                'confirmed',
                fn (string $attribute, mixed $value, Closure $fail) => self::bytesError($value) !== null ? $fail(self::bytesError($value)) : null,
            ],
        ];
    }

    /** 72 バイトを超えるときのエラー文言（コマンドからも使う） */
    public static function bytesError(mixed $value): ?string
    {
        return is_string($value) && strlen($value) > self::MAX_BYTES
            ? 'パスワードは'.self::MAX_BYTES.'バイト以内にしてください（日本語は1文字3バイトです）。'
            : null;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['password' => 'パスワード'];
    }
}
