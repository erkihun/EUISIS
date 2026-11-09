<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Database\Factories\ServiceMonitoring\ParticipantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;

/** An institution in the reporting registry (D7): its level, and for a woreda the sub-city it reports through. */
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_participants';

    protected $fillable = [
        'organization_id',
        'level',
        'parent_participant_id',
        'active_from',
        'active_to',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'level' => ReportingLevel::class,
            'active_from' => 'date:Y-m-d',
            'active_to' => 'date:Y-m-d',
        ];
    }

    protected static function newFactory(): ParticipantFactory
    {
        return ParticipantFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_participant_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_participant_id');
    }

    public function supportOfficers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'service_monitoring_support_assignments', 'participant_id', 'user_id')->withTimestamps();
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** Rows in the registry on $date (Gregorian Y-m-d). */
    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query->where('active_from', '<=', $date)
            ->where(fn (Builder $q): Builder => $q->whereNull('active_to')->orWhere('active_to', '>=', $date));
    }

    public function formType(): FormType
    {
        return $this->level->formType();
    }
}
