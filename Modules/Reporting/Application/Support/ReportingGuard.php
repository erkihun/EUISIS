<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/**
 * Who may see and do what, in one place. Every query, count, file, export,
 * background job and notification goes through these rules, so they cannot
 * drift apart.
 *
 * Two sides exist for each obligation: the reporting side (the institution
 * that owes the report, or the assigned person) and the receiving side (the
 * institution that reviews it). A permission alone is never enough: the actor
 * must also reach the organization on that side.
 */
final class ReportingGuard
{
    public static function require(ActorContext $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ReportingAccessDenied($permission);
        }
    }

    public static function onReportingSide(ActorContext $actor, Obligation $obligation): bool
    {
        if (in_array($actor->userId, [(int) $obligation->person_user_id, (int) $obligation->preparer_user_id, (int) $obligation->accountable_user_id], true)) {
            return true;
        }

        // A report assigned to a person is that person's (and the named officers'), not the whole institution's.
        return $obligation->person_user_id === null && $actor->reaches($obligation->organization_id);
    }

    public static function onReceivingSide(ActorContext $actor, Obligation $obligation): bool
    {
        return $actor->unrestricted
            || $actor->reaches($obligation->receiving_organization_id)
            || (int) $obligation->current_reviewer_user_id === $actor->userId;
    }

    public static function canView(ActorContext $actor, Obligation $obligation): bool
    {
        return $actor->can(ReportingRoles::VIEW) && (self::onReportingSide($actor, $obligation) || self::onReceivingSide($actor, $obligation));
    }

    /** The narrative and its files, as opposed to the register row. */
    public static function canReadContent(ActorContext $actor, Obligation $obligation, string $confidentiality): bool
    {
        if (! self::canView($actor, $obligation)) {
            return false;
        }
        if ($confidentiality !== 'confidential' || self::onReportingSide($actor, $obligation)) {
            return true;
        }

        return $actor->can(ReportingRoles::VIEW_CONFIDENTIAL) || $actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE);
    }

    public static function requireView(ActorContext $actor, Obligation $obligation): void
    {
        if (! self::canView($actor, $obligation)) {
            throw new ReportingAccessDenied(ReportingRoles::VIEW);
        }
    }

    public static function requireReportingSide(ActorContext $actor, Obligation $obligation, string $permission): void
    {
        if (! $actor->can($permission) || ! self::onReportingSide($actor, $obligation)) {
            throw new ReportingAccessDenied($permission);
        }
    }

    public static function requireReceivingSide(ActorContext $actor, Obligation $obligation, string $permission): void
    {
        if (! $actor->can($permission) || ! self::onReceivingSide($actor, $obligation)) {
            throw new ReportingAccessDenied($permission);
        }
    }

    /**
     * Restricts an obligations query to what the actor may see: the same
     * predicate as canView(), in SQL. Registers, counts, matrices and exports
     * all start from it.
     *
     * @param  Builder<Obligation>  $query
     * @return Builder<Obligation>
     */
    public static function visible(Builder $query, ActorContext $actor): Builder
    {
        if (! $actor->can(ReportingRoles::VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->unrestricted) {
            return $query;
        }
        $ids = $actor->organizationIds;

        return $query->where(function (Builder $q) use ($actor, $ids): void {
            $q->where(fn (Builder $own) => $own->whereNull('person_user_id')->whereIn('organization_id', $ids))
                ->orWhereIn('receiving_organization_id', $ids)
                ->orWhere('person_user_id', $actor->userId)
                ->orWhere('preparer_user_id', $actor->userId)
                ->orWhere('accountable_user_id', $actor->userId)
                ->orWhere('current_reviewer_user_id', $actor->userId);
        });
    }
}
