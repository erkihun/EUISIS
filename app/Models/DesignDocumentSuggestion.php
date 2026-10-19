<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suggestions the assistant drew from a source-document passage (ADR-LB-006).
 * `items` is a list of {title, kind, legal_reference, uncertain, state,
 * record_id}; `state` is pending, applied or rejected. Nothing here is a
 * design record until a person applies an item.
 */
class DesignDocumentSuggestion extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_READY = 'ready';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['organization_id', 'mandate_id', 'legal_source_id', 'kind', 'status', 'passage_sha256', 'passage_chars', 'items', 'notes', 'ai_run_id', 'created_by'];

    protected function casts(): array
    {
        return ['items' => 'array', 'passage_chars' => 'integer'];
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(InstitutionMandate::class, 'mandate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
