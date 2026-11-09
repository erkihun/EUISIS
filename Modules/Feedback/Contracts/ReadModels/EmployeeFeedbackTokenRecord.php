<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Modules\Feedback\Contracts\Data\FeedbackTokenStatus;

/**
 * An employee's feedback QR token, for the Core's employee relations and the
 * printed ID card's feedback link. The raw token is hidden from array output
 * as on the owner's model; writes are refused (the Feedback module issues,
 * suspends and revokes tokens).
 */
final class EmployeeFeedbackTokenRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'employee_feedback_tokens';

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'status' => FeedbackTokenStatus::class,
            'revoked_at' => 'datetime',
            'last_scanned_at' => 'datetime',
            'scan_count' => 'integer',
        ];
    }

    /** Absolute URL encoded into the printed QR. */
    public function publicUrl(): string
    {
        return route('service-feedback.show', $this->token);
    }

    /** The name stored in polymorphic columns, as for the Feedback module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\EmployeeFeedbackToken';
    }
}
