<?php

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\RespondentCaseView;
use Laravel\Sanctum\HasApiTokens;

class Respondent extends Authenticatable
{
    protected $table = 'court_respondents';

    use HasApiTokens, Notifiable;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'position',
        'organization_name',
        'address',
        'national_id',
        'phone',
        'email',
        'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function caseViews(): HasMany
    {
        return $this->hasMany(RespondentCaseView::class);
    }

    public function viewedCases(): BelongsToMany
    {
        return $this->belongsToMany(
            CourtCase::class,
            'court_respondent_case_views',
            'respondent_id',
            'case_id'
        )
        ->withPivot('viewed_at')
        ->withTimestamps();
    }
}
