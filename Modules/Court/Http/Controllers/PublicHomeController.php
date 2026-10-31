<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers;

use Modules\Court\Domain\Models\Announcement;
use Modules\Court\Domain\Models\CourtMember;
use Modules\Court\Domain\Models\HomeFaq;
use Modules\Court\Domain\Models\HomeResource;
use Modules\Court\Domain\Models\HomeService;
use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Domain\Models\HomeSlide;
use Modules\Court\Domain\Models\HomeTimelineStep;
use Modules\Court\Application\Support\EuisisParties;
use Modules\Court\Http\Support\Ui\EditorLink;
use Modules\Court\Http\Support\Ui\PublicSiteChrome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public home page (Court/Public/Home), laid out as an administrative
 * court's site: who the court is and its members, its services, the hearings
 * of the coming days, its figures, how a case proceeds, news, publications,
 * questions and how to reach the registry. Everything on it is the landing
 * manager's, in both languages: a row shows its Amharic text, or its English
 * when that is empty. A list whose rows have no Amharic at all keeps the
 * reviewed built-in Amharic (lang/am/home.php), as before rows carried both.
 * A hidden section sends no items.
 */
class PublicHomeController extends Controller
{
    private const SLIDE_STYLES = ['blue', 'orange', 'emerald'];

    private const STEP_COLORS = ['blue', 'orange', 'emerald', 'violet', 'amber'];

    private const SERVICE_ICONS = ['document', 'calendar', 'lock', 'chart', 'database', 'chat'];

    private const SERVICE_ACCENTS = ['blue', 'orange', 'emerald', 'violet', 'amber', 'pink'];

    /** The home page's sections, in page order. */
    public const SECTIONS = ['about', 'members', 'services', 'hearings', 'metrics', 'process', 'news', 'resources', 'faq', 'contact'];

    /** The built-in timeline (home.timeline.steps.*) and the colour of each step. */
    private const DEFAULT_STEPS = [
        'case_initiation' => 'blue',
        'case_assignment' => 'orange',
        'evidence_submission' => 'blue',
        'adjudication' => 'orange',
        'implementation' => 'emerald',
    ];

    /** The built-in service cards (home.court_services.*): icon, accent and where each leads. */
    private const DEFAULT_SERVICES = [
        'filing' => ['document', 'blue', 'portal'],
        'hearings' => ['calendar', 'orange', '#hearings'],
        'follow' => ['chart', 'violet', 'portal'],
        'publications' => ['database', 'amber', '#resources'],
    ];

    /** Resources the home page lists before "View all resources". */
    private const HOME_RESOURCE_LIMIT = 6;

    /** How far ahead the public hearing list looks, and how many it shows. */
    private const HEARING_DAYS = 14;

    private const HEARING_LIMIT = 8;

