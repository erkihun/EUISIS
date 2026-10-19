<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question asked of the Knowledge Assistant, stored redacted with how it was
 * answered: `ai` (grounded synthesis through the gateway), `extractive`
 * (quoted passages only) or `none` (the assistant is off). A viewer sees
 * only their own questions.
 */
class IntelligenceKnowledgeQuestion extends Model
{
    use HasUuidPrimaryKey;

    public const MODES = ['ai', 'extractive', 'none'];

    public $timestamps = false;

    protected $fillable = ['user_id', 'question', 'answer', 'grounded', 'citations', 'ai_run_id', 'mode', 'created_at'];

    protected function casts(): array
    {
        return [
            'grounded' => 'boolean',
            'citations' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
