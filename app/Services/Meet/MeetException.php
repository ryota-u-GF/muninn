<?php

namespace App\Services\Meet;

use RuntimeException;

/** 利用者に見せてよい文言だけを持つ（トークンやメモの本文は含めない）。 */
class MeetException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $needsReconnect = false)
    {
        parent::__construct($message);
    }
}
