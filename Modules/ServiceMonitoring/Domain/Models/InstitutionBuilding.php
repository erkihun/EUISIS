<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\InstitutionBuildingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Section II figures of one submission: one column per field of FormDefinition::section2Fields(). */
class InstitutionBuilding extends Model
{
    /** @use HasFactory<InstitutionBuildingFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_institution_building';

    protected $fillable = [
        'submission_id',
        'daycare_count',
        'cafeteria_count',
        'consumer_shop_count',
        'waiting_area_count',
        'waiting_area_capacity',
        'suggestion_box_count',
        'led_count',
        'signage_count',
        'employees_excl_drivers',
        'chest_badges_excl_drivers',
        'desk_badges',
        'awareness_trainings',
        'awareness_participants',
    ];

    protected function casts(): array
    {
        return [
            'daycare_count' => 'integer',
            'cafeteria_count' => 'integer',
            'consumer_shop_count' => 'integer',
            'waiting_area_count' => 'integer',
            'waiting_area_capacity' => 'integer',
            'suggestion_box_count' => 'integer',
            'led_count' => 'integer',
            'signage_count' => 'integer',
            'employees_excl_drivers' => 'integer',
            'chest_badges_excl_drivers' => 'integer',
            'desk_badges' => 'integer',
            'awareness_trainings' => 'integer',
            'awareness_participants' => 'integer',
        ];
    }

    protected static function newFactory(): InstitutionBuildingFactory
    {
        return InstitutionBuildingFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
