<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMeetSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 空のときは、予定に付いたドキュメントをすべてメモとみなす
            'title_keywords' => ['nullable', 'string', 'max:200'],
            'initial_days' => ['required', 'integer', Rule::in(config('gf.gmail.initial_days_options'))],
            'auto_sync' => ['required', 'boolean'],
            'sync_interval_minutes' => ['required', 'integer', Rule::in(config('gf.gmail.sync_interval_options'))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title_keywords' => 'メモのタイトルに含む語', 'initial_days' => '初回の取得期間', 'sync_interval_minutes' => '取得の間隔'];
    }
}
