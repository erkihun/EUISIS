<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Letters\Domain\Enums\RoutingStatus;

/** A forwarding step inside one institution: to a unit or a person, with an instruction and a due date. */
class LetterRouting extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letter_routings';

    protected $fillable = [
        'registration_id', 'letter_id', 'parent_id', 'step', 'from_user_id', 'from_unit_id', 'to_user_id', 'to_unit_id', 'instruction', 'due_on', 'status',
        'acknowledged_at', 'completed_at', 'outcome', 'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoutingStatus::class,
            'due_on' => 'date:Y-m-d',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(LetterRegistration::class, 'registration_id');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    /** The step this one was passed on from; null for a step the registry office started. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The unit the step was sent from: the registry office for a first step. */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'from_unit_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'to_unit_id');
    }
}
