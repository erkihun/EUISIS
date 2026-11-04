<?php

declare(strict_types=1);

namespace Modules\DailyWork\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\DailyWork\Domain\Enums\DailyActivityReviewMode;

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
