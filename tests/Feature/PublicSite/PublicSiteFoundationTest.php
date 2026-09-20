<?php

declare(strict_types=1);

use App\Enums\PublicAnnouncementStatus;
use App\Enums\PublicServiceStatus;
use App\Models\PublicAnnouncement;
use App\Models\PublicNavigationItem;
use App\Models\PublicService;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicUrlPolicy;
use App\Services\PublicSite\SafeContentRenderer;

/*
 * Foundation of Public Site Management: the link allow-list, the content
 * sanitizer, the single visibility rule, and cache invalidation. Everything
 * public-facing is built on these, so they are tested in isolation here.
 */

function announcement(array $attributes = []): PublicAnnouncement
{
    static $n = 0;
    $n++;

    return PublicAnnouncement::query()->create([
        'slug' => "notice-{$n}",
        'title_en' => "Notice {$n}",
        'status' => PublicAnnouncementStatus::Published->value,
        'published_at' => now()->subHour(),
        ...$attributes,
    ]);
}

// ── Link allow-list ────────────────────────────────────────────────────────

it('accepts only allow-listed public routes', function (): void {
    expect(PublicUrlPolicy::isAllowedRoute('public.verify'))->toBeTrue()
        ->and(PublicUrlPolicy::isAllowedRoute('home'))->toBeTrue()
        ->and(PublicUrlPolicy::isAllowedRoute('system-settings.index'))->toBeFalse()
        ->and(PublicUrlPolicy::isAllowedRoute('dashboard'))->toBeFalse()
        ->and(PublicUrlPolicy::isAllowedRoute('no.such.route'))->toBeFalse()
        ->and(PublicUrlPolicy::isAllowedRoute(null))->toBeFalse();
});

it('rejects every unsafe or non-https external url', function (string $url): void {
    expect(PublicUrlPolicy::isAllowedExternalUrl($url))->toBeFalse();
})->with([
    'javascript' => 'javascript:alert(1)',
    'javascript mixed case' => 'JaVaScRiPt:alert(1)',
    'tab-split scheme' => "java\tscript:alert(1)",
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'vbscript' => 'vbscript:msgbox(1)',
    'plain http' => 'http://example.gov.et',
    'protocol relative' => '//evil.example',
    'relative admin path' => '/system-settings',
    'no host' => 'https://',
]);

it('accepts a well-formed https url', function (): void {
    expect(PublicUrlPolicy::isAllowedExternalUrl('https://www.addisababa.gov.et/about'))->toBeTrue();
});

// ── Sanitizer ──────────────────────────────────────────────────────────────

it('strips scripts, frames, event handlers and unsafe links from content', function (): void {
    $renderer = app(SafeContentRenderer::class);

    $html = $renderer->toHtml(implode("\n\n", [
        'Intro <script>alert(1)</script>',
        '<iframe src="https://evil.example"></iframe>',
        '<img src=x onerror=alert(1)>',
        '[bad](javascript:alert(1)) and [data](data:text/html,x)',
    ]));

    expect($html)->not->toContain('<script')
        ->not->toContain('<iframe')
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        ->not->toContain('data:');
});

it('keeps headings, lists, emphasis and safe links', function (): void {
    $html = app(SafeContentRenderer::class)->toHtml("## Title\n\n- **one**\n- *two*\n\n[site](https://example.gov.et)");

    expect($html)->toContain('<h2>Title</h2>')
        ->toContain('<strong>one</strong>')
        ->toContain('<em>two</em>')
        ->toContain('href="https://example.gov.et"');
});

// ── Visibility ─────────────────────────────────────────────────────────────

it('shows only published announcements whose window has opened and not closed', function (): void {
    $visible = announcement();
    announcement(['status' => PublicAnnouncementStatus::Draft->value]);
    announcement(['status' => PublicAnnouncementStatus::Archived->value]);
    announcement(['published_at' => now()->addDay()]);                      // not yet
    announcement(['expires_at' => now()->subMinute()]);                     // expired
    announcement(['published_at' => null]);                                 // never dated
    announcement()->delete();                                               // soft-deleted

    expect(PublicAnnouncement::query()->visible()->pluck('id')->all())->toBe([$visible->id]);
});

it('publishes a scheduled announcement once its time arrives, without a job', function (): void {
    $scheduled = announcement([
        'status' => PublicAnnouncementStatus::Scheduled->value,
        'published_at' => now()->addHour(),
    ]);

    expect(PublicAnnouncement::query()->visible()->count())->toBe(0);

    $this->travel(2)->hours();

    expect(PublicAnnouncement::query()->visible()->pluck('id')->all())->toBe([$scheduled->id]);
});

it('hides draft, hidden and archived services', function (): void {
    $before = PublicService::query()->visible()->count();

    foreach ([PublicServiceStatus::Draft, PublicServiceStatus::Hidden, PublicServiceStatus::Archived] as $i => $status) {
        PublicService::query()->create([
            'code' => "T-{$i}", 'slug' => "t-{$i}", 'name_en' => "T {$i}", 'status' => $status->value,
        ]);
    }

    expect(PublicService::query()->visible()->count())->toBe($before);
});

// ── Defaults and caching ───────────────────────────────────────────────────

it('carries the existing public copy into the CMS on migration', function (): void {
    $content = app(PublicSiteContent::class);

    expect(array_column($content->navigation(), 'route_name'))->toContain('public.verify', 'home', 'public.services', 'public.support')
        ->and(array_keys($content->sections('home')))->toBe(['intro', 'featured_services', 'latest_announcements', 'verification', 'support'])
        ->and($content->featuredServices(10))->toHaveCount(4);
});

it('drops a stored link that no longer passes the allow-list', function (): void {
    PublicNavigationItem::query()->create([
        'label_en' => 'Admin', 'type' => 'route', 'route_name' => 'system-settings.index', 'sort_order' => 1,
    ]);
    PublicNavigationItem::query()->create([
        'label_en' => 'Evil', 'type' => 'external', 'url' => 'javascript:alert(1)', 'sort_order' => 2,
    ]);
    PublicSiteContent::flush();

    $labels = array_column(app(PublicSiteContent::class)->navigation(), 'label_en');

    expect($labels)->not->toContain('Admin')->not->toContain('Evil');
});

it('serves fresh content after a flush, and never serves a draft', function (): void {
    $content = app(PublicSiteContent::class);
    expect($content->latestAnnouncements(5))->toBe([]);

    $draft = announcement(['status' => PublicAnnouncementStatus::Draft->value]);
    PublicSiteContent::flush();
    expect($content->latestAnnouncements(5))->toBe([]);

    $draft->update(['status' => PublicAnnouncementStatus::Published->value]);
    PublicSiteContent::flush();
    expect(array_column($content->latestAnnouncements(5), 'id'))->toBe([$draft->id]);
});

it('never exposes admin-only columns in public payloads', function (): void {
    announcement(['created_by' => null]);
    PublicSiteContent::flush();

    $payload = json_encode([
        app(PublicSiteContent::class)->latestAnnouncements(5),
        app(PublicSiteContent::class)->featuredServices(5),
        app(PublicSiteContent::class)->navigation(),
    ]);

    foreach (['created_by', 'updated_by', 'published_by', 'deleted_at', '"status"', 'sort_order', 'is_system'] as $field) {
        expect($payload)->not->toContain($field);
    }
});
