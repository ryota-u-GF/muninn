<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SessionLogin
{
    public const VERSION_KEY = 'gf.session_version';

    public static function login(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::VERSION_KEY, $user->session_version);
    }

    public static function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
