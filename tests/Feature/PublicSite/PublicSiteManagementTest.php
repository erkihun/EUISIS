<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\PublicAnnouncement;
use App\Models\PublicFaq;
use App\Models\PublicPageSection;
use App\Models\PublicService;
use App\Models\User;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicSiteSectionRegistry;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function publicEditorUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

function editorAnnouncementPayload(array $overrides = []): array
{
    return [...[
        'title_en' => 'Office opening', 'title_am' => 'የቢሮ መክፈቻ',
        'slug' => 'editor-office-opening', 'content_en' => 'Opening details.',
        'is_featured' => false,
    ], ...$overrides];
}

test('public management requires authentication and its entry permission', function (): void {
    $this->get(route('public-site-management.index'))->assertRedirect(route('login'));
    $this->actingAs(publicEditorUser(['public_home.view']))->get(route('public-site-management.index'))->assertForbidden();
});

test('public management only returns authorized tabs and fixed section slots', function (): void {
    $this->actingAs(publicEditorUser(['public_site.view', 'public_home.view']))
        ->get(route('public-site-management.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('PublicSiteManagement/Index')
        ->where('tabs', ['home'])->where('tab', 'home')
        ->has('sections', count(PublicSiteSectionRegistry::definitions()['home']))
        ->where('sections.0.key', 'intro')->where('can.updatePage', false)
        ->where('records', null)->where('settingsFields', []));
    $this->get(route('public-site-management.index', ['tab' => 'settings']))->assertForbidden();
    $this->get(route('public-site-management.index', ['tab' => 'announcements']))->assertForbidden();
});

test('read only content users cannot mutate sections or create records', function (): void {
    $before = PublicPageSection::where('page', 'home')->where('section_key', 'intro')->first()?->title_en;
    $this->actingAs(publicEditorUser(['public_site.view', 'public_home.view', 'public_announcements.view']))
        ->put(route('public-site-management.section', ['home', 'intro']), ['title_en' => 'Unauthorized', 'is_visible' => true, 'options' => []])
        ->assertForbidden();
    expect(PublicPageSection::where('page', 'home')->where('section_key', 'intro')->first()?->title_en)->toBe($before);
    $this->post(route('public-site-management.store', 'announcements'), editorAnnouncementPayload())->assertForbidden();
    $this->assertDatabaseMissing('public_announcements', ['slug' => 'editor-office-opening']);
});

test('section editing rejects unknown slots hiding required sections and unsafe CTA options', function (): void {
    $this->actingAs(publicEditorUser(['public_site.view', 'public_home.update']));
    $this->put(route('public-site-management.section', ['home', 'invented']), [])->assertNotFound();
    $this->put(route('public-site-management.section', ['home', 'intro']), ['is_visible' => false, 'options' => []])
        ->assertSessionHasErrors('is_visible');
    $this->put(route('public-site-management.section', ['home', 'intro']), ['is_visible' => true, 'options' => ['primary_cta_route' => 'system-settings.index']])
        ->assertSessionHasErrors('options.primary_cta_route');
    $this->put(route('public-site-management.section', ['home', 'intro']), ['is_visible' => true, 'options' => ['html' => '<script>bad()</script>']])
        ->assertSessionHasErrors('options');
});

test('section editing saves bilingual text and invalidates public section cache', function (): void {
    $content = app(PublicSiteContent::class);
    $content->sections('home');
    $user = publicEditorUser(['public_site.view', 'public_home.update']);
    $this->actingAs($user)
        ->put(route('public-site-management.section', ['home', 'intro']), [
            'title_en' => 'Our public services', 'title_am' => 'የህዝብ አገልግሎቶች',
            'is_visible' => true, 'options' => ['primary_cta_route' => 'public.verify'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    expect($content->sections('home')['intro']['title_en'])->toBe('Our public services')
        ->and($content->sections('home')['intro']['title_am'])->toBe('የህዝብ አገልግሎቶች')
        ->and($content->sections('home')['intro']['options']['primary_cta_href'])->toBe('/verify');
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')->where('sections.intro.title_en', 'Our public services')
        ->where('sections.intro.title_am', 'የህዝብ አገልግሎቶች'));
    $section = PublicPageSection::where('page', 'home')->where('section_key', 'intro')->firstOrFail();
    $audit = AuditLog::where('event_type', AuditEventType::PublicPageSectionUpdated->value)
        ->where('auditable_id', $section->id)->sole();
    expect($audit->actor_user_id)->toBe($user->id)
        ->and($audit->auditable_type)->toBe(PublicPageSection::class)
        ->and($audit->new_values['fields'])->toContain('title_en', 'title_am', 'options');
});

test('homepage receives optional section visibility and ordering changes', function (): void {
    // Prime the public response before editing to exercise cache invalidation.
    $this->get('/')->assertOk();
    $this->actingAs(publicEditorUser(['public_site.view', 'public_home.update']));
    foreach ([['support', 10], ['verification', 20], ['latest_announcements', 30]] as [$section, $order]) {
        $this->put(route('public-site-management.section', ['home', $section]), [
            'is_visible' => true, 'sort_order' => $order,
            'options' => $section === 'latest_announcements' ? ['max_items' => 3] : ['button_label_en' => 'Open'],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    $this->put(route('public-site-management.section', ['home', 'featured_services']), [
        'is_visible' => false, 'sort_order' => 40, 'options' => ['max_items' => 4],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('sections.featured_services')->where('services', [])
        ->where('sections', fn ($sections): bool => collect($sections)->keys()
            ->reject(fn (string $key): bool => $key === 'intro')->values()->all()
                === ['support', 'verification', 'latest_announcements']));
});

test('read authorized announcement content is paginated instead of loading all records', function (): void {
    for ($i = 1; $i <= 18; $i++) {
        PublicAnnouncement::create(editorAnnouncementPayload(['slug' => 'editor-page-'.$i, 'title_en' => 'Pagination record '.$i]));
    }
    $user = publicEditorUser(['public_site.view', 'public_announcements.view']);
    $this->actingAs($user)->get(route('public-site-management.index', ['tab' => 'announcements', 'search' => 'Pagination record']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tabs', ['announcements'])->where('records.total', 18)
            ->where('records.per_page', 15)->where('records.last_page', 2)->has('records.data', 15)
            ->where('can.create', false)->where('can.update', false)->where('can.publish', false)->where('can.archive', false));
    $this->get(route('public-site-management.index', ['tab' => 'announcements', 'search' => 'Pagination record', 'page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 18)->where('records.current_page', 2)->has('records.data', 3));
    $this->get(route('public-site-management.index', ['tab' => 'invented']))->assertForbidden();
    $this->post(route('public-site-management.store', 'users'), [])->assertNotFound();
    $this->put(route('public-site-management.update', ['users', $user->id]), ['name' => 'Changed'])->assertNotFound();
    $this->post(route('public-site-management.transition', ['users', $user->id]), ['action' => 'publish'])->assertNotFound();
    expect($user->fresh()->name)->toBe($user->name);
});

test('creating announcements always produces a draft even when publication fields are posted', function (): void {
    $user = publicEditorUser(['public_site.view', 'public_announcements.create']);
    $this->actingAs($user)->post(route('public-site-management.store', 'announcements'), editorAnnouncementPayload([
        'status' => 'published', 'published_at' => now()->toIso8601String(), 'published_by' => $user->id,
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $notice = PublicAnnouncement::where('slug', 'editor-office-opening')->firstOrFail();
    expect($notice->status->value)->toBe('draft')->and($notice->published_at)->toBeNull()->and($notice->published_by)->toBeNull();
    $this->get('/announcements/'.$notice->slug)->assertNotFound();
    expect(collect(app(PublicSiteContent::class)->latestAnnouncements(10))->pluck('id'))->not->toContain($notice->id);
});

test('publishing and unpublishing invalidate cached public visibility', function (): void {
    $notice = PublicAnnouncement::create([...editorAnnouncementPayload(), 'status' => 'draft']);
    $content = app(PublicSiteContent::class);
    expect(collect($content->latestAnnouncements(10))->pluck('id'))->not->toContain($notice->id);
    $this->actingAs(publicEditorUser(['public_site.view', 'public_announcements.view', 'public_announcements.update']))
        ->post(route('public-site-management.transition', ['announcements', $notice->id]), ['action' => 'publish'])->assertForbidden();
    expect($notice->fresh()->status->value)->toBe('draft');
    $this->actingAs(publicEditorUser(['public_site.view', 'public_announcements.view', 'public_announcements.publish']))
        ->post(route('public-site-management.transition', ['announcements', $notice->id]), ['action' => 'publish'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(collect($content->latestAnnouncements(10))->pluck('id'))->toContain($notice->id);
    $this->get('/announcements/'.$notice->slug)->assertOk();
    $this->post(route('public-site-management.transition', ['announcements', $notice->id]), ['action' => 'unpublish'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(collect($content->latestAnnouncements(10))->pluck('id'))->not->toContain($notice->id);
    $this->get('/announcements/'.$notice->slug)->assertNotFound();
});

test('editing live announcements also requires publish permission', function (string $status): void {
    $notice = PublicAnnouncement::create([...editorAnnouncementPayload(), 'status' => $status, 'published_at' => now()]);
    $this->actingAs(publicEditorUser(['public_site.view', 'public_announcements.update']))
        ->put(route('public-site-management.update', ['announcements', $notice->id]), editorAnnouncementPayload(['title_en' => 'Unauthorized edit']))
        ->assertForbidden();
    expect($notice->fresh()->title_en)->toBe('Office opening');
})->with(['published', 'scheduled']);

test('service editor rejects unsafe or ambiguous action targets', function (array $target, string $error): void {
    $this->actingAs(publicEditorUser(['public_site.view', 'public_services.create']))
        ->post(route('public-site-management.store', 'services'), [
            'name_en' => 'Identity service', 'code' => 'EDITOR-ID', 'slug' => 'editor-identity',
            'sort_order' => 0, 'is_featured' => false, ...$target,
        ])->assertSessionHasErrors($error);
    $this->assertDatabaseMissing('public_services', ['code' => 'EDITOR-ID']);
})->with([
    [['action_url' => 'javascript:alert(1)'], 'action_url'],
    [['action_url' => '//example.com'], 'action_url'],
    [['action_url' => 'http://example.com'], 'action_url'],
    [['action_route' => 'employees.index'], 'action_route'],
    [['action_route' => 'public.verify', 'action_url' => 'https://example.com'], 'action_url'],
]);

test('service editor accepts safe targets but keeps newly created content draft', function (array $target): void {
    $this->actingAs(publicEditorUser(['public_site.view', 'public_services.create']))
        ->post(route('public-site-management.store', 'services'), [
            'name_en' => 'Identity service', 'code' => 'EDITOR-ID', 'slug' => 'editor-identity',
            'sort_order' => 0, 'is_featured' => false, 'status' => 'published', ...$target,
        ])->assertRedirect()->assertSessionHasNoErrors();
    expect(PublicService::where('code', 'EDITOR-ID')->firstOrFail()->status->value)->toBe('draft');
    $this->get('/services/editor-identity')->assertNotFound();
})->with([
    [['action_route' => 'public.verify']],
    [['action_url' => 'https://example.com/service']],
]);

test('service publication unpublication and archive control public visibility and cache', function (): void {
    $service = PublicService::create([
        'name_en' => 'Lifecycle service', 'code' => 'EDITOR-LIFECYCLE', 'slug' => 'editor-lifecycle',
        'status' => 'draft', 'is_featured' => true,
    ]);
    $content = app(PublicSiteContent::class);
    expect(collect($content->featuredServices(12))->pluck('id'))->not->toContain($service->id);
    $this->actingAs(publicEditorUser(['public_site.view', 'public_services.view', 'public_services.publish', 'public_services.archive']));
    foreach ([['publish', 'published', true], ['unpublish', 'draft', false], ['publish', 'published', true], ['archive', 'archived', false]] as [$action, $status, $visible]) {
        $this->post(route('public-site-management.transition', ['services', $service->id]), ['action' => $action])
            ->assertRedirect()->assertSessionHasNoErrors();
        expect($service->fresh()->status->value)->toBe($status)
            ->and(collect($content->featuredServices(12))->pluck('id')->contains($service->id))->toBe($visible);
        $this->get('/services/'.$service->slug)->assertStatus($visible ? 200 : 404);
        $this->get('/services')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('services.data', fn ($rows): bool => collect($rows)->pluck('id')->contains($service->id) === $visible));
    }
});

test('public settings use registry validation and never update unrelated settings', function (): void {
    $payload = array_map(fn (array $field) => $field['default'], SystemSettingsRegistry::group('public_site'));
    $this->actingAs(publicEditorUser(['public_site.view', 'public_site_settings.update']))
        ->put(route('public-site-management.settings'), [...$payload, 'announcements_page_size' => 1])
        ->assertSessionHasErrors('announcements_page_size');
    $this->put(route('public-site-management.settings'), [...$payload,
        'office_hours_en' => 'Monday to Friday', 'office_hours_am' => 'ከሰኞ እስከ አርብ',
        'unknown_setting' => 'not registered', 'general.organization_name' => 'Unauthorized replacement',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(SystemSettingsService::class)->get('public_site', 'office_hours_en'))->toBe('Monday to Friday');
    $this->assertDatabaseMissing('system_settings', ['group' => 'public_site', 'key' => 'unknown_setting']);
    $this->assertDatabaseMissing('system_settings', ['group' => 'general', 'value' => 'Unauthorized replacement']);
});

test('settings require both module access and settings update permission', function (array $permissions): void {
    $payload = array_map(fn (array $field) => $field['default'], SystemSettingsRegistry::group('public_site'));
    $this->actingAs(publicEditorUser($permissions))
        ->put(route('public-site-management.settings'), [...$payload, 'office_hours_en' => 'Unauthorized hours'])
        ->assertForbidden();
    $this->assertDatabaseMissing('system_settings', ['group' => 'public_site', 'key' => 'office_hours_en', 'value' => 'Unauthorized hours']);
})->with([
    [['public_site.view', 'public_site_settings.view']],
    [['public_site_settings.update']],
]);

test('FAQ editor saves both languages and updates cached public answers', function (): void {
    $content = app(PublicSiteContent::class);
    $content->faqs();
    $this->actingAs(publicEditorUser(['public_site.view', 'public_support.update']))
        ->post(route('public-site-management.store', 'faqs'), [
            'question_en' => 'When is the office open?', 'question_am' => 'ቢሮው መቼ ይከፈታል?',
            'answer_en' => 'Weekdays.', 'answer_am' => 'በሥራ ቀናት።', 'sort_order' => 0, 'is_published' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    $faq = PublicFaq::where('question_en', 'When is the office open?')->firstOrFail();
    expect($faq->question_am)->toBe('ቢሮው መቼ ይከፈታል?')->and($faq->answer_am)->toBe('በሥራ ቀናት።')
        ->and(collect($content->faqs())->pluck('id'))->toContain($faq->id);
});
