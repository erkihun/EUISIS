<?php

declare(strict_types=1);

namespace Modules\Assessment\Providers;

use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Assessment\Application\Notifications\AssessmentNotification;
use Modules\Assessment\Application\Policies\AssessmentFormPolicy;
use Modules\Assessment\Application\Services\Oversight\AssessmentCoverageService;
use Modules\Assessment\Domain\Models\AssessmentCriterion;
use Modules\Assessment\Domain\Models\AssessmentCycle;
use Modules\Assessment\Domain\Models\AssessmentCycleEligibility;
use Modules\Assessment\Domain\Models\AssessmentCycleOrganization;
use Modules\Assessment\Domain\Models\AssessmentEvaluatorChange;
use Modules\Assessment\Domain\Models\AssessmentEvaluatorScheme;
use Modules\Assessment\Domain\Models\AssessmentExclusionRequest;
use Modules\Assessment\Domain\Models\AssessmentForm;
use Modules\Assessment\Domain\Models\AssessmentFormSection;
use Modules\Assessment\Domain\Models\AssessmentFormVersion;
use Modules\Assessment\Domain\Models\AssessmentInstitutionSubmission;
use Modules\Assessment\Domain\Models\AssessmentRatingOption;
use Modules\Assessment\Domain\Models\AssessmentRecord;
use Modules\Assessment\Domain\Models\AssessmentResponse;
use Modules\Assessment\Domain\Models\AssessmentResponseEvidence;
use Modules\Assessment\Domain\Models\AssessmentResponseItem;
use Modules\Assessment\Domain\Models\AssessmentResponseRevision;
use Modules\Assessment\Domain\Models\AssessmentResultBand;
use Modules\Assessment\Domain\Models\AssessmentResultBandPolicy;
use Modules\Assessment\Domain\Models\AssessmentResultVersion;
use Modules\Assessment\Domain\Models\AssessmentSubmissionEvent;
use Modules\Assessment\Domain\Models\AssessmentTargetRule;
use Modules\Assessment\Domain\Models\AssessmentType;
use Modules\Assessment\Domain\Models\AssessmentUnassessedReason;
use Modules\Assessment\Infrastructure\Console\ImportAssessmentForms;
use Modules\Assessment\Infrastructure\Console\MonitorAssessmentOversight;
use Modules\Assessment\Infrastructure\Console\SendAssessmentEvaluatorReminders;

/**
 * Everything the application needs to know about the Assessment module
 * (ADR 0017): its policy, its commands and the names its records keep in the
 * database. Its routes stay in routes/assessments.php, which names its
 * controllers; the schedule in routes/console.php names its commands.
 */
final class AssessmentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, notifications).
        Relation::morphMap([
            'App\Models\AssessmentCriterion' => AssessmentCriterion::class,
            'App\Models\AssessmentCycle' => AssessmentCycle::class,
            'App\Models\AssessmentCycleEligibility' => AssessmentCycleEligibility::class,
            'App\Models\AssessmentCycleOrganization' => AssessmentCycleOrganization::class,
            'App\Models\AssessmentEvaluatorChange' => AssessmentEvaluatorChange::class,
            'App\Models\AssessmentEvaluatorScheme' => AssessmentEvaluatorScheme::class,
            'App\Models\AssessmentExclusionRequest' => AssessmentExclusionRequest::class,
            'App\Models\AssessmentForm' => AssessmentForm::class,
            'App\Models\AssessmentFormSection' => AssessmentFormSection::class,
            'App\Models\AssessmentFormVersion' => AssessmentFormVersion::class,
            'App\Models\AssessmentInstitutionSubmission' => AssessmentInstitutionSubmission::class,
            'App\Models\AssessmentRatingOption' => AssessmentRatingOption::class,
            'App\Models\AssessmentRecord' => AssessmentRecord::class,
            'App\Models\AssessmentResponse' => AssessmentResponse::class,
            'App\Models\AssessmentResponseEvidence' => AssessmentResponseEvidence::class,
            'App\Models\AssessmentResponseItem' => AssessmentResponseItem::class,
            'App\Models\AssessmentResponseRevision' => AssessmentResponseRevision::class,
            'App\Models\AssessmentResultBand' => AssessmentResultBand::class,
            'App\Models\AssessmentResultBandPolicy' => AssessmentResultBandPolicy::class,
            'App\Models\AssessmentResultVersion' => AssessmentResultVersion::class,
            'App\Models\AssessmentSubmissionEvent' => AssessmentSubmissionEvent::class,
            'App\Models\AssessmentTargetRule' => AssessmentTargetRule::class,
            'App\Models\AssessmentType' => AssessmentType::class,
            'App\Models\AssessmentUnassessedReason' => AssessmentUnassessedReason::class,
        ]);

        // Oversight aggregates are cached per cycle; a changed record or response invalidates them.
        AssessmentRecord::saved(fn (AssessmentRecord $record) => AssessmentCoverageService::bump($record->assessment_cycle_id));
        AssessmentResponse::saved(fn (AssessmentResponse $response) => AssessmentCoverageService::bump(
            AssessmentRecord::query()->whereKey($response->assessment_record_id)->value('assessment_cycle_id')));

        // Policy discovery only looks in App\Policies.
        Gate::policy(AssessmentForm::class, AssessmentFormPolicy::class);

        NotificationPresenter::registerRenderer('assessment', AssessmentNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportAssessmentForms::class,
                MonitorAssessmentOversight::class,
                SendAssessmentEvaluatorReminders::class,
            ]);
        }
    }
}
