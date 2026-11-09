<?php

declare(strict_types=1);

namespace Modules\Grievance\Providers;

use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Support\NotificationPresenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Modules\Grievance\Application\Notifications\GrievanceNotification;
use Modules\Grievance\Application\Queries\GrievanceCaseLinksService;
use Modules\Grievance\Application\Queries\GrievanceCourtRecordsQuery;
use Modules\Grievance\Contracts\GrievanceCaseLinks;
use Modules\Grievance\Contracts\GrievanceCourtRecords;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceAmendment;
use Modules\Grievance\Domain\Models\GrievanceAppeal;
use Modules\Grievance\Domain\Models\GrievanceApprovalRule;
use Modules\Grievance\Domain\Models\GrievanceAssignment;
use Modules\Grievance\Domain\Models\GrievanceCaseEvent;
use Modules\Grievance\Domain\Models\GrievanceCaseOfficer;
use Modules\Grievance\Domain\Models\GrievanceCaseRecusal;
use Modules\Grievance\Domain\Models\GrievanceCaseStage;
use Modules\Grievance\Domain\Models\GrievanceCategory;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceCommitteeMember;
use Modules\Grievance\Domain\Models\GrievanceCorrectiveAction;
use Modules\Grievance\Domain\Models\GrievanceDecision;
use Modules\Grievance\Domain\Models\GrievanceDecisionApproval;
use Modules\Grievance\Domain\Models\GrievanceDecisionLetter;
use Modules\Grievance\Domain\Models\GrievanceDecisionVote;
use Modules\Grievance\Domain\Models\GrievanceDelegation;
use Modules\Grievance\Domain\Models\GrievanceDisciplinaryReferral;
use Modules\Grievance\Domain\Models\GrievanceEscalation;
use Modules\Grievance\Domain\Models\GrievanceEvidence;
use Modules\Grievance\Domain\Models\GrievanceEvidenceCustody;
use Modules\Grievance\Domain\Models\GrievanceExternalAuthority;
use Modules\Grievance\Domain\Models\GrievanceHearing;
use Modules\Grievance\Domain\Models\GrievanceHearingParticipant;
use Modules\Grievance\Domain\Models\GrievanceInformationRequest;
use Modules\Grievance\Domain\Models\GrievanceInformationResponse;
use Modules\Grievance\Domain\Models\GrievanceInitialIssue;
use Modules\Grievance\Domain\Models\GrievanceLetter;
use Modules\Grievance\Domain\Models\GrievanceLetterAttachment;
use Modules\Grievance\Domain\Models\GrievanceLetterDispatch;
use Modules\Grievance\Domain\Models\GrievanceLetterRecipient;
use Modules\Grievance\Domain\Models\GrievanceLetterTemplate;
use Modules\Grievance\Domain\Models\GrievanceMinutes;
use Modules\Grievance\Domain\Models\GrievanceNote;
use Modules\Grievance\Domain\Models\GrievanceReasonCode;
use Modules\Grievance\Domain\Models\GrievanceResponse;
use Modules\Grievance\Domain\Models\GrievanceRoute;
use Modules\Grievance\Domain\Models\GrievanceSlaPause;
use Modules\Grievance\Domain\Models\GrievanceSlaProfile;
use Modules\Grievance\Domain\Models\GrievanceStageMember;
use Modules\Grievance\Domain\Models\GrievanceStatusHistory;
use Modules\Grievance\Domain\Models\GrievanceTask;
use Modules\Grievance\Infrastructure\Console\ProcessGrievanceSla;

/**
 * Everything the application needs to know about the Grievance module
 * (ADR 0017): its public contracts, its notification renderer, its SLA
 * command, the code-rule collision checks of its numbers, and the names its
 * records keep in the database. Its routes stay in routes/grievances.php,
 * which names its controllers.
 */
