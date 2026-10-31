<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/** An EUISIS organization unit mirrored from the EUISIS partner API. */
class EuisisOrganizationUnit extends Model
{
    protected $table = 'court_euisis_organization_units';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['synced_at' => 'datetime', 'last_sequence' => 'integer'];
}
