<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 外部システムから届く候補。担当者・宛先はメールアドレスで指定する。
 * ここでは束ねた配列の形だけを確認し、1 件ごとの不備は受け取り側で「その 1 件だけ」断る（残りは取り込む）。
 * 案件・日付の不備は、候補の取り込み時に警告つきで未設定にする。
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
        return ['items' => ['required', 'array', 'min:1', 'max:'.config('gf.limits.import_items')]];
    }

    /**
     * 候補 1 件分の入力規則。
     *
     * @return array<string, mixed>
     */
    public static function itemRules(): array
    {
        $limits = config('gf.limits');

        return [
            'external_id' => ['required', 'string', 'max:200'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            'title' => ['required', 'string', 'max:'.$limits['title']],
            'description' => ['nullable', 'string', 'max:'.$limits['task_description']],
            'project_id' => ['nullable', 'integer'],
            'assignee_emails' => ['nullable', 'array', 'max:20'],
            'assignee_emails.*' => ['string', 'max:255'],
            'source_url' => ['nullable', 'string', 'url:https', 'max:2048'],
            'start_date' => ['nullable', 'string', 'max:20'],
            'due_date' => ['nullable', 'string', 'max:20'],
            'source_type' => ['nullable', 'string', 'max:20'],
        ];
    }
}
