<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 削除など、版の確認だけが必要な操作 */
class ExpectedRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_revision' => ['required', 'integer', 'min:1']];
    }
}
