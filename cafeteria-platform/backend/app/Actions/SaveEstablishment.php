<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use App\Services\PositionHistoryLog;
use App\Services\SeatCapacity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves seats for a position (a position establishment) or ends them. An
 * approved establishment counts toward the seat rule from its start date; one
 * is ended, never deleted, and not below the people who hold the position.
 */
final readonly class SaveEstablishment
{
    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private SeatCapacity $seats, private PositionHistoryLog $history) {}

    /** @param array{approved_slots: int, effective_from: string, establishment_number?: ?string, approval_reference?: ?string, notes?: ?string} $data */
    public function approve(Position $position, array $data, User $actor): PositionEstablishment
    {
        return DB::transaction(function () use ($position, $data, $actor): PositionEstablishment {
            $position = Position::query()->lockForUpdate()->findOrFail($position->id);
            $this->assertScope($position, $actor);
            if (in_array($position->lifecycle_status, [Position::ABOLISHED, Position::ARCHIVED], true)) {
                throw ValidationException::withMessages(['approved_slots' => 'An abolished or archived position takes no new seats.']);
            }
            $before = $this->seats->capacity((string) $position->id);

            $number = trim((string) ($data['establishment_number'] ?? ''));
            $number = $number !== '' ? $number : 'EST-'.$position->job_position_code.'-'.now()->format('YmdHis');
            if (PositionEstablishment::withTrashed()->where('establishment_number', $number)->exists()) {
                throw ValidationException::withMessages(['establishment_number' => 'This establishment number is already used.']);
            }

            $establishment = PositionEstablishment::query()->create([
                'establishment_number' => $number,
                'organization_id' => $position->organization_id,
                'organization_unit_id' => $position->organization_unit_id,
                'position_id' => $position->id,
                'approved_slots' => (int) $data['approved_slots'],
                'effective_from' => $data['effective_from'],
                'status' => PositionEstablishment::APPROVED,
                'approval_reference' => $data['approval_reference'] ?? null,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
            $this->audit->write('establishment.approved', $actor, $establishment, (string) $position->organization_id, null,
                $establishment->only(['establishment_number', 'position_id', 'approved_slots', 'effective_from']));
            $this->history->record($position, 'capacity_changed', ['seats' => ['from' => $before, 'to' => $this->seats->capacity((string) $position->id)],
                'establishment' => ['from' => null, 'to' => $establishment->establishment_number]], $actor, $data['approval_reference'] ?? null, (string) $data['effective_from']);

            return $establishment;
        });
    }

    public function end(PositionEstablishment $establishment, string $effectiveTo, User $actor, ?string $reason = null): PositionEstablishment
    {
        return DB::transaction(function () use ($establishment, $effectiveTo, $actor, $reason): PositionEstablishment {
            $establishment = PositionEstablishment::query()->lockForUpdate()->findOrFail($establishment->id);
            $position = Position::query()->lockForUpdate()->findOrFail($establishment->position_id);
            $this->assertScope($position, $actor);
            if ($establishment->effective_to !== null) {
                throw ValidationException::withMessages(['effective_to' => 'This establishment has already ended.']);
            }
            if ($effectiveTo < $establishment->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_to' => 'The end cannot be before the start.']);
            }

            $establishment->update(['effective_to' => $effectiveTo, 'status' => 'archived']);
            // Seats on the first day without this establishment (today at the earliest).
            $firstDayWithout = Carbon::parse($effectiveTo)->addDay();
            $after = $this->seats->capacity((string) $position->id, $firstDayWithout->isPast() ? Carbon::today() : $firstDayWithout);
            if ($this->seats->holders((string) $position->id) > $after) {
                throw ValidationException::withMessages(['effective_to' => 'More people hold this position than the seats that would remain; transfer someone first.']);
            }
            $this->audit->write('establishment.ended', $actor, $establishment, (string) $position->organization_id, null,
                ['effective_to' => $effectiveTo, 'status' => 'archived'], $reason);
            $this->history->record($position, 'capacity_changed', ['establishment' => ['from' => $establishment->establishment_number, 'to' => null], 'seats_after' => ['from' => null, 'to' => $after]],
                $actor, $reason, $firstDayWithout->toDateString());

            return $establishment;
        });
    }

    private function assertScope(Position $position, User $actor): void
    {
        if (! $this->scope->allows($actor, (string) $position->organization_id)) {
            throw ValidationException::withMessages(['position' => 'The position is outside your organizations.']);
        }
    }
}
