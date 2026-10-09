<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\User;

readonly class CreateHierarchyVersionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, User $actor): HierarchyVersion
    {
        $version = HierarchyVersion::query()->create([
            'version_name' => $attributes['version_name'],
            'notes' => $attributes['notes'] ?? null,
            'source_document' => $attributes['source_document'] ?? null,
            'status' => HierarchyVersionStatus::Draft,
            'effective_from' => $attributes['effective_from'],
            'effective_to' => $attributes['effective_to'] ?? null,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::HierarchyVersionCreated,
            $actor,
            $version,
            newValues: $version->toArray(),
        );

        return $version;
    }
}
