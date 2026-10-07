<?php

namespace App\Http\Requests;

use App\Enums\DocumentKind;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'kind' => ['sometimes', Rule::enum(DocumentKind::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:'.config('gf.limits.title')],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.config('gf.limits.document_body')],
            'meeting_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'participants' => ['sometimes', 'nullable', 'string', 'max:'.config('gf.limits.participants')],
        ];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return collect($this->validated())->except('expected_revision')->all();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return TaskAttributes::NAMES;
    }
}
