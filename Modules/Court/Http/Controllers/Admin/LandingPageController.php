<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\CourtMember;
use Modules\Court\Domain\Models\HomeFaq;
use Modules\Court\Domain\Models\HomeResource;
use Modules\Court\Domain\Models\HomeService;
use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Domain\Models\HomeSlide;
use Modules\Court\Domain\Models\HomeTimelineStep;
use Modules\Court\Http\Support\Ui\EditorLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LandingPageController extends Controller
{
    /** The Amharic text each kind of row keeps in `translations`, field => longest length. */
    private const AMHARIC = [
        'slide' => ['badge', 'title', 'description', 'primary_label', 'secondary_label'],
        'step' => ['title', 'description', 'meta', 'duration'],
        'faq' => ['question', 'answer'],
        'service' => ['title', 'description', 'meta', 'features'],
        'resource' => ['title', 'description'],
        'member' => ['name', 'role', 'bio'],
    ];

    private const AMHARIC_MAX = [
        'badge' => 120, 'title' => 255, 'description' => 2000, 'primary_label' => 120, 'secondary_label' => 120,
        'meta' => 120, 'duration' => 60, 'question' => 500, 'answer' => 2000, 'name' => 255, 'role' => 255, 'bio' => 2000,
    ];

    private const FOOTER_FIELDS = [
        'description', 'description_am', 'authority_name', 'authority_name_am',
        'contact_phone', 'contact_email', 'contact_address', 'contact_address_am',
        'office_hours', 'office_hours_am', 'map_url',
        'social_facebook', 'social_twitter', 'social_linkedin', 'social_youtube', 'social_telegram', 'social_instagram',
    ];

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function flushCache(): void
    {
        foreach (['court.home_slides','court.home_faqs','court.home_settings','court.home_metrics','court.home_services','court.home_timeline','court.home_resources','court.home_footer','court.home_user_manual','court.home_about','court.home_members'] as $key) {
            Cache::forget($key);
        }
    }

    private function defaultSections(): array
    {
        $titled = ['visible' => true, 'title' => '', 'subtitle' => '', 'title_am' => '', 'subtitle_am' => ''];

        return [
            'about'    => $titled,
            'members'  => $titled,
            'services' => $titled,
            'hearings' => $titled,
            'metrics'  => $titled,
            'process'  => $titled,
            'news'     => $titled,
            'resources'=> $titled,
            'faq'      => $titled,
            'contact'  => $titled,
            'cases'    => ['visible' => true],
            'cta'      => [
                'visible'         => true,
                'title'           => '',
                'description'     => '',
                'primary_label'   => '',
                'primary_href'    => '',
                'secondary_label' => '',
                'secondary_href'  => '',
            ],
        ];
    }

    private function loadSections(): array
    {
        $sections = $this->defaultSections();
        foreach (array_keys($sections) as $key) {
            $stored = HomeSetting::getJson("section.{$key}");
            if ($stored) {
                $sections[$key] = array_merge($sections[$key], $stored);
            }
        }
        return $sections;
    }

    private function storeFile(Request $request, string $field, string $dir): ?string
    {
        if (!$request->hasFile($field)) return null;
        return $request->file($field)->store("landing/{$dir}", 'court_public');
    }

    private function deleteFile(?string $path): void
    {
        if ($path) Storage::disk('court_public')->delete($path);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index()
    {
        $slides    = HomeSlide::orderBy('sort_order')->get();
        $faqs      = HomeFaq::orderBy('sort_order')->get();
        $services  = HomeService::orderBy('sort_order')->get();
        $steps     = HomeTimelineStep::orderBy('sort_order')->get();
        $resources = HomeResource::orderBy('sort_order')->get();
        $members   = CourtMember::orderBy('sort_order')->get();
        $about     = HomeSetting::getJson('about.settings');
        $about     = is_array($about) ? $about : [];
        $userManual = HomeSetting::getJson('user_manual.settings');
        $sections  = $this->loadSections();
        $metrics   = HomeSetting::getJson('metrics.content');
        $footer    = HomeSetting::getJson('footer.settings');
        $totalCases = DB::table('court_cases')->count();
        $resolvedCases = DB::table('court_cases')->whereIn('status', ['closed', 'dismissed'])->count();
        $pendingCases = DB::table('court_cases')->where('status', 'pending')->count();
        $openCases = max($totalCases - $resolvedCases, 0);
        $upcomingHearings = DB::table('court_case_hearings')->where('hearing_at', '>=', now())->count();
        $hearingsThisWeek = DB::table('court_case_hearings')
            ->whereBetween('hearing_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        $metricKeys = ['total_cases', 'resolved_cases', 'pending_cases', 'active_caseload', 'hearings_this_week', 'avg_resolution_time'];
        $footerFields = self::FOOTER_FIELDS;
        $tab = (string) session('landing_tab', 'slides');

        return Inertia::render('Court/Landing/Index', [
            'initialTab' => in_array($tab, ['slides', 'about', 'members', 'metrics', 'timeline', 'services', 'resources', 'manual', 'faqs', 'footer', 'sections'], true) ? $tab : 'slides',
            'homeUrl' => route('court.landing.home'),
            'slides' => $slides->map(fn (HomeSlide $slide): array => [
                'id' => $slide->id,
                'badge' => $slide->badge,
                'title' => (string) $slide->title,
                'description' => $slide->description,
                'primary_label' => (string) $slide->primary_label,
                'primary_href' => (string) $slide->primary_href,
                'secondary_label' => $slide->secondary_label,
                'secondary_href' => $slide->secondary_href,
                'bg_style' => $slide->bg_style ?: 'blue',
                'bg_image' => $slide->bg_image,
                'bg_image_url' => $slide->bg_image ? asset('storage/'.$slide->bg_image) : null,
                'is_active' => (bool) $slide->is_active,
                'am' => $slide->amharicFields(self::AMHARIC['slide']),
            ])->values(),
            'steps' => $steps->map(fn (HomeTimelineStep $step): array => [
                'id' => $step->id,
                'title' => (string) $step->title,
                'description' => $step->description,
                'meta' => $step->meta,
                'duration' => $step->duration,
                'color' => $step->color ?: 'blue',
                'is_active' => (bool) $step->is_active,
                'am' => $step->amharicFields(self::AMHARIC['step']),
            ])->values(),
            'services' => $services->map(fn (HomeService $service): array => [
                'id' => $service->id,
                'title' => (string) $service->title,
                'description' => $service->description,
                'meta' => $service->meta,
                'features' => array_values($service->features ?? []),
                'icon_type' => $service->icon_type ?: 'document',
                'accent' => $service->accent ?: 'blue',
                'href' => $service->href,
                'is_active' => (bool) $service->is_active,
                'am' => $service->amharicFields(self::AMHARIC['service']),
            ])->values(),
            'resources' => $resources->map(fn (HomeResource $resource): array => [
                'id' => $resource->id,
                'title' => (string) $resource->title,
                'type' => $resource->type,
                'description' => $resource->description,
                'external_url' => $resource->external_url,
                'is_featured' => (bool) $resource->is_featured,
                'is_active' => (bool) $resource->is_active,
                'published_at' => $resource->published_at?->toIso8601String(),
                'published_at_local' => $resource->published_at?->format('Y-m-d\TH:i'),
                'is_scheduled' => (bool) $resource->published_at?->isFuture(),
                'file_path' => $resource->file_path,
                'cover_image' => $resource->cover_image,
                'cover_image_url' => $resource->cover_image ? asset('storage/'.$resource->cover_image) : null,
                'am' => $resource->amharicFields(self::AMHARIC['resource']),
            ])->values(),
            'members' => $members->map(fn (CourtMember $member): array => [
                'id' => $member->id,
                'name' => (string) $member->name,
                'role' => $member->role,
                'bio' => $member->bio,
                'photo_url' => $member->photo_path ? asset('storage/'.$member->photo_path) : null,
                'is_active' => (bool) $member->is_active,
                'am' => $member->amharicFields(self::AMHARIC['member']),
            ])->values(),
            'about' => [
                'body' => (string) ($about['body'] ?? ''),
                'body_am' => (string) ($about['body_am'] ?? ''),
                'facts' => array_values(array_map(fn ($fact): array => [
                    'label' => (string) ($fact['label'] ?? ''),
                    'value' => (string) ($fact['value'] ?? ''),
                    'label_am' => (string) ($fact['label_am'] ?? ''),
                    'value_am' => (string) ($fact['value_am'] ?? ''),
                ], array_filter((array) ($about['facts'] ?? []), 'is_array'))),
            ],
            'faqs' => $faqs->map(fn (HomeFaq $faq): array => [
                'id' => $faq->id,
                'question' => (string) $faq->question,
                'answer' => (string) $faq->answer,
                'is_active' => (bool) $faq->is_active,
                'am' => $faq->amharicFields(self::AMHARIC['faq']),
            ])->values(),
            'stats' => [
                'total_cases' => $totalCases,
                'resolved_cases' => $resolvedCases,
                'pending_cases' => $pendingCases,
                'active_caseload' => $openCases,
                'hearings_this_week' => $hearingsThisWeek,
                // The Blade card showed upcoming hearings under this key, as the home page does.
                'avg_resolution_time' => $upcomingHearings,
            ],
            'metrics' => collect($metricKeys)->mapWithKeys(fn (string $key): array => [$key => [
                'label' => (string) ($metrics[$key]['label'] ?? ''),
                'description' => (string) ($metrics[$key]['description'] ?? ''),
                'label_am' => (string) ($metrics[$key]['label_am'] ?? ''),
                'description_am' => (string) ($metrics[$key]['description_am'] ?? ''),
            ]])->all(),
            'metricDefaults' => collect($metricKeys)->mapWithKeys(fn (string $key): array => [$key => [
                'label' => __("court::home.metrics.cards.{$key}.label", [], 'en'),
                'description' => __("court::home.metrics.cards.{$key}.description", [], 'en'),
            ]])->all(),
            'sections' => $sections,
            'footer' => collect($footerFields)->mapWithKeys(fn (string $field): array => [$field => (string) ($footer[$field] ?? '')])->all(),
            'userManual' => [
                'title_en' => (string) ($userManual['title_en'] ?? 'User Manual'),
                'title_am' => (string) ($userManual['title_am'] ?? __('court::home.nav.user_manual', [], 'am')),
                'content_en' => (string) ($userManual['content_en'] ?? ''),
                'content_am' => (string) ($userManual['content_am'] ?? ''),
                'is_active' => (bool) ($userManual['is_active'] ?? true),
            ],
        ]);
    }

    // ── Slides ────────────────────────────────────────────────────────────────

    /** The slide form: its button links are http(s), # anchors or site paths only (EditorLink). */
    private function slideRules(): array
    {
        return [
            'badge'           => 'nullable|string|max:120',
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'primary_label'   => 'required|string|max:120',
            'primary_href'    => ['required', 'string', 'max:500', EditorLink::rule()],
            'secondary_label' => 'nullable|string|max:120',
            'secondary_href'  => ['nullable', 'string', 'max:500', EditorLink::rule()],
            'bg_style'        => 'required|in:blue,orange,emerald',
            'bg_image'        => 'nullable|image|max:3072',
            ...$this->amharicRules('slide'),
        ];
    }

    public function storeSlide(Request $request)
    {
        $data = $this->withAmharic($request, $request->validate($this->slideRules(), [], $this->attributeNames()), 'slide');

        $data['bg_image']    = $this->storeFile($request, 'bg_image', 'slides');
        $data['sort_order']  = HomeSlide::max('sort_order') + 1;
        HomeSlide::create($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.slide_added'));
    }

    public function updateSlide(Request $request, HomeSlide $slide)
    {
        $data = $this->withAmharic($request, $request->validate($this->slideRules(), [], $this->attributeNames()), 'slide');

        if ($request->hasFile('bg_image')) {
            $this->deleteFile($slide->bg_image);
            $data['bg_image'] = $this->storeFile($request, 'bg_image', 'slides');
        } else {
            unset($data['bg_image']);
        }

        if ($request->boolean('remove_bg_image')) {
            $this->deleteFile($slide->bg_image);
            $data['bg_image'] = null;
        }

        $slide->update($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.slide_updated'));
    }

    public function destroySlide(HomeSlide $slide)
    {
        $this->deleteFile($slide->bg_image);
        $slide->delete();
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.slide_deleted'));
    }

    public function toggleSlide(HomeSlide $slide)
    {
        $slide->update(['is_active' => !$slide->is_active]);
        $this->flushCache();
        return back();
    }

    public function reorderSlides(Request $request)
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);
        foreach ($request->order as $position => $id) {
            HomeSlide::where('id', $id)->update(['sort_order' => $position]);
        }
        $this->flushCache();
        return response()->json(['ok' => true]);
    }

    // ── Timeline steps ────────────────────────────────────────────────────────

    public function storeStep(Request $request)
    {
        $data = $this->withAmharic($request, $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'meta'        => 'nullable|string|max:120',
            'duration'    => 'nullable|string|max:60',
            'color'       => 'required|in:blue,orange,emerald,violet,amber',
            ...$this->amharicRules('step'),
        ], [], $this->attributeNames()), 'step');

        $data['sort_order'] = HomeTimelineStep::max('sort_order') + 1;
        HomeTimelineStep::create($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.step_added'));
    }

    public function updateStep(Request $request, HomeTimelineStep $step)
    {
        $data = $this->withAmharic($request, $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'meta'        => 'nullable|string|max:120',
            'duration'    => 'nullable|string|max:60',
            'color'       => 'required|in:blue,orange,emerald,violet,amber',
            ...$this->amharicRules('step'),
        ], [], $this->attributeNames()), 'step');

        $step->update($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.step_updated'));
    }

    public function destroyStep(HomeTimelineStep $step)
    {
        $step->delete();
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.step_deleted'));
    }

    public function toggleStep(HomeTimelineStep $step)
    {
        $step->update(['is_active' => !$step->is_active]);
        $this->flushCache();
        return back();
    }

    // ── FAQs ──────────────────────────────────────────────────────────────────

    public function storeFaq(Request $request)
    {
        $data = $this->withAmharic($request, $request->validate([
            'question' => 'required|string|max:500',
            'answer'   => 'required|string|max:2000',
            ...$this->amharicRules('faq'),
        ], [], $this->attributeNames()), 'faq');
        $data['sort_order'] = HomeFaq::max('sort_order') + 1;
        HomeFaq::create($data);
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.faq_added'));
    }

    public function updateFaq(Request $request, HomeFaq $faq)
    {
        $faq->update($this->withAmharic($request, $request->validate([
            'question' => 'required|string|max:500',
            'answer'   => 'required|string|max:2000',
            ...$this->amharicRules('faq'),
        ], [], $this->attributeNames()), 'faq'));
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.faq_updated'));
    }

    public function destroyFaq(HomeFaq $faq)
    {
        $faq->delete();
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.faq_deleted'));
    }

    public function toggleFaq(HomeFaq $faq)
    {
        $faq->update(['is_active' => !$faq->is_active]);
        $this->flushCache();
        return back();
    }

    // ── Services ──────────────────────────────────────────────────────────────

    public function storeService(Request $request)
    {
        $data = $this->withAmharic($request, $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'meta'        => 'nullable|string|max:120',
            'features'    => 'nullable|string',
            'icon_type'   => 'required|in:document,calendar,lock,chart,database,chat',
            'accent'      => 'required|in:blue,orange,emerald,violet,amber,pink',
            'href'        => ['nullable', 'string', 'max:500', EditorLink::rule()],
            ...$this->amharicRules('service'),
        ], [], $this->attributeNames()), 'service');
        $data['features']   = $this->parseLines($request->input('features', ''));
        $data['sort_order'] = HomeService::max('sort_order') + 1;
        HomeService::create($data);
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.service_added'));
    }

    public function updateService(Request $request, HomeService $service)
    {
        $data = $this->withAmharic($request, $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'meta'        => 'nullable|string|max:120',
            'features'    => 'nullable|string',
            'icon_type'   => 'required|in:document,calendar,lock,chart,database,chat',
            'accent'      => 'required|in:blue,orange,emerald,violet,amber,pink',
            'href'        => ['nullable', 'string', 'max:500', EditorLink::rule()],
            ...$this->amharicRules('service'),
        ], [], $this->attributeNames()), 'service');
        $data['features'] = $this->parseLines($request->input('features', ''));
        $service->update($data);
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.service_updated'));
    }

    public function destroyService(HomeService $service)
    {
        $service->delete();
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.service_deleted'));
    }

    public function toggleService(HomeService $service)
    {
        $service->update(['is_active' => !$service->is_active]);
        $this->flushCache();
        return back();
    }

    // ── Resources ────────────────────────────────────────────────────────────

    /** The resource form: an external link is an http(s) address only (EditorLink). */
    private function resourceRules(): array
    {
        return [
            'title'        => 'required|string|max:255',
            'type'         => 'required|in:post,form,document,link',
            'description'  => 'nullable|string|max:2000',
            'external_url' => ['nullable', 'string', 'max:500', EditorLink::rule(allowRelative: false)],
            'is_featured'  => 'boolean',
            'published_at' => 'nullable|date',
            'file'         => 'nullable|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,zip',
            'cover_image'  => 'nullable|image|max:2048',
            ...$this->amharicRules('resource'),
        ];
    }

    public function storeResource(Request $request)
    {
        $data = $this->withAmharic($request, $request->validate($this->resourceRules(), [], $this->attributeNames()), 'resource');

        $data['file_path']    = $this->storeFile($request, 'file', 'resources');
        $data['cover_image']  = $this->storeFile($request, 'cover_image', 'covers');
        $data['is_featured']  = $request->boolean('is_featured');
        $data['sort_order']   = HomeResource::max('sort_order') + 1;
        HomeResource::create($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.resource_added'));
    }

    public function updateResource(Request $request, HomeResource $resource)
    {
        $data = $this->withAmharic($request, $request->validate($this->resourceRules(), [], $this->attributeNames()), 'resource');

        if ($request->hasFile('file')) {
            $this->deleteFile($resource->file_path);
            $data['file_path'] = $this->storeFile($request, 'file', 'resources');
        } else {
            unset($data['file']);
        }

        if ($request->hasFile('cover_image')) {
            $this->deleteFile($resource->cover_image);
            $data['cover_image'] = $this->storeFile($request, 'cover_image', 'covers');
        } else {
            unset($data['cover_image']);
        }

        if ($request->boolean('remove_file')) {
            $this->deleteFile($resource->file_path);
            $data['file_path'] = null;
        }

        $data['is_featured'] = $request->boolean('is_featured');
        $resource->update($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.resource_updated'));
    }

    public function destroyResource(HomeResource $resource)
    {
        $this->deleteFile($resource->file_path);
        $this->deleteFile($resource->cover_image);
        $resource->delete();
        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.resource_deleted'));
    }

    public function toggleResource(HomeResource $resource)
    {
        $resource->update(['is_active' => !$resource->is_active]);
        $this->flushCache();
        return back();
    }

    public function updateUserManual(Request $request)
    {
        $data = $request->validate([
            'title_en' => 'required|string|max:255',
            'title_am' => 'required|string|max:255',
            'content_en' => 'required|string|max:50000',
            'content_am' => 'required|string|max:50000',
            'is_active' => 'boolean',
        ], [], $this->attributeNames());
        $data['content_en'] = $this->sanitizeManualHtml($data['content_en']);
        $data['content_am'] = $this->sanitizeManualHtml($data['content_am']);
        $data['is_active'] = $request->boolean('is_active');
        HomeSetting::setJson('user_manual.settings', $data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.manual.saved'))->with('landing_tab', 'resources');
    }

    // ── Footer settings ───────────────────────────────────────────────────────

    public function updateFooter(Request $request)
    {
        // Social links are http(s) addresses only (EditorLink): Laravel's `url`
        // rule alone lets through a scheme such as "javascript://…".
        $social = ['nullable', 'string', 'max:300', EditorLink::rule(allowRelative: false)];
        $data = $request->validate([
            'description'         => 'nullable|string|max:500',
            'description_am'      => 'nullable|string|max:500',
            'authority_name'      => 'nullable|string|max:160',
            'authority_name_am'   => 'nullable|string|max:160',
            'contact_phone'       => 'nullable|string|max:60',
            'contact_email'       => 'nullable|email|max:120',
            'contact_address'     => 'nullable|string|max:255',
            'contact_address_am'  => 'nullable|string|max:255',
            'office_hours'        => 'nullable|string|max:255',
            'office_hours_am'     => 'nullable|string|max:255',
            'map_url'             => ['nullable', 'string', 'max:500', EditorLink::rule(allowRelative: false)],
            'social_facebook'     => $social,
            'social_twitter'      => $social,
            'social_linkedin'     => $social,
            'social_youtube'      => $social,
            'social_telegram'     => $social,
            'social_instagram'    => $social,
        ], [], $this->attributeNames());

        HomeSetting::setJson('footer.settings', $data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.footer_saved'))->with('landing_tab', 'footer');
    }

    // ── Metrics content ──────────────────────────────────────────────────────

    public function updateMetrics(Request $request)
    {
        $metricKeys = [
            'total_cases',
            'resolved_cases',
            'pending_cases',
            'active_caseload',
            'hearings_this_week',
            'avg_resolution_time',
        ];

        $rules = [];
        foreach ($metricKeys as $key) {
            $rules["{$key}.label"] = 'nullable|string|max:120';
            $rules["{$key}.description"] = 'nullable|string|max:255';
            $rules["{$key}.label_am"] = 'nullable|string|max:120';
            $rules["{$key}.description_am"] = 'nullable|string|max:255';
        }

        HomeSetting::setJson('metrics.content', $request->validate($rules));
        $this->flushCache();

        return back()
            ->with('success', __('court::admin_landing.flash.metrics_saved'))
            ->with('landing_tab', 'metrics');
    }

    // ── Section settings ──────────────────────────────────────────────────────

    public function updateSections(Request $request)
    {
        $titled = [];
        foreach (self::TITLED_SECTIONS as $section) {
            $titled["{$section}.visible"] = 'boolean';
            $titled["{$section}.title"] = 'nullable|string|max:255';
            $titled["{$section}.subtitle"] = 'nullable|string|max:500';
            $titled["{$section}.title_am"] = 'nullable|string|max:255';
            $titled["{$section}.subtitle_am"] = 'nullable|string|max:500';
        }
        $validated = $request->validate([
            ...$titled,
            'cases.visible'            => 'boolean',
            'cta.visible'              => 'boolean',
            'cta.title'                => 'nullable|string|max:255',
            'cta.description'          => 'nullable|string|max:500',
            'cta.primary_label'        => 'nullable|string|max:120',
            'cta.primary_href'         => ['nullable', 'string', 'max:500', EditorLink::rule()],
            'cta.secondary_label'      => 'nullable|string|max:120',
            'cta.secondary_href'       => ['nullable', 'string', 'max:500', EditorLink::rule()],
        ], [], $this->attributeNames());

        // Only the validated keys are stored: the request's own section arrays
        // could carry any other key into the settings rows.
        foreach ($validated as $key => $value) {
            $value = is_array($value) ? $value : [];
            $value['visible'] = (bool) ($value['visible'] ?? false);
            HomeSetting::setJson("section.{$key}", $value);
        }

        $this->flushCache();
        return back()->with('success', __('court::admin_landing.flash.sections_saved'))->with('landing_tab', 'sections');
    }

    // ── About the court ───────────────────────────────────────────────────────

    /** The mandate text and the key facts (established, seat, jurisdiction, …) in both languages. */
    public function updateAbout(Request $request)
    {
        $data = $request->validate([
            'body'             => 'nullable|string|max:5000',
            'body_am'          => 'nullable|string|max:5000',
            'facts'            => 'nullable|array|max:8',
            'facts.*.label'    => 'nullable|string|max:120',
            'facts.*.value'    => 'nullable|string|max:255',
            'facts.*.label_am' => 'nullable|string|max:120',
            'facts.*.value_am' => 'nullable|string|max:255',
        ], [], $this->attributeNames());
        $data['facts'] = array_values(array_filter(
            array_map(fn (array $fact): array => array_map(fn ($value): string => trim((string) $value), $fact + ['label' => '', 'value' => '', 'label_am' => '', 'value_am' => '']), (array) ($data['facts'] ?? [])),
            fn (array $fact): bool => $fact['label'] !== '' || $fact['label_am'] !== '',
        ));

        HomeSetting::setJson('about.settings', $data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.about_saved'))->with('landing_tab', 'about');
    }

    // ── Members of the court ──────────────────────────────────────────────────

    private function memberRules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'role'  => 'nullable|string|max:255',
            'bio'   => 'nullable|string|max:2000',
            'photo' => 'nullable|image|max:2048',
            ...$this->amharicRules('member'),
        ];
    }

    public function storeMember(Request $request)
    {
        $data = $this->withAmharic($request, $request->validate($this->memberRules(), [], $this->attributeNames()), 'member');
        unset($data['photo']);
        $data['photo_path'] = $this->storeFile($request, 'photo', 'members');
        $data['sort_order'] = (int) CourtMember::max('sort_order') + 1;
        CourtMember::create($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.member_added'))->with('landing_tab', 'members');
    }

    public function updateMember(Request $request, CourtMember $member)
    {
        $data = $this->withAmharic($request, $request->validate($this->memberRules(), [], $this->attributeNames()), 'member');
        unset($data['photo']);
        if ($request->hasFile('photo')) {
            $this->deleteFile($member->photo_path);
            $data['photo_path'] = $this->storeFile($request, 'photo', 'members');
        } elseif ($request->boolean('remove_photo')) {
            $this->deleteFile($member->photo_path);
            $data['photo_path'] = null;
        }
        $member->update($data);
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.member_updated'))->with('landing_tab', 'members');
    }

    public function destroyMember(CourtMember $member)
    {
        $this->deleteFile($member->photo_path);
        $member->delete();
        $this->flushCache();

        return back()->with('success', __('court::admin_landing.flash.member_deleted'))->with('landing_tab', 'members');
    }

    public function toggleMember(CourtMember $member)
    {
        $member->update(['is_active' => ! $member->is_active]);
        $this->flushCache();

        return back()->with('landing_tab', 'members');
    }

    public function reorderMembers(Request $request)
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);
        foreach ($request->input('order') as $position => $id) {
            CourtMember::where('id', $id)->update(['sort_order' => $position]);
        }
        $this->flushCache();

        return back()->with('landing_tab', 'members');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Sections with a title and subtitle in each language. */
    private const TITLED_SECTIONS = ['about', 'members', 'services', 'hearings', 'metrics', 'process', 'news', 'resources', 'faq', 'contact'];

    /** The `am[field]` rules of a kind of row. @return array<string, string> */
    private function amharicRules(string $kind): array
    {
        $rules = ['am' => 'nullable|array'];
        foreach (self::AMHARIC[$kind] as $field) {
            $rules["am.{$field}"] = $field === 'features' ? 'nullable|string|max:4000' : 'nullable|string|max:'.self::AMHARIC_MAX[$field];
        }

        return $rules;
    }

    /**
     * The validated row with its Amharic text in `translations` (empty fields dropped;
     * the features list one per line, as the English one).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withAmharic(Request $request, array $data, string $kind): array
    {
        $am = [];
        foreach (self::AMHARIC[$kind] as $field) {
            $value = trim((string) $request->input("am.{$field}", ''));
            if ($value !== '') {
                $am[$field] = $field === 'features' ? $this->parseLines($value) : $value;
            }
        }
        unset($data['am']);
        $data['translations'] = $am === [] ? null : ['am' => $am];

        return $data;
    }

    private function parseLines(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $raw)))
        ));
    }

    /**
     * The manual is public HTML. strip_tags plus regexes let an entity-encoded
     * `javascript:` link through, so it goes through HTMLPurifier's default
     * profile, which keeps the headings, lists, tables and inline styles a
     * manual needs. UserManualController purifies it again on output.
     */
    private function sanitizeManualHtml(string $html): string
    {
        // HTMLPurifier writes the platform's line endings; store "\n" everywhere.
        return trim((string) preg_replace('/\r\n?/', "\n", (string) \Modules\Court\Application\Support\Purifier::clean($html, 'default')));
    }

    /**
     * Field names for the validation messages, in the request's language.
     *
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $common = [
            'badge' => 'badge_text', 'title' => 'title', 'description' => 'description',
            'primary_label' => 'primary_button_label', 'primary_href' => 'primary_button_url',
            'secondary_label' => 'secondary_button_label', 'secondary_href' => 'secondary_button_url',
            'bg_style' => 'background_style', 'bg_image' => 'background_image',
            'meta' => 'meta_label', 'duration' => 'duration', 'color' => 'colour_accent',
            'features' => 'features', 'icon_type' => 'icon', 'accent' => 'accent_colour',
            'type' => 'type', 'published_at' => 'publish_date', 'external_url' => 'external_url',
            'file' => 'file', 'cover_image' => 'cover_image', 'question' => 'question', 'answer' => 'answer',
            'contact_phone' => 'phone', 'contact_email' => 'email', 'contact_address' => 'physical_address',
            'cta.title' => 'banner_title', 'cta.description' => 'banner_description',
            'cta.primary_label' => 'primary_button_label', 'cta.primary_href' => 'primary_button_url',
            'cta.secondary_label' => 'secondary_button_label', 'cta.secondary_href' => 'secondary_button_url',
        ];
        $names = array_map(fn (string $key): string => (string) __("court::admin_landing.common.{$key}"), $common);

        foreach (self::TITLED_SECTIONS as $section) {
            $names["{$section}.title"] = (string) __('court::admin_landing.sections.section_title');
            $names["{$section}.subtitle"] = (string) __('court::admin_landing.sections.subtitle');
            $names["{$section}.title_am"] = (string) __('court::admin_landing.sections.section_title').' ('.__('court::admin_landing.common.amharic').')';
            $names["{$section}.subtitle_am"] = (string) __('court::admin_landing.sections.subtitle').' ('.__('court::admin_landing.common.amharic').')';
        }
        foreach (['name' => 'member_name', 'role' => 'member_role', 'bio' => 'member_bio', 'photo' => 'member_photo', 'href' => 'card_link',
            'body' => 'about_body', 'body_am' => 'about_body', 'office_hours' => 'office_hours', 'office_hours_am' => 'office_hours',
            'map_url' => 'map_url', 'authority_name' => 'authority_name', 'authority_name_am' => 'authority_name'] as $field => $key) {
            $names[$field] = (string) __("court::admin_landing.common.{$key}");
        }
        foreach (array_unique(array_merge(...array_values(self::AMHARIC))) as $field) {
            $names["am.{$field}"] = ($names[$field] ?? $field).' ('.__('court::admin_landing.common.amharic').')';
        }
        foreach (['title_en', 'title_am', 'content_en', 'content_am'] as $field) {
            $names[$field] = (string) __("court::admin_landing.manual.{$field}");
        }
        // The networks are proper names, the same in both languages.
        foreach (['facebook' => 'Facebook', 'twitter' => 'Twitter / X', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'telegram' => 'Telegram', 'instagram' => 'Instagram'] as $network => $label) {
            $names["social_{$network}"] = $label;
        }

        return $names;
    }
}
