<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The person who follows an institution's cases at the administrative tribunal
 * (court-ms Flow 7, ADR 0013 #14): every new case against the institution goes
 * to them.
 */
class CourtCaseOfficer extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['organization_id', 'user_id', 'assigned_by_user_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