final class GrievanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GrievanceCaseLinks::class, GrievanceCaseLinksService::class);
        $this->app->bind(GrievanceCourtRecords::class, GrievanceCourtRecordsQuery::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (audit logs, deadlines, notifications).
        Relation::morphMap([
            'App\Models\Grievance' => Grievance::class,
            'App\Models\GrievanceAmendment' => GrievanceAmendment::class,
            'App\Models\GrievanceAppeal' => GrievanceAppeal::class,
            'App\Models\GrievanceApprovalRule' => GrievanceApprovalRule::class,
            'App\Models\GrievanceAssignment' => GrievanceAssignment::class,
            'App\Models\GrievanceCaseEvent' => GrievanceCaseEvent::class,
            'App\Models\GrievanceCaseOfficer' => GrievanceCaseOfficer::class,
            'App\Models\GrievanceCaseRecusal' => GrievanceCaseRecusal::class,
            'App\Models\GrievanceCaseStage' => GrievanceCaseStage::class,
            'App\Models\GrievanceCategory' => GrievanceCategory::class,
            'App\Models\GrievanceCommittee' => GrievanceCommittee::class,
            'App\Models\GrievanceCommitteeMember' => GrievanceCommitteeMember::class,
            'App\Models\GrievanceCorrectiveAction' => GrievanceCorrectiveAction::class,
            'App\Models\GrievanceDecision' => GrievanceDecision::class,
            'App\Models\GrievanceDecisionApproval' => GrievanceDecisionApproval::class,
            'App\Models\GrievanceDecisionLetter' => GrievanceDecisionLetter::class,
            'App\Models\GrievanceDecisionVote' => GrievanceDecisionVote::class,
            'App\Models\GrievanceDelegation' => GrievanceDelegation::class,
            'App\Models\GrievanceDisciplinaryReferral' => GrievanceDisciplinaryReferral::class,
            'App\Models\GrievanceEscalation' => GrievanceEscalation::class,
            'App\Models\GrievanceEvidence' => GrievanceEvidence::class,
            'App\Models\GrievanceEvidenceCustody' => GrievanceEvidenceCustody::class,
            'App\Models\GrievanceExternalAuthority' => GrievanceExternalAuthority::class,
            'App\Models\GrievanceHearing' => GrievanceHearing::class,
            'App\Models\GrievanceHearingParticipant' => GrievanceHearingParticipant::class,
            'App\Models\GrievanceInformationRequest' => GrievanceInformationRequest::class,
            'App\Models\GrievanceInformationResponse' => GrievanceInformationResponse::class,
            'App\Models\GrievanceInitialIssue' => GrievanceInitialIssue::class,
            'App\Models\GrievanceLetter' => GrievanceLetter::class,
            'App\Models\GrievanceLetterAttachment' => GrievanceLetterAttachment::class,
            'App\Models\GrievanceLetterDispatch' => GrievanceLetterDispatch::class,
            'App\Models\GrievanceLetterRecipient' => GrievanceLetterRecipient::class,
            'App\Models\GrievanceLetterTemplate' => GrievanceLetterTemplate::class,
            'App\Models\GrievanceMinutes' => GrievanceMinutes::class,
            'App\Models\GrievanceNote' => GrievanceNote::class,
            'App\Models\GrievanceReasonCode' => GrievanceReasonCode::class,
            'App\Models\GrievanceResponse' => GrievanceResponse::class,
            'App\Models\GrievanceRoute' => GrievanceRoute::class,
            'App\Models\GrievanceSlaPause' => GrievanceSlaPause::class,
            'App\Models\GrievanceSlaProfile' => GrievanceSlaProfile::class,
            'App\Models\GrievanceStageMember' => GrievanceStageMember::class,
            'App\Models\GrievanceStatusHistory' => GrievanceStatusHistory::class,
            'App\Models\GrievanceTask' => GrievanceTask::class,
        ]);

        // Grievance numbers carry unique indexes that include archived rows, so archived rows count as taken.
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::GrievanceCase, Grievance::class, 'reference_number', withTrashed: true);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::GrievanceDecision, GrievanceDecision::class, 'decision_no', withTrashed: true);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::GrievanceLetter, GrievanceLetter::class, 'reference_number', withTrashed: true);

        NotificationPresenter::registerRenderer('grievance', GrievanceNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessGrievanceSla::class]);
        }
    }
}
