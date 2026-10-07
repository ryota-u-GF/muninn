<?php

namespace App\Http\Middleware;

use App\Support\SessionLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 無効化・役割変更を既存セッションに即時反映する。
 * ユーザーは毎リクエスト DB から読み直されるため、役割はそのまま最新になる。
 * さらに session_version が一致しなければログアウトさせる。
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && (! $user->is_active || $request->session()->get(SessionLogin::VERSION_KEY) !== $user->session_version)) {
            SessionLogin::logout($request);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'セッションが無効になりました。再ログインしてください。'], 401);
            }

            return redirect()->route('login')->with('error', 'アカウントの状態が変更されたため、再ログインが必要です。');
        }

        return $next($request);
    }
}
