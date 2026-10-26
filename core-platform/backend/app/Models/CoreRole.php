<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Core role (ADR 0009): a named set of permissions. Accounts name their role
 * by its key (`users.role`); the three system roles cannot be deleted.
 * `scope_type` global marks a role meant only for city-wide accounts.
 */
class CoreRole extends Model
{
    protected $fillable = ['key', 'name_en', 'name_am', 'description', 'scope_type', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CoreRolePermission::class);
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->permissions->pluck('permission')->sort()->values()->all();
    }
}
