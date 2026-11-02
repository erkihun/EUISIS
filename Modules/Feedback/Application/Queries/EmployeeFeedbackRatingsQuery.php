<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Queries;

use App\Models\Employee;
use App\Models\User;
use Modules\Feedback\Application\Services\PublicFeedbackVisibility;
use Modules\Feedback\Application\Services\ServiceFeedbackQueryService;
use Modules\Feedback\Contracts\Data\EmployeeFeedbackRatingSummary;
use Modules\Feedback\Contracts\Data\PublicFeedbackTargetType;
use Modules\Feedback\Contracts\EmployeeFeedbackRatings;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;

final class EmployeeFeedbackRatingsQuery implements EmployeeFeedbackRatings
{
    public function __construct(
        private readonly ServiceFeedbackQueryService $queries,
        private readonly PublicFeedbackVisibility $visibility,
    ) {}

    public function summaryFor(Employee $employee, User $viewer): ?EmployeeFeedbackRatingSummary
    {
        // Reading your own clients' verdicts is a conflict of interest the policy refuses too.
        if ($viewer->employee_id !== null && $viewer->employee_id === $employee->getKey()) {
            return null;
        }

        if (! $viewer->can('viewAny', EmployeeServiceFeedback::class)
            || ! $this->visibility->canSeeTarget($viewer, PublicFeedbackTargetType::Employee)) {
            return null;
        }

        // One grouped query; scopedQuery() is the feedback screens' own type, target and organization scope.
        $counts = $this->queries->scopedQuery($viewer)
            ->visible()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('rating', [EmployeeFeedbackRatingSummary::MIN_STARS, EmployeeFeedbackRatingSummary::MAX_STARS])
            ->toBase()
            ->selectRaw('rating, COUNT(*) AS aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating')
            ->mapWithKeys(fn (mixed $count, mixed $stars): array => [(int) $stars => (int) $count])
            ->all();

        return new EmployeeFeedbackRatingSummary($counts);
    }
}
