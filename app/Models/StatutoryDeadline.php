<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Legal\Regulation201;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A calculated statutory milestone (StatutoryDeadlineService). The due date is
 * a snapshot: it is written once and never recalculated from later settings.
 */
class StatutoryDeadline extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'rule_key',
        'legal_reference',
        'basis',
        'amount',
        'started_at',
        'due_at',
        'completed_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOverdue(): bool
    {
        return $this->superseded_at === null
            && ($this->completed_at ?? now())->gt($this->due_at);
    }

    /** @return array<string, mixed> */
    public function toPresentation(string $locale): array
    {
        $rule = Regulation201::rules()[$this->rule_key] ?? null;

        return [
            'id' => $this->getKey(),
            'rule_key' => $this->rule_key,
            'label' => $rule === null ? $this->rule_key : ($locale === 'am' ? $rule['label_am'] : $rule['label_en']),
            'legal_reference' => $this->legal_reference,
            'basis' => $this->basis,
            'amount' => $this->amount,
            'started_at' => $this->started_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'superseded' => $this->superseded_at !== null,
            'overdue' => $this->isOverdue(),
        ];
    }
}
