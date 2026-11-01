<?php

namespace Database\Seeders\Court;

use Illuminate\Database\Seeder;
use Modules\Court\Domain\Models\SystemSetting;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        SystemSetting::current(); // ensures row exists
    }
}
