<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Announcement;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\CourtMember;
use Modules\Court\Domain\Models\HomeFaq;
use Modules\Court\Domain\Models\HomeResource;
use Modules\Court\Domain\Models\HomeService;
use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Domain\Models\HomeSlide;
use Modules\Court\Domain\Models\HomeTimelineStep;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\TermsAndCondition;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Notifications\PasswordResetOtp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * The public site, the landing-page manager and the staff account pages on the
 * EUISIS stack: each page's component and props, and every save reached from
 * the React payloads (nested JSON, booleans, multipart with `_method`).
 */

/** A landing-page editor: the manager's routes need settings.manage. */
function publicSiteStaff(): User
{
    $role = Role::query()->create(['name' => 'site-editor-'.Str::lower(Str::random(6))]);
    $role->permissions()->sync([Permission::query()->firstOrCreate(['name' => 'settings.manage'], ['label' => 'Manage settings'])->id]);
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->sync([$role->id]);

    return $user;
}

/* ── Public pages ─────────────────────────────────────────────────────── */

test('the terms page renders the latest published terms, purified, with the public chrome', function (): void {
    TermsAndCondition::query()->create(['title' => 'Old terms', 'body' => '<p>Old</p>', 'is_published' => true, 'published_at' => now()->subYear()]);
    TermsAndCondition::query()->create(['title' => 'Filing terms', 'body' => '<p>Read first.</p><script>alert(1)</script>', 'is_published' => true, 'published_at' => now()]);
    HomeSetting::setJson('footer.settings', ['contact_email' => 'registry@court.test', 'social_telegram' => 'https://t.me/court']);
    Cache::forget('court.home_footer');

    $this->get(route('court.public.terms'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Terms')
            ->where('term.title', 'Filing terms')
            ->where('term.body', fn (string $body): bool => str_contains($body, 'Read first.') && ! str_contains($body, '<script'))
            ->where('term.published_at', fn (?string $date): bool => $date !== null && str_contains($date, 'T'))
            ->where('publicSite.footer.contact_email', 'registry@court.test')
            ->where('publicSite.footer.social_telegram', 'https://t.me/court')
            ->where('publicSite.footer.contact_phone', null)
            ->where('publicSite.userManualEnabled', false));
});

test('the terms page is not found while nothing is published', function (): void {
    TermsAndCondition::query()->create(['title' => 'Draft', 'body' => 'Draft', 'is_published' => false]);

    $this->get(route('court.public.terms'))->assertNotFound();
});

test('the user manual renders the request language and turns plain text into paragraphs', function (): void {
    HomeSetting::setJson('user_manual.settings', [
        'title_en' => 'Guide', 'title_am' => 'መመሪያ',
        'content_en' => "First step.\n\nSecond & last\nstep.", 'content_am' => '<p>የአመልካች መመሪያ</p>',
        'is_active' => true,
    ]);
    Cache::forget('court.home_user_manual');

    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.user-manual'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/UserManual')
            ->where('title', 'Guide')
            ->where('content', "<p>First step.</p><p>Second &amp; last<br />\nstep.</p>")
            ->where('publicSite.userManualEnabled', true));

    $this->withSession(['app_locale' => 'am'])->get(route('court.landing.user-manual'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('title', 'መመሪያ')->where('content', '<p>የአመልካች መመሪያ</p>'));
});

test('the user manual is not found while it is switched off', function (): void {
    HomeSetting::setJson('user_manual.settings', ['title_en' => 'Guide', 'content_en' => 'x', 'is_active' => false]);

    $this->get(route('court.landing.user-manual'))->assertNotFound();
});

