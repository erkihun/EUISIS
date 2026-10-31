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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The public home page and the resources page on the EUISIS stack
 * (PublicHomeController): every section's props, the built-in content shown
 * while nothing is saved, the landing manager's saved content and visibility,
 * the Amharic rule (saved content is English-only text) and the resources lists.
 */

/** Saved landing content for every home section, plus an inactive item per list. */
function savedHomeContent(): void
{
    HomeSlide::query()->create([
        'sort_order' => 1, 'is_active' => true, 'badge' => 'Notice', 'title' => 'Court week', 'description' => 'Hearings resume.',
        'primary_label' => 'Read more', 'primary_href' => '/court/terms', 'secondary_label' => null, 'secondary_href' => null,
        'bg_style' => 'orange', 'bg_image' => 'landing/slides/week.jpg',
    ]);
    HomeSlide::query()->create([
        'sort_order' => 2, 'is_active' => false, 'title' => 'Hidden slide', 'primary_label' => 'Go', 'primary_href' => '/', 'bg_style' => 'blue',
    ]);
    HomeTimelineStep::query()->create(['sort_order' => 1, 'is_active' => true, 'title' => 'Lodge the appeal', 'description' => 'Online.', 'meta' => 'Day 1', 'duration' => '1 day', 'color' => 'violet']);
    HomeTimelineStep::query()->create(['sort_order' => 2, 'is_active' => false, 'title' => 'Hidden step', 'color' => 'blue']);
    HomeService::query()->create([
        'sort_order' => 1, 'is_active' => true, 'title' => 'E-filing', 'description' => 'File from home.', 'meta' => 'Online',
        'features' => ['Upload', 'Track'], 'icon_type' => 'chart', 'accent' => 'pink',
    ]);
    HomeService::query()->create(['sort_order' => 2, 'is_active' => false, 'title' => 'Hidden service', 'icon_type' => 'lock', 'accent' => 'blue']);
    HomeFaq::query()->create(['sort_order' => 1, 'is_active' => true, 'question' => 'Where is the registry?', 'answer' => 'Ground floor.']);
    HomeFaq::query()->create(['sort_order' => 2, 'is_active' => false, 'question' => 'Hidden question?', 'answer' => 'No.']);
    HomeSetting::setJson('metrics.content', ['pending_cases' => ['label' => 'Awaiting hearing', 'description' => 'Not yet heard.']]);
    HomeSetting::setJson('section.process', ['visible' => true, 'title' => 'How a case moves', 'subtitle' => 'Five steps.']);
}

function resourceRow(string $title, int $order, array $attributes = []): HomeResource
{
    return HomeResource::query()->create(array_merge([
        'sort_order' => $order, 'is_active' => true, 'is_featured' => false, 'type' => 'post', 'title' => $title,
    ], $attributes));
}

