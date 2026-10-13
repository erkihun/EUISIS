<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PublicPageSection;
use App\Services\Branding\BrandingService;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves stored *defaults* of the former product identity to Addis One
 * (rebrand brief §30, §58–§59). Only values that equal a known legacy
 * default are touched; anything an administrator typed is reported as
 * NEEDS_REVIEW and left alone. Historical records (audit logs, issued
 * documents, exports) are never read or written. Safe to run repeatedly.
 */
class BrandingMigrateAddisOne extends Command
{
    protected $signature = 'branding:migrate-addis-one {--dry-run : Report without writing}';

    protected $description = 'Move stored legacy product-name defaults to Addis One / AA-IEMSS, clear the settings cache and report remaining legacy references (idempotent)';

    /** Stored default → new value, per settings row. */
    private const SETTING_MOVES = [
        'general' => [
            'application_name' => [
                ['Addis Ababa Employee Unified ID & Service Platform', 'EUISIS', 'Employee Unified ID and Service Integration System', 'Employee Unified ID & Service Integration System', 'Unified Employee ID & Service Integration System'],
                'branding.name',
            ],
            'application_short_name' => [['AA Employee ID', 'EUISIS'], 'branding.short_name'],
        ],
        'branding' => [
            'app_name_en' => [['EUISIS'], 'branding.name'],
            'app_name_am' => [['ኢዩሲሲስ', 'EUISIS'], 'branding.name'],
        ],
    ];

    /** Public home intro titles seeded under the former identity. */
    private const INTRO_TITLES = [
        'title_en' => [['Unified Employee ID & Service Integration System', 'Employee Unified ID & Service Integration System'], 'branding.official_name_en'],
        'title_am' => [['የተቀናጀ የሠራተኛ መታወቂያ እና አገልግሎት ስርዓት', 'የሠራተኛ ተዋሃደ መታወቂያ እና አገልግሎት ስርዓት'], 'branding.official_name_am'],
    ];

    public function handle(BrandingService $branding, SystemSettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $review = [];

        foreach (self::SETTING_MOVES as $group => $keys) {
            foreach ($keys as $key => [$legacyValues, $configKey]) {
                $row = DB::table('system_settings')->where('group', $group)->where('key', $key)->first(['id', 'value']);
                if ($row === null) {
                    continue;
                }
                $current = trim((string) $row->value);
                $target = (string) config($configKey);
                if ($this->matches($current, $legacyValues)) {
                    if (! $dryRun) {
                        DB::table('system_settings')->where('id', $row->id)->update(['value' => $target, 'updated_at' => now()]);
                    }
                    $moved++;
                    $this->components->twoColumnDetail("{$group}.{$key}: \"{$current}\" → \"{$target}\"", $dryRun ? '<fg=yellow>DRY RUN</>' : '<fg=green>MOVED</>');
                } elseif ($branding->isLegacyName($current) || $this->mentionsLegacy($current, $branding)) {
                    $review[] = "setting {$group}.{$key} = \"{$current}\"";
                }
            }
        }

        // Every other current setting value that still mentions a former name: reported, never changed.
        foreach (DB::table('system_settings')->get(['group', 'key', 'value']) as $row) {
            if (isset(self::SETTING_MOVES[$row->group][$row->key])) {
                continue;
            }
            if ($this->mentionsLegacy((string) $row->value, $branding)) {
                $review[] = "setting {$row->group}.{$row->key} mentions a former product name";
            }
        }

        $sectionsMoved = 0;
        $intro = PublicPageSection::query()->where('page', 'home')->where('section_key', 'intro')->first();
        if ($intro !== null) {
            $changes = [];
            foreach (self::INTRO_TITLES as $column => [$legacyValues, $configKey]) {
                if ($this->matches((string) $intro->{$column}, $legacyValues)) {
                    $changes[$column] = (string) config($configKey);
                }
            }
            if ($changes !== []) {
                if (! $dryRun) {
                    $intro->forceFill($changes)->save();
                }
                $sectionsMoved++;
                $this->components->twoColumnDetail('public site home intro title: '.implode(', ', array_keys($changes)), $dryRun ? '<fg=yellow>DRY RUN</>' : '<fg=green>MOVED</>');
            }
        }
        foreach (PublicPageSection::query()->get() as $section) {
            foreach (['title_en', 'title_am', 'subtitle_en', 'subtitle_am', 'body_en', 'body_am'] as $column) {
                if ($this->mentionsLegacy((string) $section->{$column}, $branding) && ! ($section->section_key === 'intro' && in_array($column, ['title_en', 'title_am'], true) && $this->matches((string) $section->{$column}, [...self::INTRO_TITLES['title_en'][0], ...self::INTRO_TITLES['title_am'][0]]))) {
                    $review[] = "public site section {$section->page}/{$section->section_key}.{$column} mentions a former product name";
                }
            }
        }

        if (! $dryRun) {
            $settings->clearCache();
            PublicSiteContent::flush();
        }

        $this->newLine();
        $this->components->info(sprintf('%d setting(s) moved, %d public-site section(s) moved%s.', $moved, $sectionsMoved, $dryRun ? ' (dry run, nothing written)' : '; settings and public-site caches cleared on the shared store'));
        if ($review !== []) {
            $this->components->warn('NEEDS_REVIEW — administrator-entered content still naming the former product (left unchanged):');
            $this->components->bulletList(array_values(array_unique($review)));
        } else {
            $this->components->twoColumnDetail('Current settings and public-site content free of the former product name', '<fg=green>OK</>');
        }
        $this->components->twoColumnDetail('Brand now resolves to', $branding->brandName().' / '.$branding->shortName());
        $this->line('Historical audit entries, issued documents and exports are deliberately untouched (brief §31).');

        return self::SUCCESS;
    }

    /** @param  list<string>  $legacyValues */
    private function matches(string $value, array $legacyValues): bool
    {
        $needle = mb_strtolower(trim($value));

        return $needle !== '' && in_array($needle, array_map(fn (string $v): string => mb_strtolower(trim($v)), $legacyValues), true);
    }

    private function mentionsLegacy(string $value, BrandingService $branding): bool
    {
        $haystack = mb_strtolower($value);
        foreach ($branding->legacyNames() as $name) {
            if (str_contains($haystack, mb_strtolower($name))) {
                return true;
            }
        }

        return false;
    }
}
