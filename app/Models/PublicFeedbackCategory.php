<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category a visitor may pick for a submission (brief §31). City-wide when
 * it belongs to no organization; otherwise one institution's own. Applies by
 * submission type and by target type. Once a submission refers to it, it is
 * retired (`is_active = false`), never deleted (brief §33).
 */
class PublicFeedbackCategory extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_feedback_categories';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'description_en',
        'description_am',
        'submission_types',
        'target_types',
        'organization_id',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'submission_types' => 'array',
            'target_types' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(EmployeeServiceFeedback::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** City-wide categories plus the ones belonging to this organization. */
    public function scopeVisibleToOrganization(Builder $query, ?string $organizationId): Builder
    {
        return $query->where(function (Builder $scope) use ($organizationId): void {
            $scope->whereNull('organization_id');

            if ($organizationId !== null) {
                $scope->orWhere('organization_id', $organizationId);
            }
        });
    }

    public function isCityWide(): bool
    {
        return $this->organization_id === null;
    }

    public function appliesTo(ServiceFeedbackType $type, PublicFeedbackTargetType $target): bool
    {
        return in_array($type->value, (array) $this->submission_types, true)
            && in_array($target->value, (array) $this->target_types, true);
    }

    public function isReferenced(): bool
    {
        return $this->submissions()->exists();
    }

    public function localizedName(string $locale): string
    {
        $amharic = trim((string) $this->name_am);

        return $locale === 'am' && $amharic !== '' ? $amharic : (string) $this->name_en;
    }
}
