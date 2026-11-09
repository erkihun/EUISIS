<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringSettings;
use Modules\ServiceMonitoring\Domain\Models\DriverOccupation;

/** Declaration text, the stamped-copy rule and the driver occupation list. */
final readonly class UpdateSettings
{
    public function __construct(private ServiceMonitoringSettings $settings, private ServiceMonitoringAudit $audit) {}

    /** @param array{require_stamped_copy: bool, declaration_text_en: string, declaration_text_am: string, owner_organization_id?: ?string, driver_occupation_ids: list<string>} $data */
    public function execute(array $data, User $actor): void
    {
        DB::transaction(function () use ($data, $actor): void {
            $row = $this->settings->row();
            $old = $row->only(['require_stamped_copy', 'declaration_text_en', 'declaration_text_am', 'owner_organization_id']) + ['driver_occupation_ids' => $this->settings->driverOccupationIds()];
            $row->fill([
                'require_stamped_copy' => $data['require_stamped_copy'],
                'declaration_text_en' => $data['declaration_text_en'],
                'declaration_text_am' => $data['declaration_text_am'],
                'owner_organization_id' => $data['owner_organization_id'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            $ids = array_values(array_unique($data['driver_occupation_ids']));
            DriverOccupation::query()->whereNotIn('occupation_id', $ids)->delete();
            $existing = DriverOccupation::query()->pluck('occupation_id')->map(fn ($id): string => (string) $id)->all();
            foreach (array_diff($ids, $existing) as $id) {
                DriverOccupation::query()->create(['occupation_id' => $id, 'created_by' => $actor->id]);
            }
            $this->settings->forget();

            $this->audit->record(AuditEventType::ServiceMonitoringSettingsUpdated, $actor, $row, null,
                ['require_stamped_copy' => $data['require_stamped_copy'], 'driver_occupation_ids' => $ids], old: $old);
        });
    }
}