    public function home(): Response
    {
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';
        $isAmharic = $locale === 'am';

        $dbSlides = $this->cached('court.home_slides', 'court_home_slides', fn () => HomeSlide::active()->get());
        $dbFaqs = $this->cached('court.home_faqs', 'court_home_faqs', fn () => HomeFaq::active()->get());
        $dbServices = $this->cached('court.home_services', 'court_home_services', fn () => HomeService::active()->get());
        $dbTimeline = $this->cached('court.home_timeline', 'court_home_timeline_steps', fn () => HomeTimelineStep::active()->get());
        $dbMembers = $this->cached('court.home_members', 'court_members', fn () => CourtMember::active()->get());
        $dbResources = $this->activeResources();
        $dbSections = Cache::remember('court.home_settings', 3600, function () {
            if (! Schema::hasTable('court_home_settings')) {
                return [];
            }
            $out = [];
            foreach (self::SECTIONS as $key) {
                $out[$key] = HomeSetting::getJson("section.{$key}");
            }

            return $out;
        });
        $dbMetrics = Cache::remember('court.home_metrics', 3600,
            fn () => Schema::hasTable('court_home_settings') ? HomeSetting::getJson('metrics.content') : []
        );
        $dbMetrics = is_array($dbMetrics) ? $dbMetrics : [];
        $about = Cache::remember('court.home_about', 3600,
            fn () => Schema::hasTable('court_home_settings') ? HomeSetting::getJson('about.settings') : []
        );
        $about = is_array($about) ? $about : [];

        // A section never saved is shown; a saved one follows its `visible` flag.
        $visible = fn (string $section): bool => ! is_array($dbSections[$section] ?? null) || (bool) ($dbSections[$section]['visible'] ?? true);
        $text = function (string $section, string $key, string $fallback) use ($dbSections, $locale): string {
            $value = trim((string) ($dbSections[$section][$locale === 'am' ? "{$key}_am" : $key] ?? ''));

            return $value !== '' ? $value : $fallback;
        };
        $sections = collect(self::SECTIONS)->mapWithKeys(fn (string $key): array => [$key => [
            'visible' => $visible($key),
            'title' => $text($key, 'title', (string) __("court::home.{$this->langSection($key)}.section_title")),
            'subtitle' => $text($key, 'subtitle', (string) __("court::home.{$this->langSection($key)}.section_description")),
        ]])->all();

        // A list keeps the built-in Amharic until one of its rows has Amharic text.
        $usable = fn (Collection $rows, string $field): bool => $rows->isNotEmpty()
            && (! $isAmharic || $rows->contains(fn ($row): bool => $row->hasAmharic($field)));

        $members = $sections['members']['visible'] ? $this->members($dbMembers, $locale) : [];
        $sections['members']['visible'] = $members !== [];

        $body = trim((string) ($about[$isAmharic ? 'body_am' : 'body'] ?? ''));
        $facts = collect((array) ($about['facts'] ?? []))->filter(fn ($fact): bool => is_array($fact))->map(fn (array $fact): array => [
            'label' => $this->pick($fact, 'label', $locale),
            'value' => $this->pick($fact, 'value', $locale),
        ])->filter(fn (array $fact): bool => $fact['label'] !== '' && $fact['value'] !== '')->values()->all();

        return Inertia::render('Court/Public/Home', [
            'slides' => $usable($dbSlides, 'title')
                ? $dbSlides->map(fn (HomeSlide $slide): array => $this->slide($slide, $locale))->values()->all()
                : $this->defaultSlides(),
            'sections' => $sections,
            'about' => $sections['about']['visible'] ? [
                'body' => $body !== '' ? $body : (string) __('court::home.about.body'),
                'facts' => $facts,
            ] : null,
            'members' => $members,
            'services' => $sections['services']['visible'] ? $this->services($usable($dbServices, 'title') ? $dbServices : collect(), $locale) : [],
            'hearings' => $sections['hearings']['visible'] ? $this->hearings() : [],
            'today' => $this->today(),
            'metrics' => $sections['metrics']['visible'] ? $this->metrics($dbMetrics, $locale) : [],
            'steps' => $sections['process']['visible'] ? $this->steps($usable($dbTimeline, 'title') ? $dbTimeline : collect(), $locale) : [],
            'news' => $sections['news']['visible']
                ? $this->newsQuery()->limit(3)->get(['id', 'title', 'content', 'created_at'])->map(fn (Announcement $announcement): array => $this->newsCard($announcement))->values()->all()
                : [],
            'resources' => $sections['resources']['visible']
                ? $dbResources->take(self::HOME_RESOURCE_LIMIT)->map(fn (HomeResource $resource): array => $this->resource($resource, $locale))->values()->all()
                : [],
            'hasMoreResources' => $sections['resources']['visible'] && $dbResources->count() > self::HOME_RESOURCE_LIMIT,
            'faqs' => $sections['faq']['visible'] ? $this->faqs($usable($dbFaqs, 'question') ? $dbFaqs : collect(), $locale) : [],
            'contactVisible' => $sections['contact']['visible'],
            'publicSite' => PublicSiteChrome::props(),
        ]);
    }

