<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CodeRuleEntityType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CodeGenerationLog extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'code_rule_id',
        'entity_type',
        'entity_id',
        'generated_code',
        'sequence_number',
        'generated_by',
        'generated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => CodeRuleEntityType::class,
            'sequence_number' => 'integer',
            'generated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function codeRule(): BelongsTo
    {
        return $this->belongsTo(CodeRule::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
