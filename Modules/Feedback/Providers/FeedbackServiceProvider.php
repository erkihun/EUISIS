<?php

declare(strict_types=1);

namespace Modules\Feedback\Providers;

use App\Backbone\Contracts\EmployeeFeedbackLinks;
use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Support\NotificationPresenter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Modules\Feedback\Application\Notifications\PublicFeedbackNotification;
use Modules\Feedback\Application\Policies\EmployeeServiceFeedbackPolicy;
use Modules\Feedback\Application\Policies\PublicFeedbackReportExportPolicy;
use Modules\Feedback\Application\Queries\EmployeeFeedbackQrQuery;
use Modules\Feedback\Application\Queries\FeedbackReportExportCommands;
use Modules\Feedback\Application\Queries\PublicFeedbackTargetsQuery;
use Modules\Feedback\Application\Services\EmployeeFeedbackLinkAdapter;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrAccess;
use Modules\Feedback\Application\Services\PublicFeedbackAnswerStatistics;
use Modules\Feedback\Application\Services\PublicFeedbackDataQuality;
use Modules\Feedback\Application\Services\PublicFeedbackQuestionnaire;
use Modules\Feedback\Application\Services\PublicFeedbackSettings;
use Modules\Feedback\Application\Services\PublicFeedbackVisibility;
use Modules\Feedback\Application\Services\PublicOrganizationServiceContext;
use Modules\Feedback\Contracts\EmployeeFeedbackQr;
use Modules\Feedback\Contracts\FeedbackAnswerStatistics;
use Modules\Feedback\Contracts\FeedbackDataQuality;
use Modules\Feedback\Contracts\FeedbackReportExports;
use Modules\Feedback\Contracts\FeedbackSettings;
use Modules\Feedback\Contracts\FeedbackVisibility;
use Modules\Feedback\Contracts\OrganizationFeedbackQrPermissions;
use Modules\Feedback\Contracts\PublicFeedbackTargets;
use Modules\Feedback\Contracts\PublicOrganizationServices;
use Modules\Feedback\Contracts\ReadModels\FeedbackReportExportRecord;
use Modules\Feedback\Domain\Models\EmployeeFeedbackToken;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedbackAttachment;
use Modules\Feedback\Domain\Models\OrganizationFeedbackQrCode;
use Modules\Feedback\Domain\Models\PublicFeedbackAnswer;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;
use Modules\Feedback\Domain\Models\PublicFeedbackQuestion;
use Modules\Feedback\Domain\Models\PublicFeedbackQuestionOption;
use Modules\Feedback\Domain\Models\PublicFeedbackQuestionSet;
use Modules\Feedback\Domain\Models\PublicFeedbackReferral;
use Modules\Feedback\Domain\Models\PublicFeedbackReportExport;
use Modules\Feedback\Domain\Models\PublicFeedbackRoutingRule;
use Modules\Feedback\Domain\Models\PublicFeedbackStatusHistory;
use Modules\Feedback\Domain\Models\PublicFeedbackSubjectEmployee;
use Modules\Feedback\Infrastructure\Console\PublicFeedbackDataQuality as PublicFeedbackDataQualityCommand;
use Modules\Feedback\Infrastructure\Console\PublicFeedbackDoctor;
use Throwable;

/**
 * Everything the application needs to know about the Feedback module (public
 * and service feedback, suggestions, complaints and tip-offs; ADR 0017): its
 * public contracts, the Core's EmployeeFeedbackLinks adapter, its policies,
 * rate limiters, commands, notification renderer, reference-number collision
 * checks and the names its records keep in the database. Its routes stay in
 * routes/web.php, which names its controllers.
 */
