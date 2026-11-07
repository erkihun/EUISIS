<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The case-handling part of a type's dashboard (ADR 0032). A type module
 * (Tip-offs, Public complaints) passes a query of its own submissions; this
 * module narrows it to what the user may see and counts where the cases
 * stand, what waits for a handler, what is assigned to the user, the last
 * six months, the institutions with most and the latest arrivals.
 */
interface PublicCaseDashboard
{
    /** Target grants, organization scope, and never a submission about the user themself. */
    public function scope(Builder $query, User $user): Builder;

    /**
     * @param  Closure(): Builder  $query  a fresh, already scoped query of the type's submissions
     * @param  list<string>  $statusOrder  the type's statuses in display order
     * @return array{figures: array<string, int>, byStatus: array<string, int>, months: array<string, int>, byOrganization: list<array{id: string, name: string, total: int}>, recent: list<array<string, mixed>>}
     */
    public function build(Closure $query, User $user, array $statusOrder): array;
}
