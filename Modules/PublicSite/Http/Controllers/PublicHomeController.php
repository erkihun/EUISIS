<?php

declare(strict_types=1);

namespace Modules\PublicSite\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PublicSite\Application\Services\PublicSiteContent;
use Modules\PublicSite\Application\Services\PublicSiteSectionRegistry as Registry;

/**
 * The public home page. Every word on it comes from Public Site Management;
 * which sections appear, and in what order, is the administrator's choice
 * within the fixed set in PublicSiteSectionRegistry.
 */
class PublicHomeController extends Controller
{
    public function __invoke(PublicSiteContent $content): Response
    {
        $sections = $content->sections(Registry::PAGE_HOME);

        $servicesLimit = (int) ($sections['featured_services']['options']['max_items'] ?? 4);
        $announcementsLimit = (int) ($sections['latest_announcements']['options']['max_items'] ?? 3);

        return Inertia::render('Welcome', [
            'sections' => $sections,
            // Only queried when their section is switched on.
            'services' => isset($sections['featured_services']) ? $content->featuredServices($servicesLimit) : [],
            'announcements' => isset($sections['latest_announcements']) ? $content->latestAnnouncements($announcementsLimit) : [],
            'meta' => $content->meta(Registry::PAGE_HOME),
        ]);
    }
}
