<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
