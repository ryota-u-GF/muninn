<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordLoginRequest;
use App\Models\User;
use App\Support\DemoMode;
use App\Support\GoogleLogin;
use App\Support\SessionLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    private static ?string $dummyHash = null;

    public function show(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'googleConfigured' => GoogleLogin::configured(),
            'demoEnabled' => DemoMode::loginEnabled(),
            'demoUsers' => DemoMode::loginEnabled()
                ? User::query()->where('is_active', true)->orderBy('id')->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'role_label' => $u->role->label()])
                : [],
            'error' => $request->session()->get('error'),
        ]);
    }

    /** 開発・テスト環境かつ GF_DEMO_LOGIN=true のときだけ有効。本番では 404。 */
    public function demo(Request $request): RedirectResponse
    {
        abort_unless(DemoMode::loginEnabled(), 404);

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $user = User::query()->whereKey($data['user_id'])->where('is_active', true)->first();
        if ($user === null) {
            return back()->with('error', '利用できないアカウントです。');
        }

        SessionLogin::login($request, $user);

        return redirect()->intended($user->role === Role::Tv ? route('tv.page') : route('board'));
    }

    /**
     * メールアドレスとパスワードでログインする。
     * 失敗の理由（未登録・無効・パスワード未設定・不一致）は区別せず、同じメッセージにする。
     */
    public function password(PasswordLoginRequest $request): RedirectResponse
    {
        $user = User::query()->where('email', mb_strtolower($request->validated('email')))->first();
        $submitted = $request->validated('password');

        // 利用者の有無・パスワード設定の有無で処理時間が変わらないよう、照合は必ず1回だけ行う
        $matched = Hash::check($submitted, $user?->hasPassword() ? $user->password : self::dummyHash());

        if ($user === null || ! $user->is_active || ! $user->hasPassword() || ! $matched) {
            return back()->with('error', 'メールアドレスまたはパスワードが違います。');
        }

        // ハッシュの強度設定を上げたとき、ログイン成功のタイミングで保存済みのハッシュも作り直す
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $submitted])->saveQuietly();
        }

        SessionLogin::login($request, $user);

        return redirect()->intended($user->role === Role::Tv ? route('tv.page') : route('board'));
    }

    /** 存在しない利用者の照合に使う、実在のハッシュと同じ強度のダミー（プロセスごとに1回だけ作る） */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(32));
    }

    public function logout(Request $request): RedirectResponse
    {
        SessionLogin::logout($request);

        return redirect()->route('login');
    }
}
