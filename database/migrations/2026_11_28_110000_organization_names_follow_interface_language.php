<?php

declare(strict_types=1);

use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organization names follow the interface language.
 *
 * `localization.organization_name_display` was seeded as `english`, which
 * pinned every institution name to English even for a reader who had chosen
 * Amharic. The default is now `locale_based`. A stored `english` is the old
 * seeded default rather than a decision, so it moves too; `amharic` and
 * `both` were chosen on purpose and stay. An administrator who does want
 * English everywhere can pick it again in Settings > Localization.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('system_settings')
            ->where('group', 'localization')
            ->where('key', 'organization_name_display');

        (clone $setting)->update([
            'default_value' => 'locale_based',
            'options' => json_encode(['locale_based', 'english', 'amharic', 'both']),
        ]);

        (clone $setting)->where('value', 'english')->update(['value' => 'locale_based']);

        app(SystemSettingsService::class)->clearCache();
    }

    public function down(): void
    {
        $setting = DB::table('system_settings')
            ->where('group', 'localization')
            ->where('key', 'organization_name_display');

        (clone $setting)->update([
            'default_value' => 'english',
            'options' => json_encode(['english', 'amharic', 'both']),
        ]);

        (clone $setting)->where('value', 'locale_based')->update(['value' => 'english']);

        app(SystemSettingsService::class)->clearCache();
    }
};
