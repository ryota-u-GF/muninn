<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** .txt / .md（UTF-8、400KB 以下、本文上限内）の取り込み */
class ParseTextFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.intdiv((int) config('gf.limits.upload_bytes'), 1024),
                'extensions:txt,md',
                'mimetypes:text/plain,text/markdown,text/x-markdown,application/octet-stream',
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => 'ファイル'];
    }
}
