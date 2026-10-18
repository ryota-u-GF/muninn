<?php

namespace App\Services\Gmail;

use RuntimeException;

/** 利用者に見せてよい文言だけを持つ（トークンやメール本文は含めない）。 */
class GmailException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $needsReconnect = false)
    {
        parent::__construct($message);
    }
}
