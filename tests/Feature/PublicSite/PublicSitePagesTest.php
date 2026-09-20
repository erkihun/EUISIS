<?php

declare(strict_types=1);

use App\Enums\PublicAnnouncementStatus;
use App\Enums\PublicServiceStatus;
use App\Models\PublicAnnouncement;
use App\Models\PublicPageSection;
use App\Models\PublicService;
use App\Models\SystemSetting;
use App\Services\PublicSite\PublicSiteContent;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The five public pages, rendered from Public Site Management content.
 */

function publishedNotice(array $attributes = []): PublicAnnouncement
{
    static $n = 0;
    $n++;

    return PublicAnnouncement::query()->create([
        'slug' => "public-notice-{$n}",
        'title_en' => "Public notice {$n}",
        'title_am' => "ማስታወቂያ {$n}",
        'content_en' => 'Body **text**.',
        'status' => PublicAnnouncementStatus::Published->value,
        'published_at' => now()->subHour(),
        ...$attributes,
    ]);
}

function disablePublicSite(): void
{
    SystemSetting::query()->updateOrCreate(
        ['group' => 'public_site', 'key' => 'enabled'],
        ['value' => '0', 'type' => 'boolean', 'is_public' => true],
    );
}

it('renders every public page with the shared navigation', function (string $url, string $component): void {
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->has('publicSite.navigation', 5)
        ->where('publicSite.navigation.0.route_name', 'home'));
})->with([
    'home' => ['/', 'Welcome'],
    'announcements' => ['/announcements', 'Public/Announcements/Index'],
    'verify' => ['/verify', 'Public/Verify'],
    'services' => ['/services', 'Public/Services'],
    'support' => ['/support', 'Public/Support'],
]);

it('lists navigation in the fixed order Home, Announcements, Verify, Services, Support', function (): void {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('publicSite.navigation.0.href', '/')
        ->where('publicSite.navigation.1.href', '/announcements')
        ->where('publicSite.navigation.2.href', '/verify')
        ->where('publicSite.navigation.3.href', '/services')
        ->where('publicSite.navigation.4.href', '/support'));
});

it('builds the home page from its CMS sections in order', function (): void {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('sections.intro.title_en', 'Unified Employee ID & Service Integration System')
        ->where('sections.intro.options.primary_cta_href', '/verify')
        ->has('services', 4)
        ->missing('sections.intro.options.primary_cta_route'));
});

it('hides a home section the administrator switched off', function (): void {
    PublicPageSection::query()->where('page', 'home')->where('section_key', 'featured_services')->update(['is_visible' => false]);
    PublicSiteContent::flush();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->missing('sections.featured_services')
        ->where('services', []));
});

it('shows published announcements and hides drafts, archived, expired and future ones', function (): void {
    $published = publishedNotice();
    publishedNotice(['status' => PublicAnnouncementStatus::Draft->value]);
    publishedNotice(['status' => PublicAnnouncementStatus::Archived->value]);
    publishedNotice(['expires_at' => now()->subMinute()]);
    publishedNotice(['published_at' => now()->addDay()]);

    $this->get('/announcements')->assertInertia(fn (Assert $page) => $page
        ->has('announcements.data', 1)
        ->where('announcements.data.0.id', $published->id));
});

it('returns 404 for the detail page of an unpublished announcement', function (): void {
    $draft = publishedNotice(['status' => PublicAnnouncementStatus::Draft->value]);

    $this->get("/announcements/{$draft->slug}")->assertNotFound();
});

it('renders a published announcement with sanitized content', function (): void {
    $notice = publishedNotice(['content_en' => "Hello <script>alert(1)</script>\n\n**bold**"]);

    $this->get("/announcements/{$notice->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/Announcements/Show')
        ->where('announcement.content_html_en', fn (string $html): bool => str_contains($html, '<strong>bold</strong>') && ! str_contains($html, '<script')));
});

it('keeps the transfer announcement routes reachable, not shadowed by slugs', function (): void {
    $this->get('/announcements/transfers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/TransferAnnouncements')
        ->has('announcements.data'));

    expect(route('public.transfer-announcements', absolute: false))->toBe('/announcements/transfers')
        ->and(route('public.transfer-announcements.show', 'abc', absolute: false))->toBe('/announcements/transfer/abc');
});

it('filters announcements by search term', function (): void {
    publishedNotice(['title_en' => 'Road closure on Bole']);
    publishedNotice(['title_en' => 'Holiday schedule']);

    $this->get('/announcements?search=closure')->assertInertia(fn (Assert $page) => $page
        ->has('announcements.data', 1)
        ->where('announcements.data.0.title_en', 'Road closure on Bole'));
});

it('lists only published services and serves the detail page of one', function (): void {
    PublicService::query()->create([
        'code' => 'DRAFT-1', 'slug' => 'draft-service', 'name_en' => 'Draft service',
        'status' => PublicServiceStatus::Draft->value,
    ]);
    $published = PublicService::query()->create([
        'code' => 'PUB-1', 'slug' => 'published-service', 'name_en' => 'Published service',
        'full_description_en' => 'Details here.', 'status' => PublicServiceStatus::Published->value,
    ]);

    $this->get('/services')->assertInertia(fn (Assert $page) => $page
        ->where('services.data', fn ($services): bool => collect($services)->pluck('slug')->contains('published-service')
            && ! collect($services)->pluck('slug')->contains('draft-service')));

    $this->get("/services/{$published->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/ServiceShow')
        ->where('service.description_html_en', '<p>Details here.</p>'));

    $this->get('/services/draft-service')->assertNotFound();
});

it('delivers both languages so the browser can choose', function (): void {
    publishedNotice(['title_en' => 'English title', 'title_am' => 'የአማርኛ ርዕስ']);

    $this->get('/announcements')->assertInertia(fn (Assert $page) => $page
        ->where('announcements.data.0.title_en', 'English title')
        ->where('announcements.data.0.title_am', 'የአማርኛ ርዕስ'));
});

it('shows the maintenance notice on content pages when the site is disabled', function (string $url): void {
    disablePublicSite();

    $this->get($url)->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('Public/Unavailable'));
})->with(['/', '/announcements', '/services', '/support']);

it('keeps ID verification available when the site is disabled', function (): void {
    disablePublicSite();

    $this->get('/verify')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Verify'));
    $this->get('/id-checker')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/IdChecker'));
});

it('passes only presentation text to the ID checker, never security configuration', function (): void {
    PublicPageSection::query()->where('page', 'verify')->where('section_key', 'otp_help')
        ->update(['body_en' => 'Ask the card holder for the code.']);
    PublicSiteContent::flush();

    $this->get('/id-checker')->assertInertia(fn (Assert $page) => $page
        ->where('sections.otp_help.body_html_en', '<p>Ask the card holder for the code.</p>')
        ->where('cardUuid', null)
        ->where('card', null));
});

it('never exposes admin-only fields on public pages', function (): void {
    publishedNotice(['created_by' => null]);

    $body = $this->get('/announcements')->getContent();

    foreach (['created_by', 'updated_by', 'published_by', 'deleted_at', 'is_system'] as $field) {
        expect($body)->not->toContain($field);
    }
});
