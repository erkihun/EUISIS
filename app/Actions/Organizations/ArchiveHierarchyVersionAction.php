<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

readonly class ArchiveHierarchyVersionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(HierarchyVersion $version, User $actor): HierarchyVersion
    {
        if ($version->status !== HierarchyVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('hierarchy-versions.only_draft_can_be_published'),
            ]);
        }

        $oldValues = $version->toArray();

        $version->update([
            'status' => HierarchyVersionStatus::Archived,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::HierarchyVersionArchived,
            $actor,
            $version,
            oldValues: $oldValues,
            newValues: $version->fresh()?->toArray(),
        );

        return $version->fresh();
    }
}
