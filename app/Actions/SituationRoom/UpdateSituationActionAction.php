<?php

declare(strict_types=1);

namespace App\Actions\SituationRoom;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\SituationActionStatus;
use App\Models\SituationAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Update an executive action and audit what changed. Reaching Done or
 * Cancelled stamps completed_at; reopening clears it. Actions are never
 * deleted — a mistaken one is cancelled with a note.
 */
final class UpdateSituationActionAction
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data */
    public function execute(SituationAction $action, array $data, User $actor, ?Request $request = null): SituationAction
    {
        return DB::transaction(function () use ($action, $data, $actor, $request): SituationAction {
            $status = SituationActionStatus::from((string) $data['status']);
            $before = $action->only(array_keys($data));

            $action->fill([
                ...$data,
                'updated_by' => $actor->getKey(),
                'completed_at' => $status->isFinal() ? ($action->completed_at ?? now()) : null,
            ])->save();

            $after = $action->only(array_keys($data));
            $changed = array_keys(array_filter($after, fn ($value, string $key): bool => $this->normalise($value) !== $this->normalise($before[$key] ?? null), ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->execute(
                    eventType: AuditEventType::SituationActionUpdated,
                    actor: $actor,
                    auditable: $action,
                    organizationId: $action->organization_id,
                    oldValues: array_intersect_key($before, array_flip($changed)),
                    newValues: array_intersect_key($after, array_flip($changed)),
                    request: $request,
                );
            }

            return $action;
        });
    }

    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }
}
