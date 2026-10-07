<?php

namespace Tests;

use App\Enums\AccessPermission;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\User;
use App\Support\SessionLogin;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** 実際のログインと同じくセッション版を設定してログイン状態にする */
    protected function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession([SessionLogin::VERSION_KEY => $user->session_version]);
    }

    protected function grant(User $contractor, Project $project, AccessPermission $permission): void
    {
        ProjectAccess::create([
            'user_id' => $contractor->id,
            'project_id' => $project->id,
            'permission' => $permission,
        ]);
    }
}
