<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An institution's reply-time standard (docs/letters-research.md §6): how
 * many working days a letter of each priority gets, when to remind the holder
 * to send an interim reply, and after how many overdue days the head is told.
 * A null organization is the platform default.
 */
class LetterStandard extends Model
{
    use HasUuidPrimaryKey;

    /** @var array<string, int> */
    public const DEFAULTS = ['reply_days_normal' => 10, 'reply_days_urgent' => 3, 'reply_days_very_urgent' => 1, 'interim_reply_days' => 2, 'escalation_after_days' => 2];

    protected $table = 'letter_standards';

    protected $fillable = ['organization_id', 'reply_days_normal', 'reply_days_urgent', 'reply_days_very_urgent', 'interim_reply_days', 'escalation_after_days', 'updated_by'];

    protected function casts(): array
    {
        return ['reply_days_normal' => 'int', 'reply_days_urgent' => 'int', 'reply_days_very_urgent' => 'int', 'interim_reply_days' => 'int', 'escalation_after_days' => 'int'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
