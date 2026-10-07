<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** expected_revision 不一致（他の人が先に更新した） */
class RevisionConflictException extends RuntimeException
{
    public function __construct(public readonly ?int $currentRevision = null, string $message = '他の人が先に更新しました。最新の内容を確認してください。')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'conflict',
            'current_revision' => $this->currentRevision,
        ], 409);
    }
}
