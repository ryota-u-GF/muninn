<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 権限は Policy / Action で確認する
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.config('gf.limits.title')],
            'description' => ['nullable', 'string', 'max:'.config('gf.limits.task_description')],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'project_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'assignee_ids' => ['sometimes', 'array', 'max:20'],
            'assignee_ids.*' => ['integer', 'distinct'],
            'sort_order' => ['sometimes', 'numeric', 'between:-1000000000,1000000000'],
            'source_type' => ['nullable', 'string', 'max:30'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return TaskAttributes::NAMES;
    }
}