test('the home page shows the built-in content while nothing is saved', function (): void {
    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Home')
            // One institutional statement; with the portal open here it leads to the hearings and the court.
            ->has('slides', 1)
            ->where('slides.0', [
                'badge' => __('court::home.hero.court.badge', [], 'en'),
                'title' => __('court::home.hero.court.title', [], 'en'),
                'description' => __('court::home.hero.court.description', [], 'en'),
                'primary_label' => __('court::home.hero.court.hearings', [], 'en'),
                'primary_href' => '#hearings',
                'secondary_label' => __('court::home.hero.court.about', [], 'en'),
                'secondary_href' => '#about',
                'style' => 'blue',
                'image_url' => null,
            ])
            ->where('sections.metrics', ['visible' => true, 'title' => 'The Court in Figures', 'subtitle' => __('court::home.metrics.section_description', [], 'en')])
            ->where('sections.about.title', 'About the Court')
            ->where('sections.hearings.title', 'Hearing Schedule')
            ->where('about', ['body' => __('court::home.about.body', [], 'en'), 'facts' => []])
            // No member, so no composition section; no announcement, no hearing.
            ->where('members', [])
            ->where('sections.members.visible', false)
            ->where('hearings', [])
            ->where('news', [])
            ->where('today.hearings', 0)
            ->where('contactVisible', true)
            ->where('sections.process.title', __('court::home.timeline.section_title', [], 'en'))
            ->where('sections.services.title', __('court::home.services.section_title', [], 'en'))
            ->where('sections.resources.title', __('court::home.resources.section_title', [], 'en'))
            ->where('sections.faq.title', __('court::home.faq.section_title', [], 'en'))
            ->has('metrics', 6)
            ->where('metrics.0', ['key' => 'total_cases', 'label' => 'Total Cases', 'description' => __('court::home.metrics.cards.total_cases.description', [], 'en'), 'value' => 0])
            ->where('metrics.5.key', 'avg_resolution_time')
            ->has('steps', 5)
            ->where('steps.0', [
                'title' => __('court::home.timeline.steps.case_initiation.title', [], 'en'),
                'description' => __('court::home.timeline.steps.case_initiation.description', [], 'en'),
                'meta' => __('court::home.timeline.steps.case_initiation.meta', [], 'en'),
                'duration' => __('court::home.timeline.steps.case_initiation.duration', [], 'en'),
                'color' => 'blue',
            ])
            ->where('steps.4.color', 'emerald')
            // The 4 built-in service cards: filing, hearings, following a case, publications.
            ->has('services', 4)
            ->where('services.0.title', 'File a case')
            ->where('services.0.features', [])
            ->where('services.0.icon', 'document')
            // My Portal is not set up in this test, so the portal cards lead nowhere.
            ->where('services.0.href', null)
            ->where('services.1.href', '#hearings')
            ->where('services.3.icon', 'database')
            ->where('services.3.href', '#resources')
            ->where('resources', [])
            ->where('hasMoreResources', false)
            ->has('faqs', count(__('court::home.faq.questions', [], 'en')))
            ->where('faqs.0', __('court::home.faq.questions', [], 'en')[0])
            ->where('publicSite.footer', null)
            ->where('publicSite.userManualEnabled', false)
            ->where('publicSite.userManualTitle', null)
            ->where('publicSite.portalUrl', null));
});

