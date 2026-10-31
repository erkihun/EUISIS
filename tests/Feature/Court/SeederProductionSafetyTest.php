<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\HomeFaq;
use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Domain\Models\HomeSlide;
use Database\Seeders\Court\CourtCaseSeeder;
use Database\Seeders\Court\HomeLandingSeeder;
use Illuminate\Support\Facades\DB;

test('the sample case seeder adds nothing in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // Invoked directly: `db:seed` would stop to ask for confirmation in production.
    app(CourtCaseSeeder::class)->run();

    expect(DB::table('court_cases')->count())->toBe(0);
});

test('the landing seeder fills an empty install', function (): void {
    $this->seed(HomeLandingSeeder::class);

    expect(HomeSlide::query()->count())->toBeGreaterThan(0)
        ->and(HomeFaq::query()->count())->toBeGreaterThan(0)
        ->and(HomeSetting::getJson('footer.settings'))->not->toBeEmpty();
});

test('re-running the landing seeder keeps administrator-authored content', function (): void {
    $this->seed(HomeLandingSeeder::class);

    $slide = HomeSlide::query()->firstOrFail();
    $slide->update(['sort_order' => 99]);
    $faqCount = HomeFaq::query()->count();
    HomeSetting::setJson('footer.settings', ['contact_phone' => 'edited-by-admin']);

    $this->seed(HomeLandingSeeder::class);

    expect($slide->fresh()->sort_order)->toBe(99)
        ->and(HomeFaq::query()->count())->toBe($faqCount)
        ->and(HomeSetting::getJson('footer.settings'))->toBe(['contact_phone' => 'edited-by-admin']);
});
