<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 旧 Gmail/Meet 連携で取り込んだメール・メモの最小限の記録（本文は持たない）。
 * 連携の撤去後は新しく作らず、既存の候補から元のメール・メモを開くリンクのためだけに残している。
 *
 * @property int $id
 * @property int $owner_id
 * @property string $kind mail（Gmail）／minutes（Meet のメモ）
 * @property string $external_id Gmail のメール ID、または Google ドキュメントの ID
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

    /** 取り込み元（Gmail のメール、または Meet のメモのドキュメント）を開くリンク */
    public function openUrl(): string
    {
        $account = $this->account_email ? rawurlencode($this->account_email) : '0';

        if ($this->kind === 'minutes') {
            return 'https://docs.google.com/document/d/'.rawurlencode($this->external_id)."/edit?authuser={$account}";
        }

        return "https://mail.google.com/mail/?authuser={$account}#all/{$this->external_id}";
    }
}
