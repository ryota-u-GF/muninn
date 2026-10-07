<?php

namespace App\Http\Controllers\Api;

use App\Actions\Admin\UserWriter;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** 管理者用：利用者の登録・役割変更・無効化 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->orderBy('id')->get()->map(fn (User $u) => $this->present($u)),
        ]);
    }

    public function store(StoreUserRequest $request, UserWriter $writer): JsonResponse
    {
        $data = $request->validated();
        $user = $writer->create($request->user(), $data['name'], $data['email'], Role::from($data['role']));

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(UpdateUserRequest $request, int $user, UserWriter $writer): JsonResponse
    {
        $data = collect($request->validated())->except('expected_revision')->all();
        $updated = $writer->update($request->user(), $user, $data, (int) $request->validated('expected_revision'));

        return response()->json(['data' => $this->present($updated)]);
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role->value,
            'role_label' => $u->role->label(),
            'is_active' => $u->is_active,
            'google_linked' => $u->google_id !== null,
            'revision' => $u->revision,
        ];
    }
}
