<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetProjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'project_id' => ['required', 'integer'],
            'permission' => ['required', 'in:view,edit,none'],
            'expected_permission' => ['required', 'in:view,edit,none'],
        ];
    }
}
