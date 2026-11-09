<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrieval unit of a knowledge document: a heading section, or a window
 * of it (Chunker). Chunks carry no access rules of their own; every query
 * joins the document and applies its filters first.
 */
class IntelligenceKnowledgeChunk extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['document_id', 'position', 'heading', 'content', 'char_count'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'char_count' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IntelligenceKnowledgeDocument::class, 'document_id');
    }
}
