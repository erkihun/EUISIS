<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table = 'court_roles';

    protected $fillable = ['name', 'description', 'mfa_required'];

    protected function casts(): array
    {
        return ['mfa_required' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'court_permission_role')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'court_role_user')->withTimestamps();
    }
}
