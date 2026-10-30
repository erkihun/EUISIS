<?php

declare(strict_types=1);

namespace Modules\Discipline\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suspension from work pending a disciplinary case (Regulation 201/2018
 * Art. 12). Written only by DisciplinaryCaseService. In force from starts_on
 * until it is lifted or its end date passes; when the case ends, the
 * salary withheld by an unpaid suspension becomes due unless the employee
 * was dismissed (Art. 12(2)).
 */
class DisciplinarySuspension extends Model
{
    use HasUuidPrimaryKey;

    public const BACK_PAY_DUE = 'due';

    public const BACK_PAY_NOT_DUE = 'not_due';

    public const BACK_PAY_PAID = 'paid';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'without_pay' => 'boolean',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'served_at' => 'datetime',
            'posting_ends_on' => 'date:Y-m-d',
            'lifted_on' => 'date:Y-m-d',
            'back_pay_paid_on' => 'date:Y-m-d',
        ];
    }

    public function isInForce(?CarbonInterface $on = null): bool
    {
        $day = ($on ?? now())->copy()->startOfDay();

        return $this->lifted_on === null && ($this->ends_on === null || $this->ends_on->gte($day));
    }

    public function disciplinaryCase(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }
}
