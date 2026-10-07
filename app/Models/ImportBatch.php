<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property array<string, mixed>|null $result
 */
class ImportBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
