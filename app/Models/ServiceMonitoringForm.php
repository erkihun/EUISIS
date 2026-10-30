<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceMonitoring\FormStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of an administrator-built monitoring form (docs/service-monitoring.md).
 *
 * @property string $id
 * @property string $code
 * @property int $version
 * @property string $name_am
 * @property string|null $name_en
 * @property string|null $description
 * @property FormStatus $status
 * @property array<string, mixed> $schema
 */
class ServiceMonitoringForm extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'code',
        'version',
        'name_am',
        'name_en',
        'description',
        'status',
        'schema',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => FormStatus::class,
            'schema' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function organizationTypes(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationType::class, 'service_monitoring_form_organization_type', 'form_id', 'organization_type_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ServiceMonitoringSubmission::class, 'form_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FormStatus::Published->value);
    }

    /** True when an organization of this type is expected to fill the form. */
    public function appliesToOrganization(Organization $organization): bool
    {
        return $organization->organization_type_id !== null
            && $this->organizationTypes()->whereKey($organization->organization_type_id)->exists();
    }
}
