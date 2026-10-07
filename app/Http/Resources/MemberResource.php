<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_active' => $this->is_active,
            // 他人のメールは社内にだけ返す（外注には自分のメールのみ）
            'email' => $this->when($viewer->isInternal() || $viewer->id === $this->id, $this->email),
        ];
    }
}
