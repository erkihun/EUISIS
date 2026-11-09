<?php

declare(strict_types=1);

namespace Modules\FieldWork\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;

/**
 * A field work type (its names and order), published for reporting outside
 * the module (the Situation Room). Writes are refused.
 */
final class FieldWorkTypeRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'field_work_types';

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    /** The name stored in polymorphic columns, as for the Field work module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\FieldWorkType';
    }
}
