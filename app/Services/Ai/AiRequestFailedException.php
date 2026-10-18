<?php

namespace App\Services\Ai;

use RuntimeException;

/** 利用者に見せてよい文言だけを持つ（API キーや本文は含めない）。 */
class AiRequestFailedException extends RuntimeException {}
