<?php

declare(strict_types=1);

namespace Modules\Discipline\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Modules\Discipline\Contracts\Data\DisciplinaryCaseStatus;

/**
 * A discipline case, published so other modules can recognise and read one
 * (a statutory deadline's subject). Writes are refused.
 */
final class DisciplinaryCaseRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'disciplinary_cases';

    protected function casts(): array
    {
        return ['status' => DisciplinaryCaseStatus::class];
    }

    /** The name stored in polymorphic columns, as for the Discipline module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\DisciplinaryCase';
    }
}
