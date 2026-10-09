<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyActivityReviewMode;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One organization's daily activity review policy (see DailyActivityReviewMode). */
class DailyActivityReviewPolicy extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['organization_id', 'review_mode'];

    protected function casts(): array
    {
        return ['review_mode' => DailyActivityReviewMode::class];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