test('the signage board shows today\'s cause list with each sitting\'s bench and room, and the notices', function (): void {
    $type = CaseType::query()->create(['name' => 'Labour']);
    $bench = DB::table('court_teams')->insertGetId(['name' => 'Bench One', 'created_at' => now(), 'updated_at' => now()]);
    $case = CourtCase::query()->create([
        'case_number' => 'SG-0001', 'title' => 'Signage case', 'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(), 'status' => 'pending',
    ]);
    $case->forceFill(['assigned_team_id' => $bench])->save();
    $unassigned = CourtCase::query()->create([
        'case_number' => 'SG-0002', 'title' => 'Unassigned case', 'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(), 'status' => 'pending',
    ]);
    $hearing = fn (CourtCase $for, string $day, string $time, ?string $room) => DB::table('court_case_hearings')->insert([
        'case_id' => $for->id, 'hearing_at' => $day.' '.$time, 'type' => 'Mention', 'location' => $room,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $hearing($unassigned, now()->format('Y-m-d'), '14:00:00', null);
    $hearing($case, now()->format('Y-m-d'), '10:30:00', 'Room 2');
    Announcement::query()->create(['title' => 'Holiday', 'content' => '<p>Closed&nbsp;Friday.</p><p>Back Monday.</p><script>x</script>', 'status' => 'active']);
    Announcement::query()->create(['title' => 'Old', 'content' => '<p>Gone.</p>', 'status' => 'inactive']);

    $this->get(route('court.public.signage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Signage')
            ->where('today.date', now()->toDateString())
            // Today's sittings in time order, with the sitting, the case's bench and the room.
            ->has('today.hearings', 2)
            ->where('today.hearings.0', fn ($row): bool => $row['time'] === '10:30' && $row['case_number'] === 'SG-0001'
                && $row['case_type'] === 'Labour' && $row['type'] === 'Mention' && $row['location'] === 'Room 2'
                && $row['bench_id'] === $bench && $row['bench'] === 'Bench One')
            ->where('today.hearings.1.time', '14:00')
            ->where('today.hearings.1.bench', null)
            ->where('benches', fn ($benches): bool => collect($benches)->contains(fn (array $one): bool => $one['id'] === $bench && $one['name'] === 'Bench One'))
            ->where('nextDay', null)
            // A notice is one line of plain text: no markup, no script, the paragraphs spaced.
            ->has('announcements', 1)
            ->where('announcements.0.title', 'Holiday')
            ->where('announcements.0.summary', 'Closed Friday. Back Monday.')
            ->where('registry', ['office_hours' => null, 'phone' => null, 'address' => null])
            ->where('refreshSeconds', 60)
            ->where('timezone', config('app.timezone'))
            ->has('now')
            ->missing('activeStaff')->missing('todayCases')->missing('statusCounts'));
});

test('the signage board looks ahead to the next sitting day within two weeks', function (): void {
    $type = CaseType::query()->create(['name' => 'Labour']);
    $case = CourtCase::query()->create([
        'case_number' => 'SG-0003', 'title' => 'Later case', 'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(), 'status' => 'pending',
    ]);
    $sit = fn (string $at) => DB::table('court_case_hearings')->insert(['case_id' => $case->id, 'hearing_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
    $sit(now()->addDays(3)->format('Y-m-d').' 09:00:00');
    $sit(now()->addDays(3)->format('Y-m-d').' 11:00:00');
    $sit(now()->addDays(5)->format('Y-m-d').' 09:00:00');

    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today.hearings', [])
            ->where('nextDay.date', now()->addDays(3)->toDateString())
            ->where('nextDay.hearings', fn ($rows): bool => collect($rows)->pluck('time')->all() === ['09:00', '11:00']));

    // Nothing within the next two weeks: no next day.
    DB::table('court_case_hearings')->delete();
    $sit(now()->addDays(20)->format('Y-m-d').' 09:00:00');
    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('nextDay', null));
});

test('the signage board lists decisions released to the parties lately, by case number only', function (): void {
    $type = CaseType::query()->create(['name' => 'Labour']);
    $case = CourtCase::query()->create(['case_number' => 'SG-0004', 'title' => 'Decided', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'closed']);
    $decision = fn (string $number, string $status, ?Carbon $approved) => DB::table('court_decisions')->insert([
        'court_case_id' => $case->id, 'case_number' => $number, 'name' => 'Decision', 'decision_content' => '<p>Private reasons</p>',
        'applicant_full_name' => 'Private Party', 'status' => $status, 'approved_at' => $approved, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $decision('SG/REL/01', 'published', now()->subDays(2));
    $decision('SG/DRAFT/02', 'draft', null);
    $decision('SG/UNAPPROVED/03', 'published', null);
    $decision('SG/OLD/04', 'published', now()->subDays(40));

    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('decisions', [['case_number' => 'SG/REL/01', 'released' => now()->subDays(2)->toDateString()]]))
        ->assertDontSee('Private Party', false)
        ->assertDontSee('Private reasons', false);
});

test('the signage board gives the court\'s figures and this week\'s sitting days', function (): void {
    $type = CaseType::query()->create(['name' => 'Labour']);
    $open = CourtCase::query()->create(['case_number' => 'SG-0006', 'title' => 'Open', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'active']);
    $old = CourtCase::query()->create(['case_number' => 'SG-0007', 'title' => 'Old', 'case_type_id' => $type->id, 'filing_date' => now()->subMonth()->toDateString(), 'status' => 'closed']);
    $old->forceFill(['created_at' => now()->subMonth()])->save();
    $sit = fn (string $at) => DB::table('court_case_hearings')->insert(['case_id' => $open->id, 'hearing_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
    $sit(now()->format('Y-m-d').' 09:00:00');
    $sit(now()->format('Y-m-d').' 11:00:00');
    $sit(now()->addDays(2)->format('Y-m-d').' 09:00:00');
    $sit(now()->addDays(9)->format('Y-m-d').' 09:00:00');
    DB::table('court_decisions')->insert([
        'court_case_id' => $old->id, 'case_number' => 'SG-0007', 'name' => 'Decision', 'decision_content' => 'x',
        'status' => 'published', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.hearings_today', 2)
            ->where('stats.hearings_week', 3)
            ->where('stats.filed_today', 1)
            ->where('stats.decisions_month', 1)
            ->where('stats.total_cases', 2)
            ->where('stats.by_status', fn ($rows): bool => collect($rows)->pluck('total', 'label')->sortKeys()->all() === ['active' => 1, 'closed' => 1])
            ->where('week', [
                ['date' => now()->toDateString(), 'count' => 2],
                ['date' => now()->addDays(2)->toDateString(), 'count' => 1],
            ]));
});

test('the signage board points parties to their portal with a QR code', function (): void {
    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('portal.url', fn (string $url): bool => $url !== '')
            ->where('portal.qr', fn (?string $svg): bool => $svg !== null && str_starts_with($svg, '<svg') && ! str_contains($svg, '<?xml')));
});

test('the signage board shows the registry\'s hours, phone and address from the landing manager, in Amharic whatever the browser\'s language', function (): void {
    HomeSetting::setJson('footer.settings', [
        'office_hours' => 'Mon-Fri 8:30-17:30', 'office_hours_am' => 'ከሰኞ እስከ ዓርብ 2:30-11:30', 'contact_phone' => '+251 11 000 0000',
        'contact_address' => 'Arada', 'contact_address_am' => 'አራዳ',
    ]);
    Cache::forget('court.home_footer');

    foreach (['en', 'am'] as $locale) {
        $this->withSession(['app_locale' => $locale])->get(route('court.public.signage'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registry.phone', '+251 11 000 0000')
                ->where('registry.office_hours', 'ከሰኞ እስከ ዓርብ 2:30-11:30')
                ->where('registry.address', 'አራዳ'));
    }
});

test('the signage board counts this month\'s decisions by the Ethiopian month', function (): void {
    $type = CaseType::query()->create(['name' => 'Labour']);
    $case = CourtCase::query()->create(['case_number' => 'SG-0008', 'title' => 'Decided', 'case_type_id' => $type->id, 'filing_date' => '2026-09-01', 'status' => 'closed']);
    $released = fn (string $at) => DB::table('court_decisions')->insert([
        'court_case_id' => $case->id, 'case_number' => 'SG-0008', 'name' => 'Decision', 'decision_content' => 'x',
        'status' => 'published', 'approved_at' => $at, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // 29 October 2026 is Tikimt 19, 2019: Tikimt began on 11 October.
    $released('2026-10-12 10:00:00'); // Tikimt 2: this month.
    $released('2026-10-05 10:00:00'); // Meskerem 25: last month, though the same Gregorian month.

    $this->travelTo(Carbon::parse('2026-10-29 10:00:00', 'Africa/Addis_Ababa'));
    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.decisions_month', 1));
});

test('the signage board names no staff', function (): void {
    User::factory()->create(['name' => 'Abebe Kebede', 'status' => 'active', 'position' => 'Registrar']);

    $this->get(route('court.public.signage'))->assertOk()
        ->assertDontSee('Abebe Kebede', false)
        ->assertDontSee('Registrar', false);
});

test('the signage board\'s today is the day in Addis Ababa, evening included', function (): void {
    $type = CaseType::query()->create(['name' => 'Labour']);
    $case = CourtCase::query()->create(['case_number' => 'SG-0005', 'title' => 'Evening', 'case_type_id' => $type->id, 'filing_date' => '2026-10-28', 'status' => 'pending']);
    $sit = fn (string $at) => DB::table('court_case_hearings')->insert(['case_id' => $case->id, 'hearing_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
    $sit('2026-10-27 23:50:00');
    $sit('2026-10-28 08:15:00');
    $sit('2026-10-28 21:45:00');
    $sit('2026-10-29 00:30:00');

    // Late in the evening: past 21:00, where a window in UTC used to end.
    $this->travelTo(Carbon::parse('2026-10-28 22:30:00', 'Africa/Addis_Ababa'));
    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today.date', '2026-10-28')
            ->where('today.hearings', fn ($rows): bool => collect($rows)->pluck('time')->all() === ['08:15', '21:45'])
            ->where('nextDay.date', '2026-10-29'));

    // Just after midnight the new day starts, and yesterday's sittings are gone from it.
    $this->travelTo(Carbon::parse('2026-10-29 00:10:00', 'Africa/Addis_Ababa'));
    $this->get(route('court.public.signage'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today.hearings', fn ($rows): bool => collect($rows)->pluck('time')->all() === ['00:30']));
});

/* ── Landing-page manager ─────────────────────────────────────────────── */

test('the landing manager opens on the tab a save asked for', function (): void {
    $this->actingAs(publicSiteStaff())->withSession(['landing_tab' => 'metrics'])
        ->get(route('court.admin.landing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Landing/Index')
            ->where('initialTab', 'metrics')
            ->where('metricDefaults.total_cases.label', 'Total Cases')
            ->where('metrics.total_cases', ['label' => '', 'description' => '', 'label_am' => '', 'description_am' => ''])
            ->where('sections.metrics.visible', true)
            ->where('userManual.title_en', 'User Manual')
            ->where('userManual.is_active', true)
            ->where('footer.contact_email', ''));
});

test('hero slides are added, edited with a new image, hidden and deleted from the page payload', function (): void {
    Storage::fake('court_public');
    $admin = publicSiteStaff();

    $this->actingAs($admin)->post(route('court.admin.landing.slides.store'), [
        'badge' => 'New', 'title' => 'File online', 'description' => '', 'primary_label' => 'Start', 'primary_href' => '/court/applicant/register',
        'secondary_label' => '', 'secondary_href' => '', 'bg_style' => 'orange', 'bg_image' => UploadedFile::fake()->image('hero.jpg'), 'remove_bg_image' => '0',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $slide = HomeSlide::query()->firstWhere('title', 'File online');
    Storage::disk('court_public')->assertExists($slide->bg_image);

    $this->actingAs($admin)->get(route('court.admin.landing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slides.0.title', 'File online')
            ->where('slides.0.bg_style', 'orange')
            ->where('slides.0.bg_image_url', asset('storage/'.$slide->bg_image))
            ->where('slides.0.is_active', true));

    // Multipart edit: POST with `_method=put`, as the page sends it.
    $old = $slide->bg_image;
    $this->actingAs($admin)->post(route('court.admin.landing.slides.update', $slide), [
        '_method' => 'put', 'badge' => '', 'title' => 'File online today', 'description' => 'Fast.', 'primary_label' => 'Start', 'primary_href' => '/court/applicant/register',
        'secondary_label' => 'Sign in', 'secondary_href' => '/court/applicant/login', 'bg_style' => 'emerald', 'bg_image' => UploadedFile::fake()->image('new.jpg'), 'remove_bg_image' => '0',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $slide->refresh();
    expect($slide->title)->toBe('File online today')->and($slide->bg_style)->toBe('emerald')->and($slide->bg_image)->not->toBe($old);
    Storage::disk('court_public')->assertMissing($old);

    $this->actingAs($admin)->post(route('court.admin.landing.slides.update', $slide), [
        '_method' => 'put', 'title' => 'File online today', 'primary_label' => 'Start', 'primary_href' => '/court/applicant/register', 'bg_style' => 'emerald', 'bg_image' => '', 'remove_bg_image' => '1',
    ])->assertRedirect();
    expect($slide->fresh()->bg_image)->toBeNull();

    $this->actingAs($admin)->patch(route('court.admin.landing.slides.toggle', $slide))->assertRedirect();
    expect($slide->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->delete(route('court.admin.landing.slides.destroy', $slide))->assertRedirect();
    expect(HomeSlide::query()->count())->toBe(0);
});

test('timeline steps, service cards and FAQs are saved from the page payload', function (): void {
    $admin = publicSiteStaff();

    $this->actingAs($admin)->post(route('court.admin.landing.steps.store'), ['title' => 'Filing', 'meta' => 'Day 1', 'duration' => '1 day', 'description' => '', 'color' => 'violet'])
        ->assertSessionHasNoErrors();
    $step = HomeTimelineStep::query()->firstWhere('title', 'Filing');
    $this->actingAs($admin)->put(route('court.admin.landing.steps.update', $step), ['title' => 'Filing online', 'meta' => '', 'duration' => '', 'description' => 'Online.', 'color' => 'amber'])
        ->assertSessionHasNoErrors();
    expect($step->fresh()->only(['title', 'color']))->toBe(['title' => 'Filing online', 'color' => 'amber']);

    $this->actingAs($admin)->post(route('court.admin.landing.services.store'), [
        'title' => 'E-filing', 'meta' => 'Online', 'description' => '', 'features' => "Upload\r\n\r\n Track \n", 'icon_type' => 'chart', 'accent' => 'pink',
    ])->assertSessionHasNoErrors();
    $service = HomeService::query()->firstWhere('title', 'E-filing');
    expect($service->features)->toBe(['Upload', 'Track']);
    $this->actingAs($admin)->put(route('court.admin.landing.services.update', $service), [
        'title' => 'E-filing', 'meta' => '', 'description' => '', 'features' => "Upload\nTrack\nPay", 'icon_type' => 'lock', 'accent' => 'blue',
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('court.admin.landing.services.toggle', $service));
    expect($service->fresh()->features)->toBe(['Upload', 'Track', 'Pay'])->and($service->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->post(route('court.admin.landing.faqs.store'), ['question' => 'How do I file?', 'answer' => 'Online.'])->assertSessionHasNoErrors();
    $faq = HomeFaq::query()->firstWhere('question', 'How do I file?');
    $this->actingAs($admin)->put(route('court.admin.landing.faqs.update', $faq), ['question' => 'How do I file a case?', 'answer' => 'Online or at the counter.'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('court.admin.landing.faqs.store'), ['question' => '', 'answer' => ''])->assertSessionHasErrors(['question', 'answer']);

    $this->actingAs($admin)->get(route('court.admin.landing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('steps.0', ['id' => $step->id, 'title' => 'Filing online', 'description' => 'Online.', 'meta' => null, 'duration' => null, 'color' => 'amber', 'is_active' => true,
                'am' => ['title' => '', 'description' => '', 'meta' => '', 'duration' => '']])
            ->where('services.0.features', ['Upload', 'Track', 'Pay'])
            ->where('services.0.is_active', false)
            ->where('faqs.0.question', 'How do I file a case?'));
});

test('resources are saved with a file, a cover and a schedule from the page payload', function (): void {
    Storage::fake('court_public');
    $admin = publicSiteStaff();
    $publishAt = now()->addDays(3)->format('Y-m-d\TH:i');

    $this->actingAs($admin)->post(route('court.admin.landing.resources.store'), [
        'title' => 'Filing form', 'type' => 'form', 'published_at' => $publishAt, 'description' => 'Fill and bring.', 'external_url' => '',
        'file' => UploadedFile::fake()->create('form.pdf', 40, 'application/pdf'), 'remove_file' => '0',
        'cover_image' => UploadedFile::fake()->image('cover.png'), 'is_featured' => '1',
    ])->assertSessionHasNoErrors();
    $resource = HomeResource::query()->firstWhere('title', 'Filing form');
    expect($resource->is_featured)->toBeTrue();
    Storage::disk('court_public')->assertExists($resource->file_path);

    $this->actingAs($admin)->get(route('court.admin.landing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('resources.0.type', 'form')
            ->where('resources.0.is_scheduled', true)
            ->where('resources.0.published_at_local', $publishAt)
            ->where('resources.0.file_path', $resource->file_path)
            ->where('resources.0.cover_image_url', asset('storage/'.$resource->cover_image)));

    $this->actingAs($admin)->post(route('court.admin.landing.resources.update', $resource), [
        '_method' => 'put', 'title' => 'Filing form', 'type' => 'link', 'published_at' => '', 'description' => '', 'external_url' => 'https://court.test/form',
        'file' => '', 'remove_file' => '1', 'cover_image' => '', 'is_featured' => '0',
    ])->assertSessionHasNoErrors();
    $resource->refresh();
    expect($resource->file_path)->toBeNull()->and($resource->type)->toBe('link')->and($resource->is_featured)->toBeFalse()->and($resource->cover_image)->not->toBeNull();

    $this->actingAs($admin)->patch(route('court.admin.landing.resources.toggle', $resource));
    $this->actingAs($admin)->delete(route('court.admin.landing.resources.destroy', $resource))->assertRedirect();
    expect(HomeResource::query()->count())->toBe(0);
});

test('statistics, sections, footer and the user manual save from the page payload', function (): void {
    $admin = publicSiteStaff();

    $metrics = collect(['total_cases', 'resolved_cases', 'pending_cases', 'active_caseload', 'hearings_this_week', 'avg_resolution_time'])
        ->mapWithKeys(fn (string $key): array => [$key => ['label' => '', 'description' => '']])->all();
    $metrics['pending_cases'] = ['label' => 'Awaiting hearing', 'description' => 'Not yet heard.'];
    $this->actingAs($admin)->put(route('court.admin.landing.metrics.update'), $metrics)
        ->assertSessionHas('success')->assertSessionHas('landing_tab', 'metrics');
    expect(HomeSetting::getJson('metrics.content')['pending_cases'])->toBe(['label' => 'Awaiting hearing', 'description' => 'Not yet heard.']);

    // The sections tab sends JSON booleans and every section's fields.
    $this->actingAs($admin)->put(route('court.admin.landing.sections.update'), [
        'metrics' => ['visible' => true, 'title' => 'Workload', 'subtitle' => ''],
        'process' => ['visible' => false, 'title' => '', 'subtitle' => ''],
        'services' => ['visible' => true, 'title' => '', 'subtitle' => ''],
        'cases' => ['visible' => false],
        'resources' => ['visible' => true, 'title' => '', 'subtitle' => ''],
        'faq' => ['visible' => true, 'title' => 'Help', 'subtitle' => ''],
        'cta' => ['visible' => true, 'title' => 'Ready?', 'description' => '', 'primary_label' => 'File', 'primary_href' => '/court/applicant/register', 'secondary_label' => '', 'secondary_href' => ''],
    ])->assertSessionHasNoErrors();
    expect(HomeSetting::getJson('section.process')['visible'])->toBeFalse()
        ->and(HomeSetting::getJson('section.cases')['visible'])->toBeFalse()
        ->and(HomeSetting::getJson('section.cta')['title'])->toBe('Ready?');

    $this->actingAs($admin)->put(route('court.admin.landing.footer.update'), [
        'description' => 'The tribunal.', 'contact_phone' => '+251 11 000 0000', 'contact_email' => 'info@court.test', 'contact_address' => 'Addis Ababa',
        'social_facebook' => 'https://facebook.com/court', 'social_twitter' => '', 'social_linkedin' => '', 'social_youtube' => '', 'social_telegram' => '', 'social_instagram' => '',
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put(route('court.admin.landing.footer.update'), ['contact_email' => 'not-an-email', 'social_youtube' => 'nope'])
        ->assertSessionHasErrors(['contact_email', 'social_youtube']);
    expect(HomeSetting::getJson('footer.settings')['contact_email'])->toBe('info@court.test');

    $this->actingAs($admin)->put(route('court.admin.landing.user-manual.update'), [
        'title_en' => 'Guide', 'title_am' => 'መመሪያ', 'content_en' => '<p onclick="x()">Hello</p>', 'content_am' => '<p>ሰላም</p>', 'is_active' => true,
    ])->assertSessionHasNoErrors()->assertSessionHas('landing_tab', 'resources');

    $this->actingAs($admin)->get(route('court.admin.landing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('metrics.pending_cases.label', 'Awaiting hearing')
            ->where('sections.process.visible', false)
            ->where('sections.metrics.title', 'Workload')
            ->where('footer.contact_phone', '+251 11 000 0000')
            ->where('footer.social_twitter', '')
            ->where('userManual.content_en', '<p>Hello</p>')
            ->where('userManual.title_am', 'መመሪያ'));
});

/* ── Staff account pages ──────────────────────────────────────────────── */




test('the landing manager keeps Amharic text, the court\'s facts and its members', function (): void {
    $admin = publicSiteStaff();

    $this->actingAs($admin)->post(route('court.admin.landing.faqs.store'), ['question' => 'Where?', 'answer' => 'Here.', 'am' => ['question' => ' የት? ', 'answer' => '']])
        ->assertSessionHasNoErrors();
    expect(HomeFaq::query()->firstWhere('question', 'Where?')->translations)->toBe(['am' => ['question' => 'የት?']]);
    $this->actingAs($admin)->post(route('court.admin.landing.services.store'), [
        'title' => 'Hearings', 'icon_type' => 'calendar', 'accent' => 'blue', 'href' => 'javascript:alert(1)',
    ])->assertSessionHasErrors('href');
    $this->actingAs($admin)->post(route('court.admin.landing.services.store'), [
        'title' => 'Hearings', 'icon_type' => 'calendar', 'accent' => 'blue', 'href' => '#hearings', 'am' => ['title' => 'ችሎቶች', 'features' => "አንድ\n\nሁለት"],
    ])->assertSessionHasNoErrors();
    expect(HomeService::query()->firstWhere('title', 'Hearings')->only(['href', 'translations']))->toBe(['href' => '#hearings', 'translations' => ['am' => ['title' => 'ችሎቶች', 'features' => ['አንድ', 'ሁለት']]]]);

    $this->actingAs($admin)->put(route('court.admin.landing.about.update'), [
        'body' => 'Mandate.', 'body_am' => 'ተልዕኮ።',
        'facts' => [['label' => 'Seat', 'value' => 'Addis Ababa'], ['label' => '', 'value' => 'dropped', 'label_am' => '', 'value_am' => '']],
    ])->assertSessionHasNoErrors()->assertSessionHas('landing_tab', 'about');
    expect(HomeSetting::getJson('about.settings'))->toBe(['body' => 'Mandate.', 'body_am' => 'ተልዕኮ።', 'facts' => [['label' => 'Seat', 'value' => 'Addis Ababa', 'label_am' => '', 'value_am' => '']]]);

    $this->actingAs($admin)->post(route('court.admin.landing.members.store'), ['name' => 'Almaz T', 'role' => 'President', 'am' => ['name' => 'አልማዝ ተ']])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('court.admin.landing.members.store'), ['name' => 'Kebede A', 'role' => 'Judge'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('court.admin.landing.members.store'), ['name' => ''])->assertSessionHasErrors('name');
    [$first, $second] = CourtMember::query()->orderBy('sort_order')->get()->all();
    $this->actingAs($admin)->post(route('court.admin.landing.members.reorder'), ['order' => [$second->id, $first->id]])->assertRedirect();
    $this->actingAs($admin)->put(route('court.admin.landing.members.update', $first), ['name' => 'Almaz Tesfaye', 'role' => 'President'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('court.admin.landing.members.toggle', $second));
    expect($first->fresh()->translations)->toBeNull()->and($second->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->put(route('court.admin.landing.sections.update'), ['about' => ['visible' => true, 'title' => 'The Court', 'title_am' => 'ፍርድ ቤቱ'], 'news' => ['visible' => false]])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)->put(route('court.admin.landing.footer.update'), ['office_hours' => 'Mon–Fri', 'map_url' => 'javascript:alert(1)'])->assertSessionHasErrors('map_url');

    $this->actingAs($admin)->get(route('court.admin.landing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('members.0.name', 'Kebede A')
            ->where('members.1', fn ($member): bool => $member['name'] === 'Almaz Tesfaye' && $member['am'] === ['name' => '', 'role' => '', 'bio' => ''])
            ->where('about.facts', [['label' => 'Seat', 'value' => 'Addis Ababa', 'label_am' => '', 'value_am' => '']])
            ->where('sections.about.title_am', 'ፍርድ ቤቱ')
            ->where('sections.news.visible', false)
            ->where('faqs.0.am', ['question' => 'የት?', 'answer' => '']));

    $this->get(route('court.landing.home'))->assertInertia(fn (AssertableInertia $page) => $page->where('news', [])->has('members', 1)->where('members.0.name', 'Almaz Tesfaye'));
});
