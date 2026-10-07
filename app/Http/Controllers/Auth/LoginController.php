<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DemoMode;
use App\Support\GoogleLogin;
use App\Support\SessionLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
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

    public function logout(Request $request): RedirectResponse
    {
        SessionLogin::logout($request);

        return redirect()->route('login');
    }
}
