<?php

use App\Exceptions\RevisionConflictException;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RestrictTvUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->redirectUsersTo('/');

        $middleware->web(append: [
            EnsureActiveSession::class,
            RestrictTvUser::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 本文・説明などの入力値をセッションへ戻さない（ログにも出さない）
        $exceptions->dontFlash(['body', 'description', 'json', 'participants', 'password', 'current_password']);

        // 競合（409）は想定内の応答なので記録しない
        $exceptions->dontReport([RevisionConflictException::class]);

        // DB エラーはバインド値（本文・タイトル等）を含めずに記録する
        $exceptions->report(function (QueryException $e) {
            Log::error('データベースエラー', [
                'sqlstate' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
                'sql' => $e->getSql(),
                'connection' => $e->getConnectionName(),
            ]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