test('the statistics count cases and hearings as the Blade page did', function (): void {
    $this->travelTo(Carbon::parse('2026-10-28 10:00:00')); // a Wednesday
    $type = CaseType::query()->create(['name' => 'Labour']);
    foreach (['pending', 'pending', 'closed', 'dismissed', 'active'] as $index => $status) {
        CourtCase::query()->create([
            'case_number' => 'HM-000'.$index, 'title' => "Case {$index}", 'case_type_id' => $type->id,
            'filing_date' => now()->toDateString(), 'status' => $status,
        ]);
    }
    $caseId = CourtCase::query()->value('id');
    foreach ([now()->addHour(), now()->subDay(), now()->addDays(10), now()->addDays(40)] as $at) {
        DB::table('court_case_hearings')->insert(['case_id' => $caseId, 'hearing_at' => $at->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('metrics', fn ($metrics): bool => collect($metrics)->pluck('value', 'key')->all() === [
                'total_cases' => 5,
                'resolved_cases' => 2,
                'pending_cases' => 2,
                'active_caseload' => 3,
                // This week: Tuesday and today; the next 30 days: today and day 10.
                'hearings_this_week' => 2,
                'avg_resolution_time' => 2,
            ])
            // Case titles are never sent: the page has no recent-cases list.
            ->missing('recentCases'));
});

test('saved landing content replaces the built-in content in English', function (): void {
    savedHomeContent();

    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Home')
            ->where('slides', [[
                'badge' => 'Notice', 'title' => 'Court week', 'description' => 'Hearings resume.',
                'primary_label' => 'Read more', 'primary_href' => '/court/terms', 'secondary_label' => '', 'secondary_href' => '#',
                'style' => 'orange', 'image_url' => asset('storage/landing/slides/week.jpg'),
            ]])
            ->where('steps', [['title' => 'Lodge the appeal', 'description' => 'Online.', 'meta' => 'Day 1', 'duration' => '1 day', 'color' => 'violet']])
            ->where('services', [['title' => 'E-filing', 'description' => 'File from home.', 'meta' => 'Online', 'features' => ['Upload', 'Track'], 'icon' => 'chart', 'accent' => 'pink', 'href' => null]])
            ->where('faqs', [['question' => 'Where is the registry?', 'answer' => 'Ground floor.']])
            ->where('metrics.2', ['key' => 'pending_cases', 'label' => 'Awaiting hearing', 'description' => 'Not yet heard.', 'value' => 0])
            ->where('metrics.0.label', 'Total Cases')
            ->where('sections.process', ['visible' => true, 'title' => 'How a case moves', 'subtitle' => 'Five steps.'])
            ->where('sections.services.title', __('court::home.services.section_title', [], 'en')));
});

test('in Amharic the home page shows the reviewed translations, not the English-only saved content', function (): void {
    savedHomeContent();
    resourceRow('Filing form', 1, ['type' => 'form']);
    HomeSetting::setJson('footer.settings', ['description' => 'English footer text.', 'contact_email' => 'registry@court.test']);

    $this->withSession(['app_locale' => 'am'])->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Home')
            ->has('slides', 1)
            ->where('slides.0.title', __('court::home.hero.court.title', [], 'am'))
            ->where('slides.0.primary_label', __('court::home.hero.court.hearings', [], 'am'))
            ->where('sections.process.title', __('court::home.timeline.section_title', [], 'am'))
            ->where('sections.process.subtitle', __('court::home.timeline.section_description', [], 'am'))
            ->where('sections.metrics.title', __('court::home.metrics.section_title', [], 'am'))
            ->where('metrics.2.label', __('court::home.metrics.cards.pending_cases.label', [], 'am'))
            ->has('steps', 5)
            ->where('steps.0.title', __('court::home.timeline.steps.case_initiation.title', [], 'am'))
            ->has('services', 4)
            ->where('services.0.title', __('court::home.court_services.filing.title', [], 'am'))
            ->where('faqs.0', __('court::home.faq.questions', [], 'am')[0])
            // Resources and contact details are shown in both languages.
            ->where('resources.0.title', 'Filing form')
            ->where('publicSite.footer.contact_email', 'registry@court.test')
            ->where('publicSite.footer.description', null));

    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slides.0.title', 'Court week')
            ->where('publicSite.footer.description', 'English footer text.'));
});

test('a section the landing manager hides is sent without its items', function (): void {
    savedHomeContent();
    foreach (range(1, 7) as $order) {
        resourceRow("Notice {$order}", $order);
    }
    foreach (['metrics', 'process', 'services', 'resources', 'faq'] as $section) {
        HomeSetting::setJson("section.{$section}", ['visible' => false, 'title' => 'Hidden heading', 'subtitle' => '']);
    }

    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections.metrics.visible', false)
            ->where('sections.process.visible', false)
            ->where('sections.services.visible', false)
            ->where('sections.resources.visible', false)
            ->where('sections.faq.visible', false)
            ->where('metrics', [])
            ->where('steps', [])
            ->where('services', [])
            ->where('resources', [])
            ->where('hasMoreResources', false)
            ->where('faqs', [])
            // The hero has no visibility setting.
            ->has('slides', 1));
});

