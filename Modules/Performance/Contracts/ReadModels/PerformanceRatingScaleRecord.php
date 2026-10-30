<?php

declare(strict_types=1);

namespace Modules\Performance\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;

/**
 * A rating scale (result bands or competency levels): Assessment form versions
 * name the result scale they report on. Writes are refused; the Performance
 * module manages scales.
 */
final class PerformanceRatingScaleRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_rating_scales';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** The name stored in polymorphic columns, as for the Performance module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\PerformanceRatingScale';
    }
}
