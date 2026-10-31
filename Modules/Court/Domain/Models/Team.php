<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\CourtStaffMember as User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $table = 'court_teams';

    protected $fillable = ['name', 'parent_id', 'description', 'team_leader_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'court_team_user')->withTimestamps();
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }
}
