<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Http\Request;

readonly class CreatePublicHolidayAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(array $attributes, User $actor, ?Request $request = null): PublicHoliday
    {
        $holiday = PublicHoliday::query()->create([
            'name_en'         => trim($attributes['name_en']),
            'name_am'         => isset($attributes['name_am']) ? trim($attributes['name_am']) : null,
            'holiday_date'    => $attributes['holiday_date'],
            'is_recurring'    => (bool) ($attributes['is_recurring'] ?? false),
            'recurrence_type' => $attributes['recurrence_type'] ?? null,
            'is_active'       => (bool) ($attributes['is_active'] ?? true),
            'description'     => $attributes['description'] ?? null,
            'created_by'      => $actor->id,
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::PublicHolidayCreated,
            $actor,
            $holiday,
            null,
            newValues: ['name_en' => $holiday->name_en, 'holiday_date' => $holiday->holiday_date],
            request: $request,
        );

        return $holiday;
    }
}
