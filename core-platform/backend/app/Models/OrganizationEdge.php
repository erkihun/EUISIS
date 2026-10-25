<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrganizationEdge extends Model
{
    use HasUuids;

    public const STRUCTURAL = 'structural_parent';

    protected $guarded = ['id'];
}
