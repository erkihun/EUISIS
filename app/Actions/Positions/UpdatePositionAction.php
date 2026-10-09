<?php

declare(strict_types=1);

namespace App\Actions\Positions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Position;
use App\Models\User;

readonly class UpdatePositionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(Position $position, array $attributes, User $actor): Position
    {
        $oldValues = $position->toArray();

        $position->update($attributes);

        $this->writeAuditLogAction->execute(
            AuditEventType::PositionUpdated,
            $actor,
            $position->fresh(),
            $position->organization_id,
            oldValues: $oldValues,
            newValues: $position->fresh()->toArray(),
        );

        return $position->fresh();
    }
}