test('the home page lists the first six active resources and the resources page every active, published one', function (): void {
    foreach (range(1, 7) as $order) {
        resourceRow("Notice {$order}", $order + 1);
    }
    resourceRow('Filing form', 1, [
        'type' => 'form', 'is_featured' => true, 'description' => 'Fill and bring.', 'file_path' => 'landing/resources/form.pdf',
        'cover_image' => 'landing/covers/form.png', 'external_url' => 'https://court.test/form', 'published_at' => now()->subDay(),
    ]);
    resourceRow('Court website', 20, ['type' => 'link', 'file_path' => 'landing/resources/ignored.pdf', 'external_url' => 'https://court.test']);
    resourceRow('Withdrawn notice', 21, ['is_active' => false]);
    resourceRow('Next month notice', 22, ['published_at' => now()->addMonth()]);

    $this->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('resources', 6)
            ->where('hasMoreResources', true)
            ->where('resources.0', fn ($resource): bool => collect($resource)->except(['id', 'published_at'])->all() === [
                'title' => 'Filing form',
                'type' => 'form',
                'description' => 'Fill and bring.',
                'is_featured' => true,
                'download_url' => asset('storage/landing/resources/form.pdf'),
                'external_url' => 'https://court.test/form',
                'cover_image_url' => asset('storage/landing/covers/form.png'),
            ] && str_contains((string) $resource['published_at'], 'T'))
            ->where('resources.1.title', 'Notice 1')
            ->where('resources.1.download_url', null)
            ->where('resources.1.published_at', null));

    $this->get(route('court.public.resources'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Resources')
            ->has('resources', 9)
            ->where('resources', fn ($resources): bool => collect($resources)->pluck('title')->intersect(['Withdrawn notice', 'Next month notice'])->isEmpty())
            // A link is never offered as a download, whatever file it kept.
            ->where('resources.8.title', 'Court website')
            ->where('resources.8.download_url', null)
            ->where('resources.8.external_url', 'https://court.test')
            ->has('publicSite.footer')
            ->has('publicSite.userManualEnabled'));
});

test('the resources page is empty while nothing is published', function (): void {
    resourceRow('Withdrawn notice', 1, ['is_active' => false]);

    $this->withSession(['app_locale' => 'am'])->get(route('court.public.resources'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Public/Resources')
            ->where('resources', []));
});

test('the user manual is linked only while it is switched on and has content', function (): void {
    HomeSetting::setJson('user_manual.settings', ['title_en' => 'Guide', 'title_am' => '', 'content_en' => '', 'content_am' => '', 'is_active' => true]);

    $this->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('publicSite.userManualEnabled', false));
});

test('the user manual link carries the title saved for the language', function (): void {
    HomeSetting::setJson('user_manual.settings', ['title_en' => 'Guide', 'title_am' => '', 'content_en' => '<p>Read me.</p>', 'is_active' => true]);

    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publicSite.userManualEnabled', true)
            ->where('publicSite.userManualTitle', 'Guide'));
    // No Amharic title saved: the link falls back to the translation in the browser.
    $this->withSession(['app_locale' => 'am'])->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publicSite.userManualEnabled', true)
            ->where('publicSite.userManualTitle', null));
});

