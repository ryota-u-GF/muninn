<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Services\MemberDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemberController extends Controller
{
    /** 担当者候補・表示名。外注には自分と閲覧できるタスクの担当者だけを返す。 */
    public function index(Request $request, MemberDirectory $directory): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = User::query()->where('role', '!=', Role::Tv->value)->orderBy('id');
        if (! $user->isInternal()) {
            $query->whereIn('id', $directory->visibleMemberIds($user));
        }

        return MemberResource::collection($query->get());
    }
}
