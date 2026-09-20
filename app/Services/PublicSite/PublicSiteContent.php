<?php

declare(strict_types=1);

namespace App\Services\PublicSite;

use App\Models\PublicAnnouncement;
use App\Models\PublicFaq;
use App\Models\PublicFooterLink;
use App\Models\PublicNavigationItem;
use App\Models\PublicPageMeta;
use App\Models\PublicPageSection;
use App\Models\PublicService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Read side of the public site: PUBLISHED content only, shaped for anonymous
 * visitors.
 *
 * Every method here returns only what a member of the public may see, through
 * the models' `visible()` scopes, and serializes through the presenters below
 * so no admin-only column (`created_by`, draft status, sort order…) can leak.
 *
 * Caching
 * -------
 * The cache store is `file`, which has no tags, so invalidation is by version:
 * every key embeds a counter that any CMS write bumps (`flush()`). Old entries
 * are simply never read again. Draft and preview content never passes through
 * this class, so it can never land in a cached public response.
 *
 * Both languages are always returned (`_en` and `_am`): the active locale is
 * chosen in the browser, so one cached payload serves every visitor.
 */
final class PublicSiteContent
{
    private const VERSION_KEY = 'public_site:version';

    /**
     * Shape of the cached payloads. Bump whenever what `remember()` stores
     * changes structure. The content version only moves on CMS writes, so
     * without this a deploy that reshapes a cached entry would keep serving
     * entries in the old shape until someone happened to edit the site — which
     * is exactly how the public home page failed with "Undefined array key
     * type" during development.
     */
    private const SCHEMA = 2;

    public function __construct(private readonly SafeContentRenderer $renderer) {}

    /** Invalidate every cached public payload. Call after any CMS write. */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /*
     * Links are cached as stored rows and resolved to hrefs on every read.
     * Resolution checks the route still exists and still passes the allow-list,
     * so a deploy that adds or removes a route takes effect immediately rather
     * than waiting for the next CMS edit to bump the cache version.
     */

    /** @return list<array<string, mixed>> */
    public function navigation(): array
    {
        $rows = $this->remember('navigation', fn (): array => PublicNavigationItem::query()
            ->visible()
            ->orderBy('sort_order')
            ->get(['id', 'label_en', 'label_am', 'type', 'route_name', 'url'])
            ->toArray());

        return $this->presentLinks($rows);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function footerLinks(): array
    {
        $rows = $this->remember('footer', fn (): array => PublicFooterLink::query()
            ->visible()
            ->orderBy('sort_order')
            ->get(['id', 'group', 'label_en', 'label_am', 'type', 'route_name', 'url'])
            ->toArray());

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group']][] = $row;
        }

        return array_map(fn (array $links): array => $this->presentLinks($links), $grouped);
    }

