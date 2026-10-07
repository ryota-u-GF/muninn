<?php

use App\Actions\Admin\UserWriter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/*
| 初期管理者の作成など、利用者の明示的な管理コマンド。
| 最初にログインした人を自動で管理者にすることはない。
*/
Artisan::command('gf:user {email} {--name=} {--role=employee : admin|employee|contractor|tv}', function (UserWriter $writer) {
    $email = mb_strtolower((string) $this->argument('email'));
    $role = Role::tryFrom((string) $this->option('role'));
    $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);
    if ($validator->fails() || $role === null) {
        $this->error('メールアドレスまたは役割が正しくありません。');

        return 1;
    }

    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $user = $writer->create(null, (string) ($this->option('name') ?: $email), $email, $role);
        $this->info("登録しました：#{$user->id} {$user->email}（{$role->label()}）");

        return 0;
    }

    $data = ['role' => $role->value];
    if ($this->option('name')) {
        $data['name'] = (string) $this->option('name');
    }
    $user = $writer->update(null, $user->id, $data, $user->revision);
    $this->info("更新しました：#{$user->id} {$user->email}（{$user->role->label()}）");

    return 0;
})->purpose('利用者を登録／役割を変更する（例：初期管理者の作成）');

Artisan::command('gf:user-deactivate {email}', function (UserWriter $writer) {
    $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
    if ($user === null) {
        $this->error('利用者が見つかりません。');

        return 1;
    }
    $writer->update(null, $user->id, ['is_active' => false], $user->revision);
    $this->info("無効化しました：{$user->email}（既存セッションも失効）");

    return 0;
})->purpose('利用者を無効化し、既存セッションを失効させる');
