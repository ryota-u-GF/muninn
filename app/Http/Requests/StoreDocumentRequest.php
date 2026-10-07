<?php

namespace App\Http\Requests;

use App\Enums\DocumentKind;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'project_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:'.config('gf.limits.title')],
            'body' => ['nullable', 'string', 'max:'.config('gf.limits.document_body')],
            'meeting_date' => ['nullable', 'required_if:kind,minutes', 'date_format:Y-m-d'],
            'participants' => ['nullable', 'string', 'max:'.config('gf.limits.participants')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return TaskAttributes::NAMES;
    }
}
