<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use App\Services\PositionHistoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The position lifecycle (ADR 0010):
 *   draft → approved (positions.approve);
 *   approved ⇄ suspended (positions.archive; a suspended position takes no new placement);
 *   approved/suspended → abolished (positions.archive; nobody may hold it);
 *   abolished → archived (positions.archive; kept for history).
 * is_active follows (true only when approved), so every placement rule holds.
 * Each step is locked, audited and kept in the position's history.
 */
final readonly class ChangePositionLifecycle
{
    /** step => [allowed from, to, permission, reason required] */
    private const STEPS = [
        'approve' => [[Position::DRAFT], Position::APPROVED, 'positions.approve', false],
        'suspend' => [[Position::APPROVED], Position::SUSPENDED, 'positions.archive', true],
        'reinstate' => [[Position::SUSPENDED], Position::APPROVED, 'positions.archive', true],
        'abolish' => [[Position::APPROVED, Position::SUSPENDED, Position::DRAFT], Position::ABOLISHED, 'positions.archive', true],
        'archive' => [[Position::ABOLISHED], Position::ARCHIVED, 'positions.archive', false],
    ];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private PositionHistoryLog $history) {}

    /** @return list<string> */
    public static function steps(): array
    {
        return array_keys(self::STEPS);
    }

    /** The steps the position's status allows now (the permissions are checked when taken). */
    public static function allowedFrom(string $status): array
    {
        return array_keys(array_filter(self::STEPS, fn (array $step): bool => in_array($status, $step[0], true)));
    }

    public static function needsReason(string $step): bool
    {
        return self::STEPS[$step][3] ?? true;
    }

    public static function permissionFor(string $step): string
    {
        return self::STEPS[$step][2] ?? 'none';
    }

    public function execute(Position $position, string $step, ?string $reason, User $actor): Position
    {
        [$from, $to, $permission, $needsReason] = self::STEPS[$step] ?? throw ValidationException::withMessages(['step' => 'Unknown position step.']);
        if ($needsReason && mb_strlen(trim((string) $reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason (at least 5 characters).']);
        }

        return DB::transaction(function () use ($position, $step, $from, $to, $permission, $reason, $actor): Position {
            $position = Position::query()->lockForUpdate()->findOrFail($position->getKey());
            abort_unless($actor->is_active && $actor->can($permission) && $this->scope->allows($actor, (string) $position->organization_id), 403);
            if (! in_array($position->lifecycle_status, $from, true)) {
                throw ValidationException::withMessages(['step' => 'A '.$position->lifecycle_status.' position cannot be '.$step.'d.']);
            }
            if ($to === Position::ABOLISHED && $position->holders()->exists()) {
                throw ValidationException::withMessages(['step' => 'Someone holds this position: transfer them before abolishing it.']);
            }
            $before = $position->lifecycle_status;
            $position->lifecycle_status = $to;
            $position->status_reason = $reason;
            if ($step === 'approve') {
                $position->forceFill(['approved_by' => $actor->getKey(), 'approved_at' => now()]);
            }
            $position->save();
            $this->history->record($position, 'status_changed', ['lifecycle_status' => ['from' => $before, 'to' => $to]], $actor, $reason);
            $this->audit->write('position.'.$step, $actor, $position, (string) $position->organization_id, ['lifecycle_status' => $before], ['lifecycle_status' => $to], $reason);

            return $position;
        });
    }
}
