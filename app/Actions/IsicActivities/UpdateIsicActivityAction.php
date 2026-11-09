<?php

declare(strict_types=1);

namespace App\Actions\IsicActivities;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IsicActivity;
use App\Models\User;

readonly class UpdateIsicActivityAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(IsicActivity $activity, array $attributes, User $actor): IsicActivity
    {
        $oldValues = $activity->toArray();

        if (isset($attributes['isic_code']) && $attributes['isic_code'] !== '') {
            $attributes['isic_code'] = strtoupper(trim((string) $attributes['isic_code']));
        }

        $attributes['updated_by'] = $actor->getKey();

        $activity->update($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::IsicActivityUpdated,
            $actor,
            $activity->fresh(),
            null,
            oldValues: $oldValues,
            newValues: $activity->fresh()->toArray(),
        );

        return $activity->fresh();
    }
}
