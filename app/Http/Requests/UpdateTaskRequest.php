<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:'.config('gf.limits.title')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.config('gf.limits.task_description')],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assignee_ids' => ['sometimes', 'array', 'max:20'],
            'assignee_ids.*' => ['integer', 'distinct'],
            'sort_order' => ['sometimes', 'numeric', 'between:-1000000000,1000000000'],
            'source_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'source_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
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
