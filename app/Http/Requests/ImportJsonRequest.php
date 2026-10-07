<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 手動 JSON 取り込み。候補配列（title, description, project_id, assignee_ids, start_date, due_date, source_type）。
 * 文字列の JSON テキストも受け付ける。
 */
class ImportJsonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['json' => ['required', 'string', 'max:200000']];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $items = $this->items();
            if ($items === null) {
                $validator->errors()->add('json', 'JSON の形式が正しくありません。候補の配列を指定してください。');

                return;
            }
            if (count($items) === 0 || count($items) > config('gf.limits.import_items')) {
                $validator->errors()->add('json', '候補は 1〜'.config('gf.limits.import_items').' 件で指定してください。');
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || array_is_list($item)) {
                    $validator->errors()->add('json', ($i + 1).' 件目がオブジェクトではありません。');
                }
            }
        }];
    }

    /** @return list<mixed>|null */
    public function items(): ?array
    {
        $decoded = json_decode((string) $this->input('json'), true);
        if (is_array($decoded) && isset($decoded['candidates']) && is_array($decoded['candidates'])) {
            $decoded = $decoded['candidates'];
        }

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }
}
