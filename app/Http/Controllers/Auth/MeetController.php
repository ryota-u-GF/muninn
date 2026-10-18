<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\MeetConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Meet のメモ（カレンダーの予定に付いたドキュメント）の連携。読み取り専用。
 * ログインや Gmail の連携とは別に、本人が許可したときだけ連携する。
 */
class MeetController extends Controller
{
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.meet_redirect'));
    }

    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        if (! self::configured()) {
            return redirect('/imports?meet=unconfigured');
        }

        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver
            ->redirectUrl((string) config('services.google.meet_redirect'))
            ->scopes(['openid', 'email', ...(array) config('gf.meet.scopes')])
            // 更新トークンを確実に受け取るため、毎回、許可画面を出す。
            ->with(['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'false'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! self::configured()) {
            return redirect('/imports?meet=unconfigured');
        }

        try {
            /** @var GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $google = $driver->redirectUrl((string) config('services.google.meet_redirect'))->user();
        } catch (Throwable) {
            return redirect('/imports?meet=error');
        }

        $refreshToken = $google->refreshToken ?? null;
        $approved = (array) ($google->approvedScopes ?? []);
        $missing = array_diff((array) config('gf.meet.scopes'), $approved);
        if (! is_string($refreshToken) || $refreshToken === '' || $missing !== []) {
            return redirect('/imports?meet=denied');
        }

        MeetConnection::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['google_email' => mb_strtolower((string) $google->getEmail()), 'refresh_token' => $refreshToken, 'last_error' => null],
        );

        return redirect('/imports?meet=connected');
    }
}
