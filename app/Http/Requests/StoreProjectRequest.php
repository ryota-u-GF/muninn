<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:'.config('gf.limits.project_name')]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => '案件名'];
    }
}
