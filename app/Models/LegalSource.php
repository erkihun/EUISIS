<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalSourceStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A legal or administrative instrument that design records cite as their
 * evidence (ADR-LB-001). `organization_id` null is a city-level source that
 * every institution may cite; otherwise the source belongs to one institution.
 */
class LegalSource extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'code', 'title_en', 'title_am', 'source_type_id', 'issuing_authority', 'document_number',
        'issue_date', 'effective_from', 'effective_to', 'status', 'description_en', 'description_am', 'source_url',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'status' => LegalSourceStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sourceType(): BelongsTo
    {
        return $this->belongsTo(LegalInstrumentType::class, 'source_type_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LegalSourceAttachment::class)->orderBy('created_at');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(LegalSourceCitation::class)->orderBy('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCityLevel(): bool
    {
        return $this->organization_id === null;
    }

    /** Sources an institution may cite: its own and the city-level ones. */
    public function scopeCitableBy(Builder $query, string $organizationId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId));
    }
}
