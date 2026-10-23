<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 外部システムから届く候補。担当者・宛先はメールアドレスで指定する。
 * 案件・日付の不備は、候補の取り込み時に警告つきで未設定にする（ここでは弾かない）。
 */
class InboundCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $limits = config('gf.limits');

        return [
            'items' => ['required', 'array', 'min:1', 'max:'.$limits['import_items']],
            'items.*' => ['required', 'array'],
            'items.*.external_id' => ['required', 'string', 'max:200'],
            'items.*.owner_email' => ['required', 'string', 'email', 'max:255'],
            'items.*.title' => ['required', 'string', 'max:'.$limits['title']],
            'items.*.description' => ['nullable', 'string', 'max:'.$limits['task_description']],
            'items.*.project_id' => ['nullable', 'integer'],
            'items.*.assignee_emails' => ['nullable', 'array', 'max:20'],
            'items.*.assignee_emails.*' => ['string', 'max:255'],
            'items.*.source_url' => ['nullable', 'string', 'url:https', 'max:2048'],
            'items.*.start_date' => ['nullable', 'string', 'max:20'],
            'items.*.due_date' => ['nullable', 'string', 'max:20'],
            'items.*.source_type' => ['nullable', 'string', 'max:20'],
        ];
    }
}
