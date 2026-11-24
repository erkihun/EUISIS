<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

/** An exemption, cancellation or reinstatement, effective from a moment. */
final class ApplicabilityDecision extends ReportingModel
{
    protected $table = 'reporting_applicability_decisions';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
