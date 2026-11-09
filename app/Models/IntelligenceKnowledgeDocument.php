<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge source the Knowledge Assistant may quote once it is approved
 * (docs/intelligence-knowledge.md). Classification, organization scope and
 * the required permission are the retrieval filters: they are applied in the
 * query, before any passage is read. The body is never written to an audit row.
 */
class IntelligenceKnowledgeDocument extends Model
{
    use HasUuidPrimaryKey;

    public const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];

    public const STATUSES = ['draft', 'approved', 'retired'];

    public const SOURCE_TYPES = ['manual', 'repo_doc', 'upload'];

    public const LANGUAGES = ['en', 'am'];

    protected $fillable = [
        'title', 'slug', 'source_type', 'source_path', 'classification', 'organization_id', 'required_permission', 'language',
        'status', 'version', 'checksum', 'body', 'approved_by', 'approved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(IntelligenceKnowledgeChunk::class, 'document_id')->orderBy('position');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
