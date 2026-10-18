<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 利用者本人の Gmail 連携。更新トークンは暗号化して保存し、画面・API には返さない。
 *
 * @property int $id
 * @property int $user_id
 * @property string $google_email
 * @property string $refresh_token
 * @property string $scope_mode
 * @property string|null $label_name
 * @property bool $exclude_promotions
 * @property int $initial_days
 * @property bool $auto_sync
 * @property int $sync_interval_minutes
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 */
class GmailConnection extends Model
{
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'exclude_promotions' => 'boolean',
            'initial_days' => 'integer',
            'auto_sync' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
