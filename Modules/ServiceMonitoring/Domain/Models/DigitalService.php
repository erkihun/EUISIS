<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Service;
use App\Models\User;
use Database\Factories\ServiceMonitoring\DigitalServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ServiceMonitoring\Domain\Enums\ServiceLevel;
use Modules\ServiceMonitoring\Domain\Enums\TechnologyType;
use Modules\ServiceMonitoring\Domain\Enums\VerificationStatus;

/** Section III: a service delivered with technology (D20). Main services may have sub-service rows beneath them. Only verified rows count. */
class DigitalService extends Model
{
    /** @use HasFactory<DigitalServiceFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_digital_services';

    protected $fillable = [
        'submission_id',
        'sequence',
        'service_id',
        'parent_entry_id',
        'level',
        'service_name_am',
        'service_name_en',
        'is_catalog_gap',
        'technology_type',
        'technology_other',
        'system_name',
        'system_count',
        'go_live_on',
        'remark',
        'verification_status',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'level' => ServiceLevel::class,
            'is_catalog_gap' => 'boolean',
            'technology_type' => TechnologyType::class,
            'system_count' => 'integer',
            'go_live_on' => 'date:Y-m-d',
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DigitalServiceFactory
    {
        return DigitalServiceFactory::new();
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entry_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entry_id')->orderBy('sequence');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
