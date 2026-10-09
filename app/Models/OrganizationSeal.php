<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An institution's official seal: a controlled asset on the private disk. Applying it requires permission and is audited; users never upload a seal per letter.
 */
class OrganizationSeal extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organization_seals';

    protected $fillable = [
        'organization_id',
        'name',
        'disk',
        'path',
        'mime_type',
        'sha256',
        'status',
        'effective_from',
        'effective_to',
        'uploaded_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
