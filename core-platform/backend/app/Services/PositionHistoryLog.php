<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Position;
use App\Models\PositionHistory;
use App\Models\User;

/**
 * The material history of a position (ADR 0010): moves, titles, grade and
 * level, occupation, profession, category and employment type, seats,
 * lifecycle status and approval. Each change is one row naming the fields
 * from and to, who changed them and from when; rows are never updated.
 */
final class PositionHistoryLog
{
    /** Field => the kind of change it is. */
    private const KINDS = [
        'organization_id' => 'moved', 'organization_unit_id' => 'moved',
        'title_en' => 'retitled', 'title_am' => 'retitled',
        'job_grade_id' => 'regraded', 'job_level_id' => 'regraded', 'grade_level' => 'regraded',
        'occupation_id' => 'reclassified', 'profession_id' => 'reclassified', 'position_category_id' => 'reclassified', 'employment_type_id' => 'reclassified',
        'lifecycle_status' => 'status_changed',
        'effective_from' => 'dates_changed', 'effective_to' => 'dates_changed',
        'job_position_code' => 'recoded',
    ];

    /**
     * Records what changed between two snapshots, one row per kind of change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function changes(Position $position, array $before, array $after, ?User $actor, ?string $reason = null): void
    {
        $byKind = [];
        foreach (self::KINDS as $field => $kind) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($this->scalar($old) !== $this->scalar($new)) {
                $byKind[$kind][$field] = ['from' => $this->scalar($old), 'to' => $this->scalar($new)];
            }
        }
        foreach ($byKind as $kind => $fields) {
            $this->record($position, $kind, $fields, $actor, $reason);
        }
    }

    /** @param array<string, mixed> $changes */
    public function record(Position $position, string $kind, array $changes, ?User $actor, ?string $reason = null, ?string $effectiveFrom = null): PositionHistory
    {
        return PositionHistory::query()->create([
            'position_id' => $position->getKey(),
            'change_type' => $kind,
            'changes' => $changes,
            'effective_from' => $effectiveFrom ?? now()->toDateString(),
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
        ]);
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
