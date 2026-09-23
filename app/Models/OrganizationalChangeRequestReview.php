<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationalChangeReviewAction;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An append-only record of one reviewer decision. */
class OrganizationalChangeRequestReview extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organizational_change_request_reviews';

    protected $fillable = [
        'request_id',
        'reviewer_id',
        'stage',
        'action',
        'comment',
        'revision',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => OrganizationalChangeReviewAction::class,
            'reviewed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OrganizationalChangeRequest::class, 'request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
