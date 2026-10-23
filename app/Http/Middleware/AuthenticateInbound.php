<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 外部システム（メール・議事録を溜めて AI で判定する側）からの候補の受け取り口を、共有トークンで守る。
 * トークンが未設定のときは受け口ごと無効にする。
 */
class AuthenticateInbound
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('gf.inbound.token');
        if ($expected === '') {
            return response()->json(['message' => '候補の受け取りは有効になっていません。'], 503);
        }

        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => '認証に失敗しました。'], 401);
        }

        return $next($request);
    }
}
