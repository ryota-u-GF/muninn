<?php

namespace App\Support;

/** Google ログインの接続準備状態（実アカウントでの検証済みとは区別する） */
final class GoogleLogin
{
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
