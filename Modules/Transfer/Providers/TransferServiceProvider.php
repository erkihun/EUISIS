<?php

declare(strict_types=1);

namespace Modules\Transfer\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Transfer\Application\Notifications\TransferAnnouncementNotification;
use Modules\Transfer\Application\Policies\TransferAnnouncementPolicy;
use Modules\Transfer\Application\Policies\TransferApplicationPolicy;
use Modules\Transfer\Application\Policies\TransferSettingPolicy;
use Modules\Transfer\Application\Queries\TransferAnnouncementListingsQuery;
use Modules\Transfer\Application\Services\EmployeeTransferPortalSummary;
use Modules\Transfer\Contracts\EmployeeTransferActivity;
use Modules\Transfer\Contracts\TransferAnnouncementListings;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferAnnouncementPosition;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferApplicationDocument;
use Modules\Transfer\Domain\Models\TransferApproval;
use Modules\Transfer\Domain\Models\TransferRuleOverride;
use Modules\Transfer\Domain\Models\TransferScreeningReview;
use Modules\Transfer\Domain\Models\TransferSetting;

/**
 * Everything the application needs to know about the Transfer module
 * (transfer announcements and applications; ADR 0017): its public contracts,
 * policies, notification renderer, application-number code rule, the tables
 * that block deleting an organization and the names its records keep in the
 * database. Completion writes the Core EmployeeTransfer record and moves the
 * assignment through the Core's TransferAssignment command. Its routes stay
 * in routes/web.php, which names its controllers.
 */
final class TransferServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeTransferActivity::class, EmployeeTransferPortalSummary::class);
        $this->app->bind(TransferAnnouncementListings::class, TransferAnnouncementListingsQuery::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, notifications, documents).
        Relation::morphMap([
            'App\Models\TransferAnnouncement' => TransferAnnouncement::class,
            'App\Models\TransferAnnouncementPosition' => TransferAnnouncementPosition::class,
            'App\Models\TransferApplication' => TransferApplication::class,
            'App\Models\TransferApplicationDocument' => TransferApplicationDocument::class,
            'App\Models\TransferApproval' => TransferApproval::class,
            'App\Models\TransferRuleOverride' => TransferRuleOverride::class,
            'App\Models\TransferScreeningReview' => TransferScreeningReview::class,
            'App\Models\TransferSetting' => TransferSetting::class,
        ]);

        Gate::policy(TransferSetting::class, TransferSettingPolicy::class);
        Gate::policy(TransferAnnouncement::class, TransferAnnouncementPolicy::class);
        Gate::policy(TransferApplication::class, TransferApplicationPolicy::class);

        NotificationPresenter::registerRenderer('transfer', TransferAnnouncementNotification::class);

        OrganizationDeletionGuard::registerDependent('transfer_announcements', 'organization_id');
        OrganizationDeletionGuard::registerDependent('transfer_announcement_positions', 'organization_id');
        OrganizationDeletionGuard::registerDependent('transfer_applications', 'releasing_organization_id', 'receiving_organization_id');

        CodeRuleTargetRegistry::registerTarget(CodeRuleEntityType::TransferApplication, [
            'model' => TransferApplication::class,
            'table' => 'transfer_applications',
            'code_column' => 'application_number',
            'label' => 'Transfer Application Number',
            'immutable_after_create' => true,
        ]);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::TransferApplication, TransferApplication::class, 'application_number', withTrashed: true);
    }
}
