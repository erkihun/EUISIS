<?php

declare(strict_types=1);

namespace Modules\Leave\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated version of the legal figures (ADR 0039, decision 3). A request
 * keeps the rule set it was counted under, so a change of law never rewrites
 * an old request.
 */
class LeaveRuleSet extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = ['name', 'legal_reference', 'effective_from', 'effective_to', 'notes', 'is_confirmed'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_confirmed' => 'bool',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(LeaveRule::class);
    }

    /** The rule sets that apply on a day: active, started, and not yet ended. */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
