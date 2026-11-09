<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class OrganizationClosurePath extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'hierarchy_version_id',
        'ancestor_organization_id',
        'descendant_organization_id',
        'depth',
    ];
}
