<?php

namespace App\Http\Requests;

use App\Enums\ExtractKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtractImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.config('gf.ai.max_text_chars')],
            // 文章の種類。省略時はメール。
            'kind' => ['sometimes', Rule::enum(ExtractKind::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['text' => '文章', 'kind' => '種類'];
    }
}
