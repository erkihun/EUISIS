<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeRuleSequence extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'code_rule_id',
        'sequence_scope_key',
        'sequence_scope_hash',
        'sequence_scope_values',
        'next_number',
        'last_number',
        'last_generated_code',
        'reset_frequency',
        'last_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_scope_values' => 'array',
            'next_number' => 'integer',
            'last_number' => 'integer',
            'last_reset_at' => 'datetime',
        ];
    }

    public function codeRule(): BelongsTo
    {
        return $this->belongsTo(CodeRule::class);
    }

    /** @param Builder<CodeRuleSequence> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('code_rule_id');
    }
}