    public function resources(): Response
    {
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';

        return Inertia::render('Court/Public/Resources', [
            'resources' => $this->activeResources()->map(fn (HomeResource $resource): array => $this->resource($resource, $locale))->values()->all(),
            'publicSite' => PublicSiteChrome::props(),
        ]);
    }

    /** News: the court's active announcements, newest first. */
    public function newsIndex(): Response
    {
        $page = $this->newsQuery()->paginate(10, ['id', 'title', 'content', 'created_at'])->withQueryString();

        return Inertia::render('Court/Public/News', [
            'items' => collect($page->items())->map(fn (Announcement $announcement): array => $this->newsCard($announcement))->values()->all(),
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'publicSite' => PublicSiteChrome::props(),
        ]);
    }

    public function newsItem(Announcement $announcement): Response
    {
        abort_unless($announcement->status === 'active', 404);

        return Inertia::render('Court/Public/NewsItem', [
            'item' => [
                'id' => (int) $announcement->id,
                'title' => (string) $announcement->title,
                // Purified here, as the court screen does, and again by the viewer.
                'content' => (string) \Modules\Court\Application\Support\Purifier::clean((string) $announcement->content, 'default'),
                'created_at' => $announcement->created_at?->toIso8601String(),
            ],
            'publicSite' => PublicSiteChrome::props(),
        ]);
    }

    private function newsQuery()
    {
        return Announcement::query()->where('status', 'active')->latest('created_at');
    }

