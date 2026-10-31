<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** The landing manager is part of the system settings (routes: perm:settings.manage). */
function landingManagementEditor(): User
{
    $role = Role::query()->create(['name' => 'landing-editor-'.Str::lower(Str::random(6))]);
    $role->permissions()->sync([Permission::query()->firstOrCreate(['name' => 'settings.manage'], ['label' => 'Manage settings'])->id]);
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->sync([$role->id]);

    return $user;
}

test('the landing manager is refused to staff without settings.manage', function (): void {
    $staff = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

    // RequirePermission sends the person to their dashboard instead of the page.
    $this->actingAs($staff)->get(route('court.admin.landing.index'))->assertRedirect(route('court.dashboard'));
    $this->actingAs($staff)->put(route('court.admin.landing.footer.update'), ['description' => 'Defaced'])->assertRedirect(route('court.dashboard'));

    expect(HomeSetting::getJson('footer.settings'))->toBeEmpty();
});

test('landing manager exposes controls for every home page section', function (): void {
    $admin = landingManagementEditor();

    // The React page (Court/Landing/Index) draws a tab per section from these props;
    // its labels are the admin_landing.* keys, read in the browser as cm.admin_landing.*.
    $this->actingAs($admin)
        ->get(route('court.admin.landing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Landing/Index')
            ->where('initialTab', 'slides')
            ->where('homeUrl', route('court.landing.home'))
            ->has('slides')->has('steps')->has('services')->has('resources')->has('faqs')
            ->has('stats')->has('metrics.total_cases')->has('metricDefaults.avg_resolution_time')
            ->has('sections.cta')->has('footer.social_telegram')->has('userManual'));

    expect([
        __('court::admin_landing.tabs.slides', [], 'en'), __('court::admin_landing.tabs.metrics', [], 'en'), __('court::admin_landing.tabs.timeline', [], 'en'),
        __('court::admin_landing.tabs.services', [], 'en'), __('court::admin_landing.tabs.resources', [], 'en'), __('court::admin_landing.tabs.faqs', [], 'en'),
        __('court::admin_landing.tabs.footer', [], 'en'), __('court::admin_landing.tabs.sections', [], 'en'), __('court::admin_landing.preview', [], 'en'),
    ])->toBe(['Hero Slides', 'Statistics', 'Timeline', 'Services', 'Resources', 'FAQ', 'Footer', 'Sections', 'Preview home page']);
});

test('landing manager uses formal amharic labels in the amharic locale', function (): void {
    $admin = landingManagementEditor();

    $this->actingAs($admin)
        ->withSession(['app_locale' => 'am'])
        ->get(route('court.admin.landing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Landing/Index')->where('locale', 'am'));

    // The labels the React page shows in Amharic (cm.admin_landing.* from lang/am/admin_landing.php).
    $labels = [
        'tabs.slides' => 'ዋና ማስታወቂያዎች',
        'tabs.metrics' => 'አጠቃላይ መረጃ',
        'tabs.timeline' => 'የጉዳይ ሂደት',
        'tabs.services' => 'አገልግሎቶች',
        'tabs.resources' => 'ሰነዶችና መረጃዎች',
        'tabs.faqs' => 'ተደጋጋሚ ጥያቄዎች',
        'tabs.footer' => 'የገጽ ግርጌ',
        'tabs.sections' => 'የገጽ ክፍሎች',
        'common.badge_text' => 'አጭር መለያ ጽሑፍ',
        'common.primary_button_label' => 'የዋና አዝራር ጽሑፍ',
        'common.duration' => 'የሚፈጀው ጊዜ',
        'common.features' => 'ዋና ባህሪያት',
        'common.publish_date' => 'የሚታተምበት ቀን',
        'common.question' => 'ጥያቄ',
        'footer.contact' => 'የግንኙነት መረጃ',
        'sections.section_title' => 'የክፍሉ ርዕስ',
    ];

    foreach ($labels as $key => $amharic) {
        expect(__("court::admin_landing.{$key}", [], 'am'))->toBe($amharic);
    }
});

test('administrator can update statistics content displayed on the english home page', function (): void {
    $admin = landingManagementEditor();

    $payload = [
        'total_cases' => [
            'label' => 'Registered Tribunal Matters',
            'description' => 'All matters currently registered by the tribunal.',
        ],
    ];

    $this->actingAs($admin)
        ->put(route('court.admin.landing.metrics.update'), $payload)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HomeSetting::getJson('metrics.content')['total_cases'])
        ->toBe($payload['total_cases']);

    $this->withSession(['app_locale' => 'en'])
        ->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Home')
            ->where('metrics.0.key', 'total_cases')
            ->where('metrics.0.label', 'Registered Tribunal Matters')
            ->where('metrics.0.description', 'All matters currently registered by the tribunal.'));
});

test('section settings control metrics and faq headings on the home page', function (): void {
    $admin = landingManagementEditor();

    $this->actingAs($admin)
        ->put(route('court.admin.landing.sections.update'), [
            'metrics' => [
                'visible' => '1',
                'title' => 'Current Tribunal Workload',
                'subtitle' => 'Live operational totals.',
            ],
            'faq' => [
                'visible' => '1',
                'title' => 'Portal Help',
                'subtitle' => 'Answers for applicants and respondents.',
            ],
        ])
        ->assertRedirect();

    $this->withSession(['app_locale' => 'en'])
        ->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Home')
            ->where('sections.metrics.title', 'Current Tribunal Workload')
            ->where('sections.metrics.subtitle', 'Live operational totals.')
            ->where('sections.faq.title', 'Portal Help')
            ->where('sections.faq.subtitle', 'Answers for applicants and respondents.'));
});

test('administrator manages the user manual opened from localized home navigation', function (): void {
    $admin = landingManagementEditor();

    $this->actingAs($admin)->put(route('court.admin.landing.user-manual.update'), [
        'title_en' => 'User Manual',
        'title_am' => 'የተጠቃሚ መመሪያ',
        'content_en' => '<h2 style="color: #123456;">Applicant Guide</h2><ol><li>Register an account</li><li><strong>File a case</strong></li></ol><table><tr><th>Step</th><th>Action</th></tr><tr><td>1</td><td>Sign in</td></tr></table>',
        'content_am' => '<h2 dir="rtl">የአመልካች መመሪያ</h2><ul><li>መለያ ይክፈቱ</li><li>ጉዳይ ያቅርቡ</li></ul>',
        'is_active' => '1',
    ])->assertRedirect();

    $settings = HomeSetting::getJson('user_manual.settings');

    // The header and footer link the manual (route landing.user-manual) under its Amharic title.
    $this->withSession(['app_locale' => 'am'])
        ->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Home')
            ->where('publicSite.userManualEnabled', true)
            ->where('publicSite.userManualTitle', 'የተጠቃሚ መመሪያ'));

    $this->withSession(['app_locale' => 'en'])
        ->get(route('court.landing.user-manual'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/UserManual')
            ->where('title', 'User Manual')
            // HTMLPurifier keeps the inline style, normalised.
            ->where('content', fn (string $content): bool => str_contains($content, '<h2 style="color:#123456;">Applicant Guide</h2>')
                && str_contains($content, '<ol><li>Register an account</li>')
                && str_contains($content, '<table>')
                && ! str_contains($content, '&lt;h2')));
});
