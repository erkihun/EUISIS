<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CodeRule extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'entity_type',
        'scope_type',
        'scope_id',
        'active_scope_key',
        'name_en',
        'name_am',
        'prefix',
        'suffix',
        'format',
        'separator',
        'sequence_length',
        'next_number',
        'initial_sequence_number',
        'sequence_scope_strategy',
        'sequence_scope_tokens',
        'reset_frequency',
        'last_reset_at',
        'year_format',
        'is_active',
        'allow_manual_override',
        'require_approval_for_override',
        'description_en',
        'description_am',
        'metadata',
        'created_by',
        'updated_by',
        'deleted_by',
        'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => CodeRuleEntityType::class,
            'reset_frequency' => CodeRuleResetFrequency::class,
            'sequence_scope_strategy' => CodeRuleScopeStrategy::class,
            'sequence_length' => 'integer',
            'next_number' => 'integer',
            'initial_sequence_number' => 'integer',
            'last_reset_at' => 'datetime',
            'is_active' => 'bool',
            'allow_manual_override' => 'bool',
            'require_approval_for_override' => 'bool',
            'metadata' => 'array',
            'sequence_scope_tokens' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function generationLogs(): HasMany
    {
        return $this->hasMany(CodeGenerationLog::class);
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(CodeRuleSequence::class);
    }

    public static function buildActiveScopeKey(
        CodeRuleEntityType|string $entityType,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): string {
        $entityValue = $entityType instanceof CodeRuleEntityType ? $entityType->value : $entityType;

        return implode('|', [
            $entityValue,
            $scopeType ?: 'global',
            $scopeId ?: 'global',
        ]);
    }
}
