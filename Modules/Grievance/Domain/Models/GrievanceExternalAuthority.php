<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authority outside the grievance-handling bodies (e.g. an Administrative Tribunal) a case can be routed to.
 */
class GrievanceExternalAuthority extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_external_authorities';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'organization_id',
        'is_administrative_tribunal',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_administrative_tribunal' => 'bool',
            'is_active' => 'bool',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
