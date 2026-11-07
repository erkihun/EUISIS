<?php

declare(strict_types=1);

namespace Modules\TipOffs\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;

/**
 * A public Tip-off (ጥቆማ), in the table this module owns (ADR 0032). The
 * shared intake and case handling (Feedback) write the row through the
 * PublicSubmissionType this module registers; this model reads it for the
 * module's own pages and checks.
 */
final class TipOff extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_tip_offs';

    /** Written through the intake (Feedback), never mass-assigned here. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ServiceFeedbackStatus::class,
            'tip_details' => 'encrypted:array',
            'submitted_at' => 'datetime',
        ];
    }
}
