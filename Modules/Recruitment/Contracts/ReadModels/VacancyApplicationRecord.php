<?php

declare(strict_types=1);

namespace Modules\Recruitment\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Modules\Recruitment\Contracts\Data\VacancyApplicationStatus;

/**
 * A vacancy application, for the Core transfer record it led to. Writes are
 * refused; the Recruitment module manages applications.
 */
final class VacancyApplicationRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'vacancy_applications';

    protected function casts(): array
    {
        return [
            'status' => VacancyApplicationStatus::class,
            'applied_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'screened_at' => 'datetime',
            'shortlisted_at' => 'datetime',
            'selected_at' => 'datetime',
            'rejected_at' => 'datetime',
            'screening_score' => 'decimal:2',
        ];
    }

    /** The name stored in polymorphic columns, as for the Recruitment module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\VacancyApplication';
    }
}
