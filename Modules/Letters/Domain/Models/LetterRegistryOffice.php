<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registry office (መዝገብ ቤት): a unit of the institution's structure
 * designated as its registry. The unit, its name and the people who work in
 * it are read from the structure, never typed again here. Forwarding a letter
 * starts from the registry office.
 */
class LetterRegistryOffice extends Model
{
    use HasUuidPrimaryKey;

    public const STATUSES = ['active', 'inactive'];

    protected $table = 'letter_registry_offices';

    protected $fillable = ['organization_id', 'organization_unit_id', 'status', 'note', 'created_by', 'updated_by'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }
}
