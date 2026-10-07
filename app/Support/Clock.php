<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** 業務日付は Asia/Tokyo 基準。テストでは Carbon::setTestNow で時刻を注入する。 */
final class Clock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('gf.timezone'));
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }
}
