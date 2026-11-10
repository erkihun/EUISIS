<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Letters\Domain\Enums\TemplateCategory;
use Modules\Letters\Domain\Enums\TemplateStatus;

/**
 * A letter template: the draft text being edited, plus the published versions
 * letters are composed from. `owner_organization_id` null means a platform
 * template offered to every institution.
 */
class LetterTemplate extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'letter_templates';

    protected $fillable = [
        'owner_organization_id', 'code', 'title_en', 'title_am', 'category', 'language', 'status', 'current_version_no',
        'draft_subject', 'draft_body', 'draft_placeholders', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
            'status' => TemplateStatus::class,
            'current_version_no' => 'int',
            'draft_placeholders' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LetterTemplateVersion::class, 'template_id')->orderByDesc('version_no');
    }

    public function currentVersion(): ?LetterTemplateVersion
    {
        return $this->current_version_no > 0
            ? $this->versions()->where('version_no', $this->current_version_no)->first()
            : null;
    }

    public function isPlatform(): bool
    {
        return $this->owner_organization_id === null;
    }

    /** Offered to an institution: published and either platform-wide or that institution's own. */
    public function isOfferedTo(string $organizationId): bool
    {
        return $this->status === TemplateStatus::Published
            && ($this->owner_organization_id === null || $this->owner_organization_id === $organizationId);
    }
}
