<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An EUISIS employee who is a party to a court-ms case; only those are cached. */
class EuisisEmployee extends Model
{
    protected $table = 'court_euisis_employees';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['synced_at' => 'datetime', 'last_sequence' => 'integer'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(EuisisOrganization::class, 'organization_uuid', 'uuid');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(EuisisPosition::class, 'position_uuid', 'uuid');
    }

    public function displayName(): string
    {
        return (string) ($this->full_name ?: $this->full_name_en ?: $this->employee_number);
    }
}
