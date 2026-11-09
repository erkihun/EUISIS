<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\ReviewCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reviewer's comment against a section (and optionally a line) of a submission. */
class ReviewComment extends Model
{
    /** @use HasFactory<ReviewCommentFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_review_comments';

    protected $fillable = [
        'submission_id',
        'section',
        'line_reference',
        'body',
        'author_id',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'section' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReviewCommentFactory
    {
        return ReviewCommentFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
