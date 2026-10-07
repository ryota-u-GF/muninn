<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Services\RevisionRecorder;
use App\Services\VersionedWriter;
use Illuminate\Support\Facades\DB;

/** 案件の作成・名称変更・並び順・アーカイブ（社内のみ。Policy で確認済みの前提） */
class ProjectWriter
{
    public function __construct(private RevisionRecorder $revisions, private VersionedWriter $writer) {}

    public function create(User $actor, string $name): Project
    {
        return DB::transaction(function () use ($actor, $name) {
            $project = Project::create([
                'name' => $name,
                'sort_order' => (int) Project::query()->max('sort_order') + 1,
                'created_by' => $actor->id,
            ]);
            $project->refresh();
            $this->revisions->audit('project', $project->id, 'create', $actor, null, $project->snapshot(), $project->id);

            return $project;
        });
    }

    /** @param array{name?: string, sort_order?: int, archived?: bool} $data */
    public function update(User $actor, int $id, array $data, int $expectedRevision): Project
    {
        return DB::transaction(function () use ($actor, $id, $data, $expectedRevision) {
            /** @var Project $project */
            $project = $this->writer->lock(Project::class, $id, $expectedRevision);
            $before = $project->snapshot();

            $attributes = [];
            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }
            if (array_key_exists('sort_order', $data)) {
                $attributes['sort_order'] = (int) $data['sort_order'];
            }
            if (array_key_exists('archived', $data)) {
                $attributes['archived_at'] = $data['archived'] ? ($project->archived_at ?? now()) : null;
            }

            $this->writer->update($project, $expectedRevision, $attributes);
            $fresh = Project::query()->findOrFail($id);

            $action = match (true) {
                array_key_exists('archived', $data) && $data['archived'] && ! $before['archived'] => 'archive',
                array_key_exists('archived', $data) && ! $data['archived'] && $before['archived'] => 'unarchive',
                default => 'update',
            };
            $this->revisions->audit('project', $id, $action, $actor, $before, $fresh->snapshot(), $id);

            return $fresh;
        });
    }
}
