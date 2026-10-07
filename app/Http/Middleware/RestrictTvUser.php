<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** TV 専用アカウントは TV 画面・TV 読み取り API・ログアウトだけ利用できる */
class RestrictTvUser
{
    private const ALLOWED = ['tv.page', 'tv.data', 'logout', 'login', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isTv() && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'TV専用アカウントでは利用できません。'], 403);
            }

            return redirect()->route('tv.page');
        }

        return $next($request);
    }
}
