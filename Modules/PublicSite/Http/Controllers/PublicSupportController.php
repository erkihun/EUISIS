<?php

declare(strict_types=1);

namespace Modules\PublicSite\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PublicSite\Application\Services\PublicSiteContent;
use Modules\PublicSite\Application\Services\PublicSiteSectionRegistry as Registry;

/**
 * Public support: contact details, office hours/location and FAQs.
 *
 * There is no support-ticket workflow in this system, so this page does not
 * pretend to have one. Contact email/phone come from the existing
 * `general.support_*` settings (shared on every page); nothing is duplicated.
 */
class PublicSupportController extends Controller
{
    public function index(PublicSiteContent $content): Response
    {
        $sections = $content->sections(Registry::PAGE_SUPPORT);

        return Inertia::render('Public/Support', [
            'sections' => $sections,
            'faqs' => isset($sections['faq']) ? $content->faqs() : [],
            'meta' => $content->meta(Registry::PAGE_SUPPORT),
        ]);
    }
}
