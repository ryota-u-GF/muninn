<?php

namespace App\Support;

/**
 * 開発限定デモ。production では設定値に関わらず常に無効。
 */
final class DemoMode
{
    public static function loginEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && (bool) config('gf.demo_login');
    }

    public static function seedingAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
