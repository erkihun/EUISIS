<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recorded model call (docs/intelligence-ai-governance.md). */
class IntelligenceAiRun extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUSES = ['ok', 'failed', 'invalid', 'refused', 'unavailable'];

    protected $fillable = [
        'feature', 'prompt_key', 'prompt_version', 'provider', 'model', 'status', 'triggered_by_user_id', 'subject_type', 'subject_id',
        'input_summary', 'output', 'error', 'input_chars', 'output_tokens', 'duration_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_summary' => 'array',
            'output' => 'array',
            'input_chars' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
