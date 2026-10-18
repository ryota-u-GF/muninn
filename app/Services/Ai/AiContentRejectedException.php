<?php

namespace App\Services\Ai;

/** この文章は処理できない（拒否・長すぎる・読み取り不能）。再試行しても同じなので、取り込みではスキップする。 */
class AiContentRejectedException extends AiRequestFailedException {}
