<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\ManageImports;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\OrganizationScope;

/**
 * Imports (ADR 0009): the import permission plus the permission of what the
 * file creates; a batch is seen and decided only by someone whose scope holds
 * the uploader's; the approver is never the uploader.
 */
final class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasOrganizationScope() && $user->can('imports.view');
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $this->viewAny($user) && $this->covers($user, $batch);
    }

    public function create(User $user, string $type): bool
    {
        return $user->is_active && $user->hasOrganizationScope() && $user->can('imports.create')
            && in_array($type, ImportBatch::TYPES, true) && $user->can(ManageImports::definition($type)->permission());
    }

    public function approve(User $user, ImportBatch $batch): bool
    {
        return $user->is_active && $user->can('imports.approve') && $user->can(ManageImports::definition($batch->type)->permission())
            && $this->covers($user, $batch) && in_array($batch->status, ImportBatch::APPROVABLE, true)
            && (int) $batch->uploaded_by !== (int) $user->getKey();
    }

    public function cancel(User $user, ImportBatch $batch): bool
    {
        $mine = (int) $batch->uploaded_by === (int) $user->getKey() && $user->can('imports.create');

        return $user->is_active && ($mine || $user->can('imports.approve')) && $this->covers($user, $batch)
            && ! in_array($batch->status, [ImportBatch::PROCESSING, ImportBatch::APPROVED, ImportBatch::COMPLETED, ImportBatch::CANCELLED], true);
    }

    /** A batch uploaded city-wide is a city-wide matter; otherwise the reader's scope must hold the uploader's. */
    private function covers(User $user, ImportBatch $batch): bool
    {
        if ($batch->scope_organization_id === null) {
            return $user->isCityWide();
        }

        return app(OrganizationScope::class)->allows($user, (string) $batch->scope_organization_id);
    }
}
