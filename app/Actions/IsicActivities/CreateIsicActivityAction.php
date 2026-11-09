<?php

declare(strict_types=1);

namespace App\Actions\IsicActivities;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IsicActivity;
use App\Models\User;

readonly class CreateIsicActivityAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, User $actor): IsicActivity
    {
        $attributes['isic_code'] = strtoupper(trim((string) $attributes['isic_code']));
        $attributes['created_by'] = $actor->getKey();
        $attributes['updated_by'] = $actor->getKey();

        $activity = IsicActivity::query()->create($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::IsicActivityCreated,
            $actor,
            $activity,
            null,
            newValues: $activity->toArray(),
        );

        return $activity;
    }
}
