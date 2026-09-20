<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PublicService;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicSiteSectionRegistry as Registry;
use App\Services\SystemSettings\PublicSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public services, managed in Public Site Management. Only services with the
 * Published status are listed; drafts, hidden and archived ones never are.
 */
class PublicServicesController extends Controller
{
    public function __construct(
        private readonly PublicSiteContent $content,
        private readonly PublicSettingsService $settings,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) ($this->settings->shareableSettings()['public_site.services_page_size'] ?? 12);

        $services = PublicService::query()
            ->visible()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name_en', ci_like_operator(), $like)->orWhere('name_am', ci_like_operator(), $like)
                    ->orWhere('short_description_en', ci_like_operator(), $like)->orWhere('short_description_am', ci_like_operator(), $like));
            })
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->paginate(max(6, min(48, $perPage)))
            ->withQueryString()
            ->through(fn (PublicService $service): array => $this->content->presentServiceSummary($service));

        return Inertia::render('Public/Services', [
            'sections' => $this->content->sections(Registry::PAGE_SERVICES),
            'services' => $services,
            'filters' => ['search' => $search],
            'meta' => $this->content->meta(Registry::PAGE_SERVICES),
        ]);
    }

    public function show(string $slug): Response
    {
        $service = PublicService::query()->visible()->where('slug', $slug)->firstOrFail();

        return Inertia::render('Public/ServiceShow', [
            'service' => $this->content->presentService($service),
        ]);
    }
}
