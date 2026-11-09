<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One organization an external application may read data of (F2).
 * `scope_type` is `self` (that organization only) or `subtree` (it and its
 * descendants in the published hierarchy), the same two modes human
 * organization scopes use, expanded by OrganizationScopeService.
 */
class ExternalApplicationOrganizationScope extends Model
{
    use HasUuidPrimaryKey;

    public const TYPES = ['self', 'subtree'];

    protected $fillable = ['external_application_id', 'organization_id', 'scope_type', 'created_by'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(ExternalApplication::class, 'external_application_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
