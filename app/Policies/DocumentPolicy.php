<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\Access;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($document->trashed()) {
            return $user->isInternal()
                && Access::canViewState($user, $document->visibility->value, $document->project_id, $document->created_by);
        }

        return Access::canViewState($user, $document->visibility->value, $document->project_id, $document->created_by);
    }

    public function create(User $user): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->isContractor() && Access::contractorProjectIds($user, true) !== [];
    }

    public function update(User $user, Document $document): bool
    {
        if ($document->trashed() || ! $this->view($user, $document)) {
            return false;
        }

        if ($document->isPrivate()) {
            return $document->created_by === $user->id;
        }

        return Access::canEditProjectContent($user, $document->project_id);
    }

    public function delete(User $user, Document $document): bool
    {
        if ($document->trashed() || ! $user->isInternal() || ! $this->view($user, $document)) {
            return false;
        }

        return ! $document->isPrivate() || $document->created_by === $user->id;
    }

    public function restore(User $user, Document $document): bool
    {
        if (! $user->isInternal() || ! $this->view($user, $document)) {
            return false;
        }

        return ! $document->isPrivate() || $document->created_by === $user->id;
    }
}
