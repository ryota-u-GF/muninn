<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:'.config('gf.limits.title')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('gf.limits.task_description')],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'visibility' => ['sometimes', 'in:shared,private'],
            'assignee_ids' => ['sometimes', 'array', 'max:20'],
            'assignee_ids.*' => ['integer', 'distinct'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