test('content written in Amharic in the landing manager is what Amharic visitors read', function (): void {
    savedHomeContent();
    HomeSlide::query()->where('title', 'Court week')->update(['translations' => json_encode(['am' => ['title' => 'የችሎት ሳምንት', 'primary_label' => 'ያንብቡ']])]);
    HomeFaq::query()->where('question', 'Where is the registry?')->update(['translations' => json_encode(['am' => ['question' => 'መዝገብ ቤቱ የት ነው?']])]);
    HomeSetting::setJson('section.process', ['visible' => true, 'title' => 'How a case moves', 'title_am' => 'ጉዳይ እንዴት ይሄዳል', 'subtitle' => 'Five steps.']);
    HomeSetting::setJson('about.settings', ['body' => 'English mandate.', 'body_am' => 'የአማርኛ ተልዕኮ።', 'facts' => [['label' => 'Seat', 'value' => 'Addis Ababa', 'label_am' => 'መቀመጫ', 'value_am' => ''], ['label' => '', 'value' => 'no label']]]);
    HomeSetting::setJson('footer.settings', ['description' => 'English footer.', 'office_hours' => 'Mon–Fri', 'office_hours_am' => 'ሰኞ–ዓርብ', 'contact_address' => 'Piazza']);
    CourtMember::query()->create(['sort_order' => 1, 'is_active' => true, 'name' => 'Almaz T', 'role' => 'President', 'translations' => ['am' => ['name' => 'አልማዝ ተ']]]);
    CourtMember::query()->create(['sort_order' => 2, 'is_active' => false, 'name' => 'Hidden Judge']);

    $this->withSession(['app_locale' => 'am'])->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            // A row's Amharic, its English where the Amharic is empty.
            ->where('slides.0.title', 'የችሎት ሳምንት')
            ->where('slides.0.primary_label', 'ያንብቡ')
            ->where('slides.0.description', 'Hearings resume.')
            ->where('faqs.0', ['question' => 'መዝገብ ቤቱ የት ነው?', 'answer' => 'Ground floor.'])
            // Steps and services have no Amharic yet, so the reviewed built-in Amharic stays.
            ->has('steps', 5)
            ->where('sections.process.title', 'ጉዳይ እንዴት ይሄዳል')
            ->where('sections.process.subtitle', __('court::home.timeline.section_description', [], 'am'))
            ->where('about', ['body' => 'የአማርኛ ተልዕኮ።', 'facts' => [['label' => 'መቀመጫ', 'value' => 'Addis Ababa']]])
            ->where('members', [['name' => 'አልማዝ ተ', 'role' => 'President', 'bio' => '', 'photo_url' => null]])
            ->where('sections.members.visible', true)
            ->where('publicSite.footer.office_hours', 'ሰኞ–ዓርብ')
            ->where('publicSite.footer.contact_address', 'Piazza')
            ->where('publicSite.footer.description', null));

    $this->withSession(['app_locale' => 'en'])->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slides.0.title', 'Court week')
            ->where('about.body', 'English mandate.')
            ->where('members.0.name', 'Almaz T')
            ->where('publicSite.footer.office_hours', 'Mon–Fri'));
});

test('the hearing list names cases by number only, for the next two weeks', function (): void {
    $this->travelTo(Carbon::parse('2026-10-28 08:00:00'));
    $type = CaseType::query()->create(['name' => 'Labour']);
    $case = CourtCase::query()->create(['case_number' => 'LAB-0001-26', 'title' => 'Meron A Haile', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'active']);
    foreach (['2026-10-28 10:30:00', '2026-11-05 09:00:00', '2026-12-01 09:00:00', '2026-10-27 09:00:00'] as $at) {
        DB::table('court_case_hearings')->insert(['case_id' => $case->id, 'hearing_at' => $at, 'type' => 'Hearing', 'location' => 'Room 2', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('hearings', 2)
            ->where('hearings.0', fn ($hearing): bool => collect($hearing)->except('id')->all() === [
                'date' => '2026-10-28', 'time' => '10:30', 'case_number' => 'LAB-0001-26', 'case_type' => 'Labour', 'type' => 'Hearing', 'location' => 'Room 2',
            ])
            ->where('hearings.1.date', '2026-11-05')
            ->where('today', ['date' => '2026-10-28', 'hearings' => 1])
            ->where('hearings', fn ($hearings): bool => ! str_contains(json_encode($hearings), 'Meron')));
});

test('news lists active announcements and opens one; an inactive one is not found', function (): void {
    $active = Announcement::query()->create(['title' => 'Court closed on Friday', 'content' => '<p>The registry is <strong>closed</strong>.</p><script>alert(1)</script>', 'status' => 'active']);
    $inactive = Announcement::query()->create(['title' => 'Draft notice', 'content' => '<p>Draft</p>', 'status' => 'inactive']);

    $this->get(route('court.landing.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('news', 1)->where('news.0.title', 'Court closed on Friday')->where('news.0.excerpt', 'The registry is closed.alert(1)'));
    $this->get(route('court.public.news'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Public/News')->has('items', 1)->where('pagination.total', 1));
    $this->get(route('court.public.news.show', $active))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Public/NewsItem')
            ->where('item.content', fn (string $html): bool => str_contains($html, '<strong>closed</strong>') && ! str_contains($html, '<script')));
    $this->get(route('court.public.news.show', $inactive))->assertNotFound();
});
