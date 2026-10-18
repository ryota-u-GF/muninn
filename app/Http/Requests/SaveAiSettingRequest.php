<?php

namespace App\Http\Requests;

use App\Models\AiSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hasKey = AiSetting::query()->where('user_id', $this->user()->id)->exists();

        return [
            // 初回は必須。登録済みなら、空のままモデルだけ変更できる。
            'api_key' => [$hasKey ? 'nullable' : 'required', 'string', 'min:20', 'max:300', 'starts_with:sk-ant-'],
            'model' => ['required', 'string', Rule::in(array_keys(config('gf.ai.models')))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['api_key' => 'APIキー', 'model' => 'モデル'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['api_key.starts_with' => 'APIキーは「sk-ant-」で始まる文字列です。'];
    }
}
