<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PublicHoliday;
use App\Services\Calendar\EthiopianCalendarService;
use Illuminate\Database\Seeder;

/**
 * The fixed-date national public holidays of Ethiopia, as recurring entries in
 * the shared Public Holidays list. Statutory "working day" periods
 * (Regulation 201/2018), the grievance SLA clock and Daily Activity all skip
 * them through PublicHolidayCalendar.
 *
 * Ethiopian-calendar holidays recur on their Ethiopian month/day. The movable
 * holidays — Siklet (Good Friday), Fasika (Easter), Eid al-Fitr, Eid al-Adha
 * and Mawlid — follow lunar or paschal reckoning and must be entered each year
 * on the Public Holidays page.
 *
 * Idempotent: a holiday already listed by the same English name, or a
 * recurring one already on the same date, is left alone, so a re-run never
 * duplicates or overwrites an edit.
 */
class EthiopianPublicHolidaySeeder extends Seeder
{
    /** Reference Ethiopian year used to store each recurring date. */
    private const BASE_YEAR = 2018;

    public function run(): void
    {
        $ethiopian = app(EthiopianCalendarService::class);

        $holidays = [
            // [name_en, name_am, ethiopian month, ethiopian day]
            ['Ethiopian New Year (Enkutatash)', 'እንቁጣጣሽ (የዘመን መለወጫ)', 1, 1],
            ['Finding of the True Cross (Meskel)', 'የመስቀል በዓል', 1, 17],
            ['Ethiopian Christmas (Genna)', 'የገና በዓል', 4, 29],
            ['Epiphany (Timket)', 'የጥምቀት በዓል', 5, 11],
            ['Adwa Victory Day', 'የዓድዋ ድል በዓል', 6, 23],
            ["Patriots' Victory Day", 'የአርበኞች የድል በዓል', 8, 27],
            ['Downfall of the Derg', 'ግንቦት 20', 9, 20],
        ];

        foreach ($holidays as [$en, $am, $month, $day]) {
            $this->ensure($en, $am, $ethiopian->ethiopianToGregorian(self::BASE_YEAR, $month, $day)->toDateString(), 'ethiopian');
        }

        // Gregorian-calendar national holiday.
        $this->ensure('International Labour Day', 'የዓለም የሠራተኞች ቀን', '2026-05-01', 'gregorian');
    }

    private function ensure(string $nameEn, string $nameAm, string $date, string $recurrence): void
    {
        $exists = PublicHoliday::query()->where('name_en', $nameEn)->exists()
            || PublicHoliday::query()->where('is_recurring', true)->where('recurrence_type', $recurrence)->whereDate('holiday_date', $date)->exists();
        if ($exists) {
            return;
        }

        PublicHoliday::query()->create([
            'name_en' => $nameEn,
            'name_am' => $nameAm,
            'holiday_date' => $date,
            'is_recurring' => true,
            'recurrence_type' => $recurrence,
            'country_code' => 'ET',
            'is_active' => true,
            'description' => 'National public holiday (fixed date).',
        ]);
    }
}