final class FeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmployeeFeedbackLinks::class, EmployeeFeedbackLinkAdapter::class);
        // One instance per request: validation, rendering and storage read the same questionnaire version.
        $this->app->scoped(PublicFeedbackQuestionnaire::class);

        $this->app->bind(FeedbackVisibility::class, PublicFeedbackVisibility::class);
        $this->app->bind(FeedbackSettings::class, PublicFeedbackSettings::class);
        $this->app->bind(FeedbackDataQuality::class, PublicFeedbackDataQuality::class);
        $this->app->bind(FeedbackAnswerStatistics::class, PublicFeedbackAnswerStatistics::class);
        $this->app->bind(FeedbackReportExports::class, FeedbackReportExportCommands::class);
        $this->app->bind(PublicFeedbackTargets::class, PublicFeedbackTargetsQuery::class);
        $this->app->bind(PublicOrganizationServices::class, PublicOrganizationServiceContext::class);
        $this->app->bind(EmployeeFeedbackQr::class, EmployeeFeedbackQrQuery::class);
        $this->app->bind(OrganizationFeedbackQrPermissions::class, OrganizationFeedbackQrAccess::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, notifications, exports).
        Relation::morphMap([
            'App\Models\EmployeeFeedbackToken' => EmployeeFeedbackToken::class,
            'App\Models\EmployeeServiceFeedback' => EmployeeServiceFeedback::class,
            'App\Models\EmployeeServiceFeedbackAttachment' => EmployeeServiceFeedbackAttachment::class,
            'App\Models\OrganizationFeedbackQrCode' => OrganizationFeedbackQrCode::class,
            'App\Models\PublicFeedbackAnswer' => PublicFeedbackAnswer::class,
            'App\Models\PublicFeedbackAssignment' => PublicFeedbackAssignment::class,
            'App\Models\PublicFeedbackQuestion' => PublicFeedbackQuestion::class,
            'App\Models\PublicFeedbackQuestionOption' => PublicFeedbackQuestionOption::class,
            'App\Models\PublicFeedbackQuestionSet' => PublicFeedbackQuestionSet::class,
            'App\Models\PublicFeedbackReferral' => PublicFeedbackReferral::class,
            'App\Models\PublicFeedbackReportExport' => PublicFeedbackReportExport::class,
            'App\Models\PublicFeedbackRoutingRule' => PublicFeedbackRoutingRule::class,
            'App\Models\PublicFeedbackStatusHistory' => PublicFeedbackStatusHistory::class,
            'App\Models\PublicFeedbackSubjectEmployee' => PublicFeedbackSubjectEmployee::class,
        ]);

        Gate::policy(EmployeeServiceFeedback::class, EmployeeServiceFeedbackPolicy::class);
        Gate::policy(PublicFeedbackReportExport::class, PublicFeedbackReportExportPolicy::class);
        // The Situation Room downloads its exports through the published read model.
        Gate::policy(FeedbackReportExportRecord::class, PublicFeedbackReportExportPolicy::class);

        NotificationPresenter::registerRenderer('public_feedback', PublicFeedbackNotification::class);

        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::PublicServiceComplaint, EmployeeServiceFeedback::class, 'reference_number');
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::PublicTipOff, EmployeeServiceFeedback::class, 'reference_number');

        $this->rateLimiters();

        if ($this->app->runningInConsole()) {
            $this->commands([PublicFeedbackDataQualityCommand::class, PublicFeedbackDoctor::class]);
        }
    }

    private function rateLimiters(): void
    {
        /*
         * Public service feedback submission.
         *
         * Two ceilings on purpose. The first stops one client hammering a
         * single employee's QR to bury them in ratings; the second stops one
         * address spraying fabricated feedback across many employees. Without
         * the second, a script could rate every desk in a building from one
         * machine and still stay under a per-token limit.
         */
        // Brief §37–38: per QR code and per address, thresholds from the public feedback policy.
        RateLimiter::for('service-feedback-submit', function (Request $request): array {
            $perSource = 3;
            $perIp = 8;

            try {
                if (Schema::hasTable('system_settings')) {
                    $policy = app(PublicFeedbackSettings::class);
                    $perSource = $policy->rateLimitPerSource();
                    $perIp = $policy->rateLimitPerIp();
                }
            } catch (Throwable) {
                // Defaults stand while the database is unavailable.
            }

            return [
                Limit::perMinutes(10, $perSource)->by('sf-submit:'.$request->route('token').'|'.$request->ip()),
                Limit::perMinutes(10, $perIp)->by('sf-submit-ip:'.$request->ip()),
            ];
        });

        /*
         * The photo on the employee feedback page: about one request per screen
         * of a visit, and the browser reuses it, so this only stops a script
         * pulling photos in bulk. The per-IP ceiling stays generous because a
         * whole service hall can share one mobile carrier address.
         */
        RateLimiter::for('service-feedback-photo', fn (Request $request): array => [
            Limit::perMinute(30)->by('sf-photo:'.$request->route('token').'|'.$request->ip()),
            Limit::perMinute(120)->by('sf-photo-ip:'.$request->ip()),
        ]);
    }
}
