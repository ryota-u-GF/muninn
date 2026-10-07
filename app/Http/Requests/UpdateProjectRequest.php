<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:'.config('gf.limits.project_name')],
            'sort_order' => ['sometimes', 'integer', 'between:-1000000,1000000'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '案件名'];
    }
}
