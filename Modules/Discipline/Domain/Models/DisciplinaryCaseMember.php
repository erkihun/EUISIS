<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Discipline\Domain\Enums\DisciplinaryCaseRole;

/**
 * A seat on one case: a snapshot of the committee member (or an appointed
 * investigator) when the case was charged. Recusal deactivates the seat for
 * this case only (Art. 9(1)); the committee membership is untouched.
 */
class DisciplinaryCaseMember extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'role' => DisciplinaryCaseRole::class,
            'is_active' => 'boolean',
            'recused_at' => 'datetime',
        ];
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_member_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSeated(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('recused_at');
    }
}
