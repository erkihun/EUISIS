<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SituationActionPriority;
use App\Enums\SituationActionStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in the Executive Action Register: a decision or instruction an
 * executive records against an organization while reading the Situation
 * Room. It points at a category and, optionally, the panel that prompted it;
 * it never changes the records that panel counts.
 *
 * @property string $id
 * @property string $reference_number
 * @property string $organization_id
 * @property string $category
 * @property SituationActionStatus $status
 * @property SituationActionPriority $priority
 */
class SituationAction extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'reference_number',
        'organization_id',
        'category',
        'panel_key',
        'title',
        'description',
        'priority',
        'status',
        'responsible_office',
        'due_date',
        'outcome_note',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SituationActionStatus::class,
            'priority' => SituationActionPriority::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
