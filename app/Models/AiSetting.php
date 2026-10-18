<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 利用者ごとの AI（Claude）設定。API キーは暗号化して保存し、画面・API には返さない。
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $api_key
 * @property string $model
 */
class AiSetting extends Model
{
    protected $table = 'user_ai_settings';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    /** 画面に出す目印（末尾4文字のみ） */
    public function keyHint(): string
    {
        return '…'.substr($this->api_key, -4);
    }
}
