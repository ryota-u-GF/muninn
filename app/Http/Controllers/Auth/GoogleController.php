<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleLogin;
use App\Support\SessionLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Google ログイン（接続準備済み・実アカウントでは未検証）。
 * 事前登録済みユーザー、または許可ドメインの新規社員のみ。最初のログイン者を管理者にしない。
 */
class GoogleController extends Controller
{
    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        if (! GoogleLogin::configured()) {
            return redirect()->route('login')->with('error', 'Google ログインは未接続です（接続情報が未設定）。');
        }

        // Socialite が state を検証する。scope は openid / email / profile のみ。
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! GoogleLogin::configured()) {
            return redirect()->route('login')->with('error', 'Google ログインは未接続です。');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('login')->with('error', 'Google 認証を確認できませんでした。もう一度お試しください。');
        }

        $raw = method_exists($google, 'getRaw') ? $google->getRaw() : [];
        $email = mb_strtolower((string) $google->getEmail());
        if ($email === '' || ! ($raw['email_verified'] ?? false)) {
            return redirect()->route('login')->with('error', '確認済みの Google メールアドレスが必要です。');
        }

        $user = User::query()->where('google_id', $google->getId())->first()
            ?? User::query()->where('email', $email)->first();

        if ($user === null) {
            $domain = substr((string) strrchr($email, '@'), 1);
            $allowed = config('gf.google_allowed_domains');
            if (! in_array($domain, $allowed, true) || ($raw['hd'] ?? null) !== $domain) {
                return redirect()->route('login')->with('error', 'このアカウントは登録されていません。管理者に登録を依頼してください。');
            }
            // 許可ドメインの新規利用者は「社員」として作成（管理者にはしない）
            $user = new User;
            $user->forceFill(['name' => (string) ($google->getName() ?: $email), 'email' => $email, 'role' => Role::Employee, 'is_active' => true])->save();
        }

        if ($user->google_id !== null && $user->google_id !== (string) $google->getId()) {
            return redirect()->route('login')->with('error', '別の Google アカウントが紐づいています。');
        }
        if (! $user->is_active) {
            return redirect()->route('login')->with('error', 'このアカウントは無効化されています。');
        }
        if ($user->google_id === null) {
            $user->forceFill(['google_id' => (string) $google->getId()])->save();
        }

        SessionLogin::login($request, $user->refresh());

        return redirect()->intended($user->isTv() ? route('tv.page') : route('board'));
    }
}
