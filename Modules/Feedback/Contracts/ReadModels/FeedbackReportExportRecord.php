<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report export kept with the feedback exports, for the Situation Room's
 * own exports (listing, download, the queued build). Writes are refused; they
 * go through the FeedbackReportExports owner command.
 */
final class FeedbackReportExportRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'public_feedback_report_exports';

    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer', 'completed_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->path !== null;
    }

    /** The name stored in polymorphic columns (audit logs), as for the Feedback module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\PublicFeedbackReportExport';
    }
}
