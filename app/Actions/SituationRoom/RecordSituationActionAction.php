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
use Illuminate\Support\Str;

/**
 * Record a new executive action. Writes the register row and its audit
 * entry together; touches nothing else.
 */
final class RecordSituationActionAction
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param  array<string, mixed>  $data */
    public function execute(array $data, User $actor, ?Request $request = null): SituationAction
    {
        return DB::transaction(function () use ($data, $actor, $request): SituationAction {
            $action = SituationAction::query()->create([
                ...$data,
                'reference_number' => $this->reference(),
                'status' => SituationActionStatus::Open,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->execute(
                eventType: AuditEventType::SituationActionCreated,
                actor: $actor,
                auditable: $action,
                organizationId: $action->organization_id,
                newValues: $action->only(['reference_number', 'category', 'panel_key', 'title', 'priority', 'status', 'due_date']),
                request: $request,
            );

            return $action;
        });
    }

    /** SRA-YYYYMMDD-XXXXX: readable on paper, unguessable enough not to enumerate. */
    private function reference(): string
    {
        do {
            $reference = 'SRA-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (SituationAction::query()->where('reference_number', $reference)->exists());

        return $reference;
    }
}
