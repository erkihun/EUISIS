<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A case filed at the administrative tribunal against an institution registered
 * here (court-ms Flow 7, ADR 0013 #14), given to the officer who follows that
 * institution's court cases. court-ms is the system of record; this is the
 * institution's register entry.
 */
class InstitutionCourtCase extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'court_case_id',
        'organization_id',
        'case_number',
        'applicants',
        'case_type',
        'filed_at',
        'status',
        'review_status',
        'source',
        'officer_user_id',
        'assigned_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'court_case_id' => 'integer',
            'applicants' => 'array',
            'filed_at' => 'date:Y-m-d',
            'assigned_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_user_id');
    }
}
