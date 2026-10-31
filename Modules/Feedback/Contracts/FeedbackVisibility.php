<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Which public submissions a user may count or read (ADR 0017): the target
 * types their grants cover. The Situation Room and the Intelligence reports
 * apply the same rule the Feedback screens do, so an aggregate never counts
 * a submission its viewer could not open.
 */
interface FeedbackVisibility
{
    /**
     * The target types (Data\PublicFeedbackTargetType values) the user may see.
     *
     * @return list<string>
     */
    public function visibleTargetTypes(User $user): array;

    /**
     * Narrows a query over employee_service_feedback (an Eloquent query of
     * ReadModels\ServiceFeedbackRecord, or of the owner's model) to the
     * target types the user may see.
     */
    public function applyTargetScope(Builder $query, User $user): Builder;
}
