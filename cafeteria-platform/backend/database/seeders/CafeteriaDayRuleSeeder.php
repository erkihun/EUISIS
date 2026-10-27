<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CafeteriaDayRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CafeteriaDayRuleSeeder extends Seeder
{
    public function run(): void
    {
        $days = [
            ['day' => 1, 'name' => 'Monday',    'open' => true,  'subsidy' => true],
            ['day' => 2, 'name' => 'Tuesday',   'open' => true,  'subsidy' => true],
            ['day' => 3, 'name' => 'Wednesday', 'open' => true,  'subsidy' => true],
            ['day' => 4, 'name' => 'Thursday',  'open' => true,  'subsidy' => true],
            ['day' => 5, 'name' => 'Friday',    'open' => true,  'subsidy' => true],
            ['day' => 6, 'name' => 'Saturday',  'open' => false, 'subsidy' => false],
            ['day' => 7, 'name' => 'Sunday',    'open' => false, 'subsidy' => false],
        ];

        foreach ($days as $d) {
            CafeteriaDayRule::query()->updateOrCreate(
                ['day_of_week' => $d['day']],
                [
                    'id' => (string) Str::uuid(),
                    'is_open' => $d['open'],
                    'is_subsidy_day' => $d['subsidy'],
                    'is_active' => true,
                ],
            );
        }
    }
}
