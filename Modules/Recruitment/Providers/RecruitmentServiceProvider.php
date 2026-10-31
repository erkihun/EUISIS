<?php

declare(strict_types=1);

namespace Modules\Recruitment\Providers;

use App\Services\Organizations\OrganizationDeletionGuard;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Recruitment\Application\Policies\VacancyAnnouncementPolicy;
use Modules\Recruitment\Application\Policies\VacancyApplicationPolicy;
use Modules\Recruitment\Domain\Models\VacancyAnnouncement;
use Modules\Recruitment\Domain\Models\VacancyAnnouncementPosition;
use Modules\Recruitment\Domain\Models\VacancyApplication;
use Modules\Recruitment\Domain\Models\VacancyApplicationDocument;

/**
 * Everything the application needs to know about the Recruitment module
 * (vacancy announcements and applications; ADR 0017): its policies and the
 * names its records keep in the database. A selected applicant moves through
 * the Core's TransferAssignment command. Its routes stay in routes/web.php,
 * which names its controllers.
 */
final class RecruitmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, documents).
        Relation::morphMap([
            'App\Models\VacancyAnnouncement' => VacancyAnnouncement::class,
            'App\Models\VacancyAnnouncementPosition' => VacancyAnnouncementPosition::class,
            'App\Models\VacancyApplication' => VacancyApplication::class,
            'App\Models\VacancyApplicationDocument' => VacancyApplicationDocument::class,
        ]);

        Gate::policy(VacancyAnnouncement::class, VacancyAnnouncementPolicy::class);
        Gate::policy(VacancyApplication::class, VacancyApplicationPolicy::class);

        // Vacancy records that block deleting an organization.
        OrganizationDeletionGuard::registerDependent('vacancy_announcements', 'organization_id');
        OrganizationDeletionGuard::registerDependent('vacancy_announcement_positions', 'organization_id');
        OrganizationDeletionGuard::registerDependent('vacancy_applications', 'current_organization_id');
    }
}
