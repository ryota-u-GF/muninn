<?php

use App\Actions\Admin\UserWriter;
use App\Enums\Role;
use App\Http\Requests\SetPasswordRequest;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

/*
| 初期管理者の作成など、利用者の明示的な管理コマンド。
| 最初にログインした人を自動で管理者にすることはない。
*/
$text = fn (mixed $value): string => is_string($value) ? trim($value) : '';

Artisan::command('gf:user {email} {--name=} {--role=employee : admin|employee|contractor|tv} {--password : パスワードを設定する（入力は画面に表示されない）}', function (UserWriter $writer) use ($text) {
    $email = mb_strtolower($text($this->argument('email')));
    $role = Role::tryFrom($text($this->option('role')));
    $name = $text($this->option('name'));
    $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);
    if ($validator->fails() || $role === null) {
        $this->error('メールアドレスまたは役割が正しくありません。');

        return 1;
    }

    // パスワードはコマンドラインに載せず、対話入力で受け取る（シェル履歴に残さない）
    $password = null;
    if ($this->option('password')) {
        $password = (string) $this->secret('パスワード（'.SetPasswordRequest::MIN_LENGTH.'文字以上）');
        if (mb_strlen($password) < SetPasswordRequest::MIN_LENGTH) {
            $this->error('パスワードは'.SetPasswordRequest::MIN_LENGTH.'文字以上にしてください。');

            return 1;
        }
        if (SetPasswordRequest::bytesError($password) !== null) {
            $this->error(SetPasswordRequest::bytesError($password));

            return 1;
        }
        if ($password !== (string) $this->secret('パスワード（確認）')) {
            $this->error('確認用のパスワードが一致しません。');

            return 1;
        }
    }

    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $user = $writer->create(null, $name !== '' ? $name : $email, $email, $role);
        $this->info("登録しました：#{$user->id} {$user->email}（{$role->label()}）");
    } else {
        $data = ['role' => $role->value];
        if ($name !== '') {
            $data['name'] = $name;
        }
        $user = $writer->update(null, $user->id, $data, $user->revision);
        $this->info("更新しました：#{$user->id} {$user->email}（{$user->role->label()}）");
    }

    if ($password !== null) {
        $writer->setPassword(null, $user->id, $password, $user->revision);
        $this->info('パスワードを設定しました。');
    }

    return 0;
})->purpose('利用者を登録／役割を変更する（例：初期管理者の作成。--password でパスワードも設定）');

Artisan::command('gf:user-deactivate {email}', function (UserWriter $writer) use ($text) {
    $user = User::query()->where('email', mb_strtolower($text($this->argument('email'))))->first();
    if ($user === null) {
        $this->error('利用者が見つかりません。');

        return 1;
    }
    $writer->update(null, $user->id, ['is_active' => false], $user->revision);
    $this->info("無効化しました：{$user->email}（既存セッションも失効）");

    return 0;
})->purpose('利用者を無効化し、既存セッションを失効させる');
