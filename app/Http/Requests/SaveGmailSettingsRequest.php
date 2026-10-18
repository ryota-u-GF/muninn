<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_mode' => ['required', 'in:inbox,label'],
            'label_name' => ['nullable', 'required_if:scope_mode,label', 'string', 'max:100'],
            'exclude_promotions' => ['required', 'boolean'],
            'initial_days' => ['required', 'integer', Rule::in(config('gf.gmail.initial_days_options'))],
            'auto_sync' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['scope_mode' => '取り込み対象', 'label_name' => 'ラベル名', 'initial_days' => '初回の取得期間'];
    }
}
