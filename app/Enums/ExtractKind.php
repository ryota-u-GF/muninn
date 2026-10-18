<?php

namespace App\Enums;

/** AI でタスク候補を取り出す文章の種類 */
enum ExtractKind: string
{
    case Mail = 'mail';
    case Minutes = 'minutes';
}
