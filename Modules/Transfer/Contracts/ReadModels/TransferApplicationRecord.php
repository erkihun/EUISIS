<?php

declare(strict_types=1);

namespace Modules\Transfer\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;

/**
 * An employee's transfer application, for the Core employee's relation.
 * Writes are refused; the Transfer module manages applications.
 */
final class TransferApplicationRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $table = 'transfer_applications';

    protected function casts(): array
    {
        return [
            'status' => TransferApplicationStatus::class,
            'eligibility_snapshot' => 'array',
            'source_assignment_snapshot' => 'array',
            'destination_snapshot' => 'array',
            'selected_at' => 'datetime',
            'submitted_at' => 'datetime',
            'effective_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'implemented_at' => 'datetime',
            'implementation_failed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** The name stored in polymorphic columns, as for the Transfer module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\TransferApplication';
    }
}
