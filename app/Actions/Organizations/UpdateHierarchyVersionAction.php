<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

readonly class UpdateHierarchyVersionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, HierarchyVersion $version, User $actor): HierarchyVersion
    {
        if ($version->status !== HierarchyVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('hierarchy-versions.only_draft_can_be_published'),
            ]);
        }

        $oldValues = $version->toArray();

        $version->update([
            'version_name' => $attributes['version_name'],
            'notes' => $attributes['notes'] ?? null,
            'source_document' => $attributes['source_document'] ?? null,
            'effective_from' => $attributes['effective_from'],
            'effective_to' => $attributes['effective_to'] ?? null,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::HierarchyVersionUpdated,
            $actor,
            $version,
            oldValues: $oldValues,
            newValues: $version->fresh()?->toArray(),
        );

        return $version->fresh();
    }
}
