<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PublicAnnouncement;
use App\Models\TransferAnnouncement;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicSiteSectionRegistry as Registry;
use App\Services\SystemSettings\PublicSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public announcements: general notices managed in Public Site Management,
 * with currently open transfer opportunities listed alongside.
 *
 * Transfer announcements remain their own business entity with their own
 * detail and application routes (PublicTransferAnnouncementController); they
 * are only summarised here, never re-implemented.
 */
class PublicAnnouncementController extends Controller
{
    public function __construct(
        private readonly PublicSiteContent $content,
        private readonly PublicSettingsService $settings,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $perPage = (int) ($this->settings->shareableSettings()['public_site.announcements_page_size'] ?? 10);

        $announcements = PublicAnnouncement::query()
            ->visible()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(fn ($inner) => $inner
                    ->where('title_en', ci_like_operator(), $like)->orWhere('title_am', ci_like_operator(), $like)
                    ->orWhere('summary_en', ci_like_operator(), $like)->orWhere('summary_am', ci_like_operator(), $like));
            })
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->paginate(max(5, min(50, $perPage)))
            ->withQueryString()
            ->through(fn (PublicAnnouncement $announcement): array => $this->content->presentAnnouncementSummary($announcement));

        return Inertia::render('Public/Announcements/Index', [
            'sections' => $this->content->sections(Registry::PAGE_ANNOUNCEMENTS),
            'announcements' => $announcements,
            'categories' => PublicAnnouncement::query()->visible()->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
            'openTransfers' => TransferAnnouncement::query()
                ->open()
                ->with(['organization:id,name_en,name_am', 'position:id,title_en,title_am'])
                ->orderBy('closing_date')
                ->limit(5)
                ->get()
                ->map(fn (TransferAnnouncement $transfer): array => PublicTransferAnnouncementController::presentSummary($transfer)),
            'filters' => ['search' => $search, 'category' => $category],
            'meta' => $this->content->meta(Registry::PAGE_ANNOUNCEMENTS),
        ]);
    }

    public function show(string $slug): Response
    {
        $announcement = PublicAnnouncement::query()
            ->visible()
            ->where('slug', $slug)
            ->with('attachments')
            ->firstOrFail();

        return Inertia::render('Public/Announcements/Show', [
            'announcement' => $this->content->presentAnnouncement($announcement),
        ]);
    }
}
