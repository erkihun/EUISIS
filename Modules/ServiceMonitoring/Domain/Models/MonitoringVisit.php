<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Database\Factories\ServiceMonitoring\MonitoringVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\VisitStatus;

/** A monitoring visit to a participant (D22), with its team, findings and action items. */
class MonitoringVisit extends Model
{
    /** @use HasFactory<MonitoringVisitFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_visits';

    protected $fillable = [
        'participant_id',
        'period_id',
        'visited_on',
        'purpose',
        'summary',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'visited_on' => 'date:Y-m-d',
            'status' => VisitStatus::class,
        ];
    }

    protected static function newFactory(): MonitoringVisitFactory
    {
        return MonitoringVisitFactory::new();
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'period_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'service_monitoring_visit_members', 'visit_id', 'user_id')->withTimestamps();
    }

    public function findings(): HasMany
    {
        return $this->hasMany(VisitFinding::class, 'visit_id')->orderBy('created_at');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'visit_id')->orderBy('due_on');
    }

    /**
     * Sets the team. The pivot rows have their own UUID key, so they are
     * written as VisitMember records rather than through sync().
     *
     * @param  list<int>  $userIds
     */
    public function syncMembers(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        VisitMember::query()->where('visit_id', $this->id)->whereNotIn('user_id', $userIds)->delete();
        $existing = VisitMember::query()->where('visit_id', $this->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_diff($userIds, $existing) as $userId) {
            VisitMember::query()->create(['visit_id' => $this->id, 'user_id' => $userId]);
        }
    }
}
