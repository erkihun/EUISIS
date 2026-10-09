<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationScopeType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOrganizationScope extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'user_id',
        'organization_id',
        'scope_type',
        'service_provider_id',
        'service_type_id',
        'effective_from',
        'effective_to',
        'is_active',
        'assigned_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => OrganizationScopeType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'bool',
            'metadata' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(static function (Builder $q) use ($today): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
            })
            ->where(static function (Builder $q) use ($today): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