    /**
     * Visible sections of a page, keyed by section key, in display order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sections(string $page): array
    {
        // Rendered HTML is cached; CTA hrefs are resolved per read (see links).
        $sections = $this->remember("sections:{$page}", function () use ($page): array {
            return PublicPageSection::query()
                ->where('page', $page)
                ->where('is_visible', true)
                ->orderBy('sort_order')
                ->get()
                ->filter(fn (PublicPageSection $section): bool => PublicSiteSectionRegistry::has($page, $section->section_key))
                ->mapWithKeys(fn (PublicPageSection $section): array => [
                    $section->section_key => $this->renderSection($section),
                ])
                ->all();
        });

        return array_map(fn (array $section): array => $this->resolveSectionLinks($section), $sections);
    }

    /** @return array<string, mixed>|null */
    public function meta(string $page): ?array
    {
        return $this->remember("meta:{$page}", function () use ($page): ?array {
            $meta = PublicPageMeta::query()->where('page', $page)->first();

            return $meta === null ? null : [
                'title_en' => $meta->meta_title_en,
                'title_am' => $meta->meta_title_am,
                'description_en' => $meta->meta_description_en,
                'description_am' => $meta->meta_description_am,
                'canonical_url' => $meta->canonical_url,
                'og_image_url' => $meta->og_image_path ? Storage::disk('public')->url($meta->og_image_path) : null,
                'noindex' => $meta->noindex,
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function featuredServices(int $limit): array
    {
        return $this->remember("services:featured:{$limit}", fn (): array => PublicService::query()
            ->visible()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn (PublicService $service): array => $this->presentServiceSummary($service))
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function latestAnnouncements(int $limit): array
    {
        // Not cached by version alone: visibility depends on the clock
        // (scheduled / expiring items), so a short TTL bounds staleness.
        return $this->remember("announcements:latest:{$limit}", fn (): array => PublicAnnouncement::query()
            ->visible()
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (PublicAnnouncement $announcement): array => $this->presentAnnouncementSummary($announcement))
            ->all(), ttlSeconds: 300);
    }

    /** @return list<array<string, mixed>> */
    public function faqs(): array
    {
        return $this->remember('faqs', fn (): array => PublicFaq::query()
            ->visible()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PublicFaq $faq): array => [
                'id' => $faq->id,
                'category' => $faq->category,
                'question_en' => $faq->question_en,
                'question_am' => $faq->question_am,
                'answer_html_en' => $this->renderer->toHtml($faq->answer_en),
                'answer_html_am' => $this->renderer->toHtml($faq->answer_am),
            ])
            ->all());
    }

    // ── Presenters ──────────────────────────────────────────────────────
    // Public so the admin preview renders drafts through the exact same shape.

    /** @return array<string, mixed> */
    public function presentAnnouncementSummary(PublicAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'slug' => $announcement->slug,
            'title_en' => $announcement->title_en,
            'title_am' => $announcement->title_am,
            'summary_en' => $announcement->summary_en ?: $this->renderer->toPlainText($announcement->content_en, 220),
            'summary_am' => $announcement->summary_am ?: $this->renderer->toPlainText($announcement->content_am, 220),
            'category' => $announcement->category,
            'is_featured' => $announcement->is_featured,
            'published_at' => $announcement->published_at?->toIso8601String(),
            'image_url' => $announcement->featured_image_path
                ? Storage::disk('public')->url($announcement->featured_image_path)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function presentAnnouncement(PublicAnnouncement $announcement): array
    {
        return [
            ...$this->presentAnnouncementSummary($announcement),
            'content_html_en' => $this->renderer->toHtml($announcement->content_en),
            'content_html_am' => $this->renderer->toHtml($announcement->content_am),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'attachments' => $announcement->attachments->map(fn ($file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime' => $file->mime,
                'size' => $file->size,
                'url' => Storage::disk('public')->url($file->path),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentServiceSummary(PublicService $service): array
    {
        return [
            'id' => $service->id,
            'slug' => $service->slug,
            'code' => $service->code,
            'icon' => $service->icon,
            'name_en' => $service->name_en,
            'name_am' => $service->name_am,
            'short_description_en' => $service->short_description_en,
            'short_description_am' => $service->short_description_am,
            'is_featured' => $service->is_featured,
            'has_details' => $this->serviceHasDetails($service),
        ];
    }

    /** @return array<string, mixed> */
    public function presentService(PublicService $service): array
    {
        $html = fn (?string $source): string => $this->renderer->toHtml($source);

        return [
            ...$this->presentServiceSummary($service),
            'description_html_en' => $html($service->full_description_en),
            'description_html_am' => $html($service->full_description_am),
            'eligibility_html_en' => $html($service->eligibility_en),
            'eligibility_html_am' => $html($service->eligibility_am),
            'requirements_html_en' => $html($service->requirements_en),
            'requirements_html_am' => $html($service->requirements_am),
            'steps_html_en' => $html($service->steps_en),
            'steps_html_am' => $html($service->steps_am),
            'contact_info' => $service->contact_info,
            'action_href' => $service->action_route
                ? PublicUrlPolicy::resolve('route', $service->action_route, null)
                : ($service->action_url ? PublicUrlPolicy::resolve('external', null, $service->action_url) : null),
            'action_external' => $service->action_route === null && $service->action_url !== null,
        ];
    }

    /** A section ready for a page, including resolved CTA hrefs. */
    public function presentSection(PublicPageSection $section): array
    {
        return $this->resolveSectionLinks($this->renderSection($section));
    }

    /** @return array<string, mixed> */
    private function renderSection(PublicPageSection $section): array
    {
        return [
            'key' => $section->section_key,
            'title_en' => $section->title_en,
            'title_am' => $section->title_am,
            'subtitle_en' => $section->subtitle_en,
            'subtitle_am' => $section->subtitle_am,
            'body_html_en' => $this->renderer->toHtml($section->body_en),
            'body_html_am' => $this->renderer->toHtml($section->body_am),
            'options' => $section->options ?? [],
        ];
    }

    /**
     * Swaps each stored `*_cta_route` for a `*_cta_href`, dropping any that no
     * longer pass the allow-list rather than trusting what was stored. Route
     * names never reach the browser.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function resolveSectionLinks(array $section): array
    {
        $options = [];

        foreach ($section['options'] as $key => $value) {
            if (str_ends_with((string) $key, '_cta_route')) {
                $options[str_replace('_route', '_href', (string) $key)] =
                    is_string($value) ? PublicUrlPolicy::resolve('route', $value, null) : null;

                continue;
            }

            $options[$key] = $value;
        }

        $section['options'] = $options;

        return $section;
    }

    private function serviceHasDetails(PublicService $service): bool
    {
        foreach (['full_description', 'eligibility', 'requirements', 'steps'] as $field) {
            if (filled($service->{$field.'_en'}) || filled($service->{$field.'_am'})) {
                return true;
            }
        }

        return filled($service->contact_info) || filled($service->action_route) || filled($service->action_url);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentLinks(array $rows): array
    {
        $links = [];

        foreach ($rows as $row) {
            $href = PublicUrlPolicy::resolve($row['type'], $row['route_name'] ?? null, $row['url'] ?? null);

            // A stored link that no longer passes the policy is dropped, never shown.
            if ($href === null) {
                continue;
            }

            $links[] = [
                'id' => $row['id'],
                'label_en' => $row['label_en'],
                'label_am' => $row['label_am'],
                'href' => $href,
                'route_name' => $row['type'] === 'route' ? $row['route_name'] : null,
                'external' => $row['type'] === 'external',
            ];
        }

        return $links;
    }

    private function remember(string $key, \Closure $callback, ?int $ttlSeconds = null): mixed
    {
        $cacheKey = sprintf('public_site:s%d:v%d:%s', self::SCHEMA, (int) Cache::get(self::VERSION_KEY, 0), $key);

        return $ttlSeconds === null
            ? Cache::rememberForever($cacheKey, $callback)
            : Cache::remember($cacheKey, $ttlSeconds, $callback);
    }
}
