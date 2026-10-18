<?php

namespace App\Services\Ai;

use RuntimeException;

class AiNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI のキーが設定されていません。「AIの設定」でAPIキーを登録してください。');
    }
}
