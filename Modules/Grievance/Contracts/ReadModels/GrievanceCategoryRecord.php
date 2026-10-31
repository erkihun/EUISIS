<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;

/** A grievance category (its code and names), published with the grievance read model. Writes are refused. */
final class GrievanceCategoryRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'grievance_categories';

    protected function casts(): array
    {
        return ['is_active' => 'bool', 'sort_order' => 'int'];
    }

    /** The name stored in polymorphic columns, as for the Grievance module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\GrievanceCategory';
    }
}
