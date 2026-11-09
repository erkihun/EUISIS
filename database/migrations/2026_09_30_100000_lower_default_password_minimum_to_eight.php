<?php

declare(strict_types=1);

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Move the previous default, preserving explicitly stricter settings.
        DB::table('system_settings')
            ->where('group', 'security')
            ->where('key', 'password_min_length')
            ->where('value', '15')
            ->update(['value' => '8']);

        app(SystemSettingsService::class)->clearCache();
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('group', 'security')
            ->where('key', 'password_min_length')
            ->where('value', '8')
            ->update(['value' => '15']);

        app(SystemSettingsService::class)->clearCache();
    }
};
