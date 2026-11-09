<?php

declare(strict_types=1);

namespace Modules\PublicSite\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\PublicSite\Application\Queries\PublicSiteRebrandingCommand;
use Modules\PublicSite\Application\Services\PublicSiteContent;
use Modules\PublicSite\Contracts\PublicSitePages;
use Modules\PublicSite\Contracts\PublicSiteRebranding;
use Modules\PublicSite\Domain\Models\PublicAnnouncement;
use Modules\PublicSite\Domain\Models\PublicAnnouncementAttachment;
use Modules\PublicSite\Domain\Models\PublicFaq;
use Modules\PublicSite\Domain\Models\PublicFooterLink;
use Modules\PublicSite\Domain\Models\PublicNavigationItem;
use Modules\PublicSite\Domain\Models\PublicPageMeta;
use Modules\PublicSite\Domain\Models\PublicPageSection;
use Modules\PublicSite\Domain\Models\PublicService;
use Modules\PublicSite\Http\Middleware\EnsurePublicSiteEnabled;

/**
 * Everything the application needs to know about the Public Site module (the
 * public website's pages, sections, navigation, services, FAQs and
 * announcements, and their management; ADR 0017): its public contracts, the
 * `public.site` middleware and the names its records keep in the database.
 * Its routes stay in routes/web.php, which names its controllers.
 */
final class PublicSiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PublicSitePages::class, PublicSiteContent::class);
        $this->app->bind(PublicSiteRebranding::class, PublicSiteRebrandingCommand::class);
    }

    public function boot(Router $router): void
    {
        // Moved models keep their old class names in the database (audit logs, attachments).
        Relation::morphMap([
            'App\Models\PublicAnnouncement' => PublicAnnouncement::class,
            'App\Models\PublicAnnouncementAttachment' => PublicAnnouncementAttachment::class,
            'App\Models\PublicFaq' => PublicFaq::class,
            'App\Models\PublicFooterLink' => PublicFooterLink::class,
            'App\Models\PublicNavigationItem' => PublicNavigationItem::class,
            'App\Models\PublicPageMeta' => PublicPageMeta::class,
            'App\Models\PublicPageSection' => PublicPageSection::class,
            'App\Models\PublicService' => PublicService::class,
        ]);

        $router->aliasMiddleware('public.site', EnsurePublicSiteEnabled::class);
    }
}
