<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 利用者本人の Meet のメモ（カレンダーの予定に付いたドキュメント）の連携。
 * 更新トークンは暗号化して保存し、画面・API には返さない。
 *
 * @property int $id
 * @property int $user_id
 * @property string $google_email
 * @property string $refresh_token
 * @property string $title_keywords
 * @property int $initial_days
 * @property bool $auto_sync
 * @property int $sync_interval_minutes
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $updated_at
 */
class MeetConnection extends Model
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'initial_days' => 'integer',
            'auto_sync' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * メモとみなす添付ドキュメントのタイトルに含まれる語。
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', str_replace('、', ',', $this->title_keywords))), fn (string $k) => $k !== ''));
    }
}
