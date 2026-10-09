<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Http\Request;

readonly class UpdatePublicHolidayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(PublicHoliday $holiday, array $attributes, User $actor, ?Request $request = null): PublicHoliday
    {
        $oldValues = ['name_en' => $holiday->name_en, 'holiday_date' => $holiday->holiday_date?->toDateString()];

        $holiday->forceFill([
            'name_en'          => trim($attributes['name_en']),
            'name_am'          => isset($attributes['name_am']) ? trim($attributes['name_am']) : $holiday->name_am,
            'holiday_date'     => $attributes['holiday_date'] ?? $holiday->holiday_date,
            'is_recurring'     => isset($attributes['is_recurring']) ? (bool) $attributes['is_recurring'] : $holiday->is_recurring,
            'recurrence_type'  => $attributes['recurrence_type'] ?? $holiday->recurrence_type,
            'is_active'        => isset($attributes['is_active']) ? (bool) $attributes['is_active'] : $holiday->is_active,
            'description'      => $attributes['description'] ?? $holiday->description,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::PublicHolidayUpdated,
            $actor,
            $holiday,
            null,
            oldValues: $oldValues,
            newValues: ['name_en' => $holiday->name_en, 'holiday_date' => $holiday->holiday_date?->toDateString()],
            request: $request,
        );

        return $holiday;
    }
}
