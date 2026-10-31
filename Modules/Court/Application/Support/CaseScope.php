<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Which cases a staff member may act on, the rule the case list applies: team
 * leaders reach their team's cases, plain team members only the cases assigned
 * to them, everyone else with cases.view all of them. Every endpoint that reads
 * or changes a case by its id applies it too (#3, 37879e4 and 0a330ea).
 */
final class CaseScope
{
    public static function allows(?User $user, object $case): bool
    {
        if ($user === null) {
            return false;
        }

        $memberScopeIds = self::leaderScopeIds($user);
        if ($memberScopeIds !== []) {
            $leaderTeamId = Team::where('team_leader_id', $user->id)->value('id');

            return in_array((int) ($case->assigned_user_id ?? 0), $memberScopeIds, true)
                || ($leaderTeamId && (int) ($case->assigned_team_id ?? 0) === (int) $leaderTeamId);
        }

        $isTeamMember = DB::table('court_team_user')->where('user_id', $user->id)->exists();
        if ($isTeamMember && ! $user->hasPermission('cases.assign.member') && ! $user->hasPermission('cases.assign.team')) {
            // Many cases are assigned through assigned_user_id only.
            return (int) ($case->assigned_member_user_id ?? $case->assigned_user_id ?? 0) === (int) $user->id;
        }

        return true;
    }

    public static function ensure(object $case): void
    {
        $user = Auth::user();
        abort_unless(self::allows($user instanceof User ? $user : null, $case), 403, __('court::cases.errors.no_case_access'));
    }

    /** A published decision on a closed case freezes the file. */
    public static function isLocked(object $case): bool
    {
        return ($case->status ?? '') === 'closed'
            && DB::table('court_decisions')->where('court_case_id', $case->id)->where('status', 'published')->exists();
    }

    /**
     * A team leader without team-wide assignment: themselves and their team's active members.
     *
     * @return list<int>
     */
    public static function leaderScopeIds(User $user): array
    {
        if (! $user->hasPermission('cases.assign.member') || $user->hasPermission('cases.assign.team')) {
            return [];
        }

        $team = Team::with(['users' => fn ($q) => $q->where('status', 'active')])->where('team_leader_id', $user->id)->first();
        $ids = collect([$user->id])->merge($team?->users->pluck('id') ?? []);

        return $ids->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
