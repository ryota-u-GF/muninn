<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AnthropicTaskExtractor;
use App\Services\Ai\TaskExtractor;
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
    public function register(): void
    {
        $this->app->bind(TaskExtractor::class, AnthropicTaskExtractor::class);
    }

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
        RateLimiter::for('imports', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()->id ?? $request->ip())));
        // AI の呼び出し（費用がかかるため、利用者ごとに 1 分 10 回まで）
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()->id ?? $request->ip())));
        // Gmail の取得（1 回の呼び出しで AI を複数回使うため、利用者ごとに 1 分 12 回まで）
        RateLimiter::for('gmail-sync', fn (Request $request) => Limit::perMinute(12)->by((string) ($request->user()->id ?? $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()->id ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by((string) ($request->user()->id ?? $request->ip())));
    }
}
