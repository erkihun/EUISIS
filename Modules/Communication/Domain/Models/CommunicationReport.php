<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Communication\Domain\Enums\ReportCategory;
use Modules\Communication\Domain\Enums\ReportStatus;
use Modules\Communication\Domain\Enums\ReportSubject;

/** A report of a message or conversation. It opens only the reported content to a moderator. */
class CommunicationReport extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'subject_type' => ReportSubject::class,
            'category' => ReportCategory::class,
            'status' => ReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
