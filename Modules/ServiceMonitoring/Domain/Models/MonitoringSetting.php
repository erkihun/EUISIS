<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use Database\Factories\ServiceMonitoring\MonitoringSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The module settings: one row (ServiceMonitoringSettings creates it on first read). */
class MonitoringSetting extends Model
{
    /** @use HasFactory<MonitoringSettingFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_settings';

    protected $fillable = [
        'require_stamped_copy',
        'declaration_text_en',
        'declaration_text_am',
        'owner_organization_id',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'require_stamped_copy' => 'boolean',
        ];
    }

    protected static function newFactory(): MonitoringSettingFactory
    {
        return MonitoringSettingFactory::new();
    }

    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }
}
