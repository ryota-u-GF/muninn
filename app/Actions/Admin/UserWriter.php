<?php

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Services\VersionedWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 利用者の登録・役割変更・無効化。
 * 役割変更・無効化は session_version を進め、既存セッションを即時失効させる。
 */
class UserWriter
{
    public function __construct(private RevisionRecorder $revisions, private VersionedWriter $writer) {}

    public function create(?User $actor, string $name, string $email, Role $role): User
    {
        return DB::transaction(function () use ($actor, $name, $email, $role) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => mb_strtolower($email),
                'role' => $role,
                'is_active' => true,
            ])->save();
            $user->refresh();
            $this->revisions->audit('user', $user->id, 'create', $actor, null, $this->snapshot($user));

            return $user;
        });
    }

    /** @param array{name?: string, role?: string, is_active?: bool} $data */
    public function update(?User $actor, int $id, array $data, int $expectedRevision): User
    {
        if ($actor !== null && $actor->id === $id
            && ((isset($data['role']) && $data['role'] !== Role::Admin->value) || (array_key_exists('is_active', $data) && ! $data['is_active']))) {
            throw ValidationException::withMessages(['role' => '自分自身の管理者権限の解除・無効化はできません。']);
        }

        return DB::transaction(function () use ($actor, $id, $data, $expectedRevision) {
            /** @var User $user */
            $user = $this->writer->lock(User::class, $id, $expectedRevision);
            $before = $this->snapshot($user);

            $attributes = [];
            if (isset($data['name'])) {
                $attributes['name'] = $data['name'];
            }
            $securityChanged = false;
            if (isset($data['role']) && $data['role'] !== $user->role->value) {
                $attributes['role'] = $data['role'];
                $securityChanged = true;
            }
            if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $user->is_active) {
                $attributes['is_active'] = (bool) $data['is_active'];
                $securityChanged = true;
            }
            if ($securityChanged) {
                $attributes['session_version'] = $user->session_version + 1;
            }

            $this->writer->update($user, $expectedRevision, $attributes);

            if ($securityChanged) {
                // DB セッションを削除（ミドルウェアでも session_version で確認する）
                DB::table('sessions')->where('user_id', $id)->delete();
            }

            $fresh = User::query()->findOrFail($id);
            $this->revisions->audit('user', $id, $securityChanged ? 'security_change' : 'update', $actor, $before, $this->snapshot($fresh));

            return $fresh;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
        ];
    }
}
