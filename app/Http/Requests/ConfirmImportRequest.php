<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:'.config('gf.limits.import_items')],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
