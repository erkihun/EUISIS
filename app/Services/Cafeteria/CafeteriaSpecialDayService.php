<?php

declare(strict_types=1);

namespace App\Services\Cafeteria;

use App\Models\CafeteriaSpecialDay;
use Illuminate\Support\Carbon;

class CafeteriaSpecialDayService
{
    public function getForDate(Carbon $date): ?CafeteriaSpecialDay
    {
        return CafeteriaSpecialDay::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('special_date', $date->toDateString())
            ->first();
    }

    public function isOpenOn(Carbon $date): ?bool
    {
        $special = $this->getForDate($date);
        if ($special === null) {
            return null; // no override
        }

        return $special->is_open;
    }

    public function isSubsidyDayOn(Carbon $date): ?bool
    {
        $special = $this->getForDate($date);
        if ($special === null) {
            return null; // no override
        }

        return $special->is_subsidy_day;
    }

    /** Returns true if a special day exists and is an emergency closure. */
    public function isEmergencyClosure(Carbon $date): bool
    {
        $special = $this->getForDate($date);

        return $special !== null
            && $special->day_type->value === 'emergency_closure';
    }
}
