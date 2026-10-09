<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaSpecialDay;
use App\Models\User;
use Illuminate\Http\Request;

readonly class UpdateCafeteriaSpecialDayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaSpecialDay $day, array $attributes, User $actor, ?Request $request = null): void
    {
        $old = $day->only(['special_date', 'name_en', 'day_type', 'is_open', 'is_subsidy_day']);

        $day->update([
            'special_date'         => $attributes['special_date'],
            'name_en'              => trim($attributes['name_en']),
            'name_am'              => isset($attributes['name_am']) ? trim($attributes['name_am']) : null,
            'day_type'             => $attributes['day_type'],
            'is_open'              => (bool) ($attributes['is_open'] ?? true),
            'is_subsidy_day'       => (bool) ($attributes['is_subsidy_day'] ?? false),
            'cafeteria_provider_id' => $attributes['cafeteria_provider_id'] ?? null,
            'organization_id'      => $attributes['organization_id'] ?? null,
            'open_time'            => $attributes['open_time'] ?? null,
            'close_time'           => $attributes['close_time'] ?? null,
            'reason_en'            => $attributes['reason_en'] ?? null,
            'reason_am'            => $attributes['reason_am'] ?? null,
            'updated_by'           => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaSpecialDayUpdated,
            $actor,
            $day,
            $day->organization_id,
            oldValues: $old,
            newValues: $day->only(['special_date', 'day_type', 'is_open', 'is_subsidy_day']),
            request: $request,
        );
    }
}
