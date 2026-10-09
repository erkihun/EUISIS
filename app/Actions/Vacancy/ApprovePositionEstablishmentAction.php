<?php

declare(strict_types=1);

namespace App\Actions\Vacancy;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EstablishmentStatus;
use App\Models\PositionEstablishment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

readonly class ApprovePositionEstablishmentAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(PositionEstablishment $establishment, User $actor): PositionEstablishment
    {
        if ($establishment->status !== EstablishmentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('positionEstablishments.alreadyApproved'),
            ]);
        }

        $old = $establishment->toArray();

        $establishment->update([
            'status' => EstablishmentStatus::Approved->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::PositionEstablishmentApproved,
            $actor,
            $establishment->fresh(),
            $establishment->organization_id,
            oldValues: $old,
            newValues: $establishment->fresh()->toArray(),
        );

        return $establishment->fresh();
    }
}
