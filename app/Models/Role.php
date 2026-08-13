<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScopeType;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'scope_type',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => RoleScopeType::class,
        ];
    }

    public function isScoped(): bool
    {
        return $this->scope_type === RoleScopeType::Scoped;
    }

    public function isGlobal(): bool
    {
        return $this->scope_type === RoleScopeType::Global;
    }

    public function canBeAssignedBy(User $actor): bool
    {
        if ($this->isGlobal() || $this->isProtected()) {
            return $actor->hasAnyRole(['Super Admin', 'System Admin']);
        }

        return $actor->can('users.assignRoles');
    }

    public function isProtected(): bool
    {
        return in_array($this->name, [
            'Super Admin',
            'System Admin',
            'City Admin',
            'Public Service Bureau Admin',
            'Security Settings Manager',
        ], true);
    }
}
