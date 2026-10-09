<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackTokenStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see EmployeeFeedbackTokenService
 */
class EmployeeFeedbackToken extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'token',
        'status',
        'created_by',
        'revoked_by',
        'revoked_at',
        'last_scanned_at',
        'scan_count',
    ];

    /**
     * The raw token is the only credential guarding the public page, so it is
     * withheld from array/JSON output. Views that legitimately need it (the QR
     * card for an authorised admin) read `$token->token` explicitly.
     */
    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'status' => FeedbackTokenStatus::class,
            'revoked_at' => 'datetime',
            'last_scanned_at' => 'datetime',
            'scan_count' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(EmployeeServiceFeedback::class, 'employee_feedback_token_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptsFeedback(): bool
    {
        return $this->status->acceptsFeedback();
    }

    /** Absolute URL encoded into the printed QR. */
    public function publicUrl(): string
    {
        return route('service-feedback.show', $this->token);
    }
}
