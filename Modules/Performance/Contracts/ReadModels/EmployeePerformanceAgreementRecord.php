<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts\ReadModels;

use App\Casts\DateOnly;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Modules\Performance\Contracts\Data\AgreementStatus;

/**
 * An employee's performance agreement, for the Core's competency ratings that
 * sit inside one. Writes are refused; the Performance module manages
 * agreements.
 */
final class EmployeePerformanceAgreementRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_performance_agreements';

    protected function casts(): array
    {
        return [
            'status' => AgreementStatus::class,
            'effective_from' => DateOnly::class,
            'effective_to' => DateOnly::class,
        ];
    }

    /** The name stored in polymorphic columns, as for the Performance module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\EmployeePerformanceAgreement';
    }
}
