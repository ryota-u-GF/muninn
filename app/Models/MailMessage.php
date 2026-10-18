<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 取り込み済みメールの最小限の記録（本文は持たない）。
 *
 * @property int $id
 * @property int $owner_id
 * @property string $gmail_message_id
 * @property string|null $account_email
 * @property string|null $subject
 * @property string|null $sender
 * @property CarbonImmutable|null $received_at
 * @property string $status
 * @property int $candidate_count
 */
class MailMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'candidate_count' => 'integer'];
    }

    /** Gmail でこのメールを開くリンク */
    public function gmailUrl(): string
    {
        $account = $this->account_email ? rawurlencode($this->account_email) : '0';

        return "https://mail.google.com/mail/?authuser={$account}#all/{$this->gmail_message_id}";
    }
}
