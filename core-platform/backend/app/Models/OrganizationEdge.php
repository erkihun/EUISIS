<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrganizationEdge extends Model
{
    use HasUuids;

    public const STRUCTURAL = 'structural_parent';

    /** The relationship new parent edges get: the one the city's own published hierarchy uses. */
    public const REPORTS_TO = 'reports_to';

    /**
     * The edges that make the organization tree (who sits under whom), and so
     * every scope. The city's published hierarchy is recorded as reports_to;
     * structural_parent means the same. Oversight, coordination and the other
     * relationship kinds do not place an organization in the tree.
     */
    public const TREE_TYPES = [self::REPORTS_TO, self::STRUCTURAL];

    protected $guarded = ['id'];
}
