<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GmailConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Gmail 連携（読み取り専用）。ログイン用の Google 認証とは別に、本人が許可したときだけ連携する。
 * 求める権限は gmail.readonly のみ（送信・削除はできない）。
 */
class GmailController extends Controller
{
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.gmail_redirect'));
    }

    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        if (! self::configured()) {
            return redirect('/imports?gmail=unconfigured');
        }

        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver
            ->redirectUrl((string) config('services.google.gmail_redirect'))
            ->scopes(['openid', 'email', (string) config('gf.gmail.scope')])
            // 更新トークンを確実に受け取るため、毎回、許可画面を出す。
            ->with(['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'false'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! self::configured()) {
            return redirect('/imports?gmail=unconfigured');
        }

        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $google = $driver->redirectUrl((string) config('services.google.gmail_redirect'))->user();
        } catch (Throwable) {
            return redirect('/imports?gmail=error');
        }

        $refreshToken = $google->refreshToken ?? null;
        $approved = (array) ($google->approvedScopes ?? []);
        if (! is_string($refreshToken) || $refreshToken === '' || ! in_array(config('gf.gmail.scope'), $approved, true)) {
            return redirect('/imports?gmail=denied');
        }

        GmailConnection::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['google_email' => mb_strtolower((string) $google->getEmail()), 'refresh_token' => $refreshToken, 'last_error' => null],
        );

        return redirect('/imports?gmail=connected');
    }
}
