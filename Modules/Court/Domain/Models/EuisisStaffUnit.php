<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An EUISIS organization unit the court counts as its own: its staff are court staff. */
class EuisisStaffUnit extends Model
{
    protected $table = 'court_euisis_staff_units';

    protected $primaryKey = 'unit_uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['include_sub_units' => 'boolean'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(EuisisOrganizationUnit::class, 'unit_uuid', 'uuid');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(EuisisOrganization::class, 'organization_uuid', 'uuid');
    }
}
