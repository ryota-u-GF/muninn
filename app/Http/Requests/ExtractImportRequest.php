<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtractImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:'.config('gf.ai.max_text_chars')]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['text' => '文章'];
    }
}