    /** @return array{id: int, title: string, excerpt: string, created_at: string|null} */
    private function newsCard(Announcement $announcement): array
    {
        return [
            'id' => (int) $announcement->id,
            'title' => (string) $announcement->title,
            'excerpt' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $announcement->content)))), 220),
            'created_at' => $announcement->created_at?->toIso8601String(),
        ];
    }

    /** Active, already published resources, shared by both pages under one cache key. */
    private function activeResources(): Collection
    {
        return $this->cached('court.home_resources', 'court_home_resources', fn () => HomeResource::active()->get());
    }

    private function cached(string $key, string $table, \Closure $load): Collection
    {
        return Cache::remember($key, 3600, fn () => Schema::hasTable($table) ? $load() : collect());
    }

    /** The lang/home.php group of a section's built-in heading. */
    private function langSection(string $section): string
    {
        return match ($section) {
            'process' => 'timeline',
            default => $section,
        };
    }

    /** A saved field for the locale: `{field}_am` in Amharic, its English when empty. */
    private function pick(array $values, string $field, string $locale): string
    {
        $am = $locale === 'am' ? trim((string) ($values["{$field}_am"] ?? '')) : '';

        return $am !== '' ? $am : trim((string) ($values[$field] ?? ''));
    }

    /** @return array<string, string|null> */
    private function slide(HomeSlide $slide, string $locale): array
    {
        return [
            'badge' => (string) ($slide->localized('badge', $locale) ?? ''),
            'title' => (string) $slide->localized('title', $locale),
            'description' => (string) ($slide->localized('description', $locale) ?? ''),
            'primary_label' => (string) $slide->localized('primary_label', $locale),
            // Editor links: http(s), # anchors and site paths only; anything else becomes '#'.
            'primary_href' => EditorLink::safe($slide->primary_href) ?? '#',
            'secondary_label' => (string) ($slide->localized('secondary_label', $locale) ?? ''),
            'secondary_href' => EditorLink::safe($slide->secondary_href) ?? '#',
            'style' => in_array($slide->bg_style, self::SLIDE_STYLES, true) ? $slide->bg_style : 'blue',
            'image_url' => $slide->bg_image ? asset('storage/'.$slide->bg_image) : null,
        ];
    }

    /** The built-in hero (home.hero.court.*), shown while no slide is saved for the language. */
    private function defaultSlides(): array
    {
        $portal = EuisisParties::portalClosed() ? EuisisParties::portalUrl() : null;

        return [[
            'badge' => (string) __('court::home.hero.court.badge'),
            'title' => (string) __('court::home.hero.court.title'),
            'description' => (string) __('court::home.hero.court.description'),
            'primary_label' => (string) __($portal ? 'court::home.hero.court.portal' : 'court::home.hero.court.hearings'),
            'primary_href' => $portal ?? '#hearings',
            'secondary_label' => (string) __($portal ? 'court::home.hero.court.hearings' : 'court::home.hero.court.about'),
            'secondary_href' => $portal ? '#hearings' : '#about',
            'style' => 'blue',
            'image_url' => null,
        ]];
    }

    /** @return list<array<string, string|null>> */
    private function members(Collection $saved, string $locale): array
    {
        return $saved->map(fn (CourtMember $member): array => [
            'name' => (string) $member->localized('name', $locale),
            'role' => (string) ($member->localized('role', $locale) ?? ''),
            'bio' => (string) ($member->localized('bio', $locale) ?? ''),
            'photo_url' => $member->photo_path ? asset('storage/'.$member->photo_path) : null,
        ])->values()->all();
    }

    /**
     * The hearings of the coming days, as the court's public screen shows them:
     * the case number and type, the sitting and the room; no party's name.
     *
     * @return list<array<string, string|null>>
     */
    private function hearings(): array
    {
        if (! Schema::hasTable('court_case_hearings')) {
            return [];
        }

        return DB::table('court_case_hearings as h')
            ->join('court_cases as c', 'c.id', '=', 'h.case_id')
            ->leftJoin('court_case_types as t', 't.id', '=', 'c.case_type_id')
            ->whereBetween('h.hearing_at', [now()->startOfDay(), now()->addDays(self::HEARING_DAYS)->endOfDay()])
            ->orderBy('h.hearing_at')
            ->limit(self::HEARING_LIMIT)
            ->get(['h.id', 'h.hearing_at', 'h.type', 'h.location', 'c.case_number', 't.name as case_type'])
            ->map(function (object $row): array {
                // The stored wall-clock time, as the court screen shows it.
                $raw = (string) $row->hearing_at;

                return [
                    'id' => (string) $row->id,
                    'date' => substr($raw, 0, 10),
                    'time' => strlen($raw) >= 16 ? substr($raw, 11, 5) : null,
                    'case_number' => (string) $row->case_number,
                    'case_type' => $row->case_type,
                    'type' => $row->type,
                    'location' => $row->location,
                ];
            })->values()->all();
    }

    /** Today at the court: the date and the number of hearings sitting. @return array{date: string, hearings: int} */
    private function today(): array
    {
        return [
            'date' => now()->toDateString(),
            'hearings' => Schema::hasTable('court_case_hearings')
                ? DB::table('court_case_hearings')->whereBetween('hearing_at', [now()->startOfDay(), now()->endOfDay()])->count()
                : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function metrics(array $saved, string $locale): array
    {
        $totalCases = DB::table('court_cases')->count();
        $resolvedCases = DB::table('court_cases')->whereIn('status', ['closed', 'dismissed'])->count();
        // The last card has always counted the hearings of the next 30 days under this key.
        $figures = [
            'total_cases' => $totalCases,
            'resolved_cases' => $resolvedCases,
            'pending_cases' => DB::table('court_cases')->where('status', 'pending')->count(),
            'active_caseload' => max($totalCases - $resolvedCases, 0),
            'hearings_this_week' => DB::table('court_case_hearings')->whereBetween('hearing_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'avg_resolution_time' => DB::table('court_case_hearings')->whereBetween('hearing_at', [now(), now()->addDays(30)])->count(),
        ];
        $field = function (string $key, string $name) use ($saved, $locale): string {
            $value = $this->pick(is_array($saved[$key] ?? null) ? $saved[$key] : [], $name, $locale);
            // An English-only saved label is not shown in Amharic: the reviewed translation is.
            if ($locale === 'am' && trim((string) ($saved[$key]["{$name}_am"] ?? '')) === '') {
                $value = '';
            }

            return $value !== '' ? $value : (string) __("court::home.metrics.cards.{$key}.{$name}");
        };

        return collect($figures)->map(fn (int $value, string $key): array => [
            'key' => $key,
            'label' => $field($key, 'label'),
            'description' => $field($key, 'description'),
            'value' => $value,
        ])->values()->all();
    }

    private function steps(Collection $saved, string $locale): array
    {
        if ($saved->isNotEmpty()) {
            return $saved->map(fn (HomeTimelineStep $step): array => [
                'title' => (string) $step->localized('title', $locale),
                'description' => (string) ($step->localized('description', $locale) ?? ''),
                'meta' => (string) ($step->localized('meta', $locale) ?? ''),
                'duration' => (string) ($step->localized('duration', $locale) ?? ''),
                'color' => in_array($step->color, self::STEP_COLORS, true) ? $step->color : 'blue',
            ])->values()->all();
        }

        return collect(self::DEFAULT_STEPS)->map(fn (string $color, string $key): array => [
            'title' => (string) __("court::home.timeline.steps.{$key}.title"),
            'description' => (string) __("court::home.timeline.steps.{$key}.description"),
            'meta' => (string) __("court::home.timeline.steps.{$key}.meta"),
            'duration' => (string) __("court::home.timeline.steps.{$key}.duration"),
            'color' => $color,
        ])->values()->all();
    }

    private function services(Collection $saved, string $locale): array
    {
        if ($saved->isNotEmpty()) {
            return $saved->map(fn (HomeService $service): array => [
                'title' => (string) $service->localized('title', $locale),
                'description' => (string) ($service->localized('description', $locale) ?? ''),
                'meta' => (string) ($service->localized('meta', $locale) ?? ''),
                'features' => $this->strings((array) ($service->localized('features', $locale) ?? [])),
                'icon' => in_array($service->icon_type, self::SERVICE_ICONS, true) ? $service->icon_type : 'document',
                'accent' => in_array($service->accent, self::SERVICE_ACCENTS, true) ? $service->accent : 'blue',
                'href' => EditorLink::safe($service->href),
            ])->values()->all();
        }

        $portal = EuisisParties::portalClosed() ? EuisisParties::portalUrl() : null;

        return collect(self::DEFAULT_SERVICES)->map(fn (array $card, string $key): array => [
            'title' => (string) __("court::home.court_services.{$key}.title"),
            'description' => (string) __("court::home.court_services.{$key}.description"),
            'meta' => (string) __("court::home.court_services.{$key}.meta"),
            'features' => [],
            'icon' => $card[0],
            'accent' => $card[1],
            'href' => $card[2] === 'portal' ? $portal : $card[2],
        ])->values()->all();
    }

    /** @return array<int, array{question: string, answer: string}> */
    private function faqs(Collection $saved, string $locale): array
    {
        $items = $saved->isNotEmpty()
            ? $saved->map(fn (HomeFaq $faq): array => ['question' => $faq->localized('question', $locale), 'answer' => $faq->localized('answer', $locale)])->all()
            : (array) __('court::home.faq.questions');

        return array_values(array_map(fn ($faq): array => [
            'question' => (string) ($faq['question'] ?? ''),
            'answer' => (string) ($faq['answer'] ?? ''),
        ], $items));
    }

    /** @return array<string, mixed> */
    private function resource(HomeResource $resource, string $locale): array
    {
        return [
            'id' => $resource->id,
            'title' => (string) $resource->localized('title', $locale),
            'type' => $resource->type,
            'description' => $resource->localized('description', $locale) ?: null,
            'is_featured' => (bool) $resource->is_featured,
            'published_at' => $resource->published_at?->toIso8601String(),
            'download_url' => $resource->isDownloadable() ? asset('storage/'.$resource->file_path) : null,
            'external_url' => EditorLink::safe($resource->external_url, allowRelative: false),
            'cover_image_url' => $resource->cover_image ? asset('storage/'.$resource->cover_image) : null,
        ];
    }

    /** @return list<string> */
    private function strings(array $values): array
    {
        return array_values(array_filter(
            array_map(fn ($value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            fn (string $value): bool => $value !== '',
        ));
    }
}
