<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReceptionDesk extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['organization_id', 'organization_unit_id', 'code', 'name_en', 'name_am', 'location_en', 'location_am', 'operating_hours', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['operating_hours' => 'array', 'is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class);
    }

    public function counters(): HasMany
    {
        return $this->hasMany(ReceptionCounter::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reception_desk_staff')->withPivot(['id', 'role', 'is_active'])->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'reception_desk_service')->withTimestamps();
    }
}
