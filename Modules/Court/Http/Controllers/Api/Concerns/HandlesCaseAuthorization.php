<?php

namespace Modules\Court\Http\Controllers\Api\Concerns;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentCaseView;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Database\Eloquent\Builder;

trait HandlesCaseAuthorization
{
    protected function applyActorScope(Builder $query, User|Applicant|Respondent|null $actor): void
    {
        abort_if(is_null($actor), 401, 'Unauthenticated.');

        if ($actor instanceof Applicant) {
            $query->where('applicant_id', $actor->id);
            return;
        }

        if ($actor instanceof Respondent) {
            $caseIds = RespondentCaseView::where('respondent_id', $actor->id)->select('case_id');
            $query->whereIn('id', $caseIds);
            return;
        }

        if ($actor instanceof User) {
            abort_unless($actor->canDo('cases.view'), 403, 'Not authorized to view cases.');
            return;
        }

        abort(403, 'Not authorized.');
    }

    protected function assertCanViewCase(User|Applicant|Respondent|null $actor, CourtCase $case): void
    {
        abort_if(is_null($actor), 401, 'Unauthenticated.');

        if ($actor instanceof Applicant && (int) $case->applicant_id === (int) $actor->id) {
            return;
        }

        if ($actor instanceof Respondent) {
            $canView = RespondentCaseView::where('respondent_id', $actor->id)
                ->where('case_id', $case->id)
                ->exists();

            if ($canView) {
                return;
            }
        }

        if ($actor instanceof User && $actor->canDo('cases.view')) {
            return;
        }

        abort(403, 'Not authorized to view this case.');
    }
}
