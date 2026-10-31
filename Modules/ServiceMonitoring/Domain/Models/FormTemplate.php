<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\FormTemplateStatus;
use Modules\ServiceMonitoring\Domain\Forms\TemplateContent;

/**
 * One version of an administrator-built form template (docs/service-monitoring.md §Form templates).
 * A published version is assigned to organization types; a report started by
 * an institution of one of those types pins the version.
 *
 * @property string $id
 * @property string $code
 * @property int $version
 * @property FormTemplateStatus $status
 * @property array<string, mixed> $content
 */
class FormTemplate extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_form_templates';

    protected $fillable = [
        'code',
        'version',
        'name_am',
        'name_en',
        'description',
        'status',
        'content',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => FormTemplateStatus::class,
            'content' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function organizationTypes(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationType::class, 'service_monitoring_form_template_types', 'form_template_id', 'organization_type_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'form_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FormTemplateStatus::Published->value);
    }

    public function contentRules(): TemplateContent
    {
        return TemplateContent::from($this->content);
    }
}
