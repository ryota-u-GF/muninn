<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // 本番では migrate:fresh / db:wipe 等の破壊的コマンドを禁止
        DB::prohibitDestructiveCommands(app()->isProduction());

        $this->configureGates();
        $this->configureRateLimiting();
    }

    private function configureGates(): void
    {
        Gate::define('admin', fn (User $user) => $user->is_active && $user->isAdmin());
        Gate::define('manage-projects', fn (User $user) => $user->is_active && $user->isInternal());
        // AI 候補は社内メンバー本人のみ（外注・TV 不可）
        Gate::define('use-imports', fn (User $user) => $user->is_active && $user->isInternal());
        // TV 表示：TV 専用と社内メンバー（外注不可）
        Gate::define('view-tv', fn (User $user) => $user->is_active && ($user->isTv() || $user->isInternal()));
    }

    private function configureRateLimiting(): void
    {
        // ログイン：IP 単位で 30 回／分、アカウント（デモ）単位で 10 回／分
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('acct:'.$request->ip().'|'.$request->integer('user_id')),
        ]);
        // パスワードログイン：IP 単位で 30 回／分、利用者（メールアドレス）単位で 5 回／分（総当たり対策）。
        // DB の照合順序はアクセントや全角の違いを区別しないため、入力の綴りではなく、引き当てた利用者の ID で数える。
        RateLimiter::for('password-login', function (Request $request) {
            $input = $request->input('email');
            $email = is_string($input) ? mb_strtolower($input) : '';
            $userId = $email === '' ? null : User::query()->where('email', $email)->value('id');

            return [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by($userId !== null ? 'uid:'.$userId : 'email:'.$email),
            ];
        });
        RateLimiter::for('imports', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()->id ?? $request->ip())));
        // 外部システムからの候補の受け取り（トークン単位ではなく IP 単位で 1 分 60 回まで）
        RateLimiter::for('inbound', fn (Request $request) => Limit::perMinute(60)->by('ip:'.$request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()->id ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by((string) ($request->user()->id ?? $request->ip())));
    }
}
