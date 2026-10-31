<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;

use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminNotificationController extends Controller
{
    public function count(): JsonResponse
    {
        $uid = auth()->id();
        if (!$uid) return response()->json(['count' => 0]);
        if (!$this->canSeeCaseNotifications()) return response()->json(['count' => 0]);

        // Cache per user for 2 minutes — invalidated on markOne / markAll
        $count = Cache::remember("admin_notif_count_{$uid}", 120, function () use ($uid) {
            $c  = $this->applyCaseAccessScope(
                DB::table('court_case_messages as m')
                    ->join('court_cases as c', 'c.id', '=', 'm.case_id')
                    ->whereNotNull('m.sender_applicant_id')
                    ->where('m.created_at', '>=', now()->subDays(14))
                    ->whereNotExists(fn($q) => $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'm.id')->where('nr.type', 'message')->where('nr.user_id', $uid))
            )->count();

            $c += $this->applyCaseAccessScope(
                DB::table('court_cases as c')
                    ->where('c.status', 'pending')->whereNull('c.assigned_user_id')
                    ->where('c.created_at', '>=', now()->subDays(14))
                    ->whereNotExists(fn($q) => $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'c.id')->where('nr.type', 'case')->where('nr.user_id', $uid))
            )->count();

            $c += $this->applyCaseAccessScope(
                DB::table('court_case_hearings as h')
                    ->join('court_cases as c', 'c.id', '=', 'h.case_id')
                    ->where('c.assigned_user_id', $uid)
                    ->whereBetween('h.hearing_at', [now(), now()->addDays(14)])
                    ->whereNotExists(fn($q) => $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'h.id')->where('nr.type', 'hearing')->where('nr.user_id', $uid))
            )->count();

            $c += $this->applyCaseAccessScope(
                DB::table('court_respondent_case_views as v')
                    ->join('court_cases as c', 'c.id', '=', 'v.case_id')
                    ->where(function ($q) use ($uid) {
                        $q->where('c.assigned_user_id', $uid)->orWhereNull('c.assigned_user_id');
                    })
                    ->where('v.viewed_at', '>=', now()->subDays(14))
                    ->whereNotExists(fn($q) => $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'v.id')->where('nr.type', 'respondent_view')->where('nr.user_id', $uid))
            )->count();

            return $c;
        });

        return response()->json(['count' => $count]);
    }

    public function index(Request $request)
    {
        $uid = auth()->id();
        abort_if(!$uid, 403);
        abort_unless($this->canSeeCaseNotifications(), 403);

        // Applicant -> Admin messages (last 14 days)
        $msgs = $this->applyCaseAccessScope(
            DB::table('court_case_messages as m')
                ->join('court_cases as c', 'c.id', '=', 'm.case_id')
                ->select('m.id', 'm.body', 'm.created_at', 'c.case_number', 'c.id as case_id')
                ->whereNotNull('m.sender_applicant_id')
                ->where('m.created_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'm.id')
                        ->where('nr.type', 'message')
                        ->where('nr.user_id', $uid);
                })
        )
            ->orderByDesc('m.created_at')
            ->paginate(10, ['*'], 'msgs_page');

        // New & unassigned cases (last 14 days)
        $cases = $this->applyCaseAccessScope(
            DB::table('court_cases as c')
                ->select('c.id', 'c.case_number', 'c.title', 'c.created_at')
                ->where('c.status', 'pending')
                ->whereNull('c.assigned_user_id')
                ->where('c.created_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'c.id')
                        ->where('nr.type', 'case')
                        ->where('nr.user_id', $uid);
                })
        )
            ->orderByDesc('c.created_at')
            ->paginate(10, ['*'], 'cases_page');

        // Upcoming hearings for my assigned cases (next 14 days)
        $hearings = $this->applyCaseAccessScope(
            DB::table('court_case_hearings as h')
                ->join('court_cases as c', 'c.id', '=', 'h.case_id')
                ->select('h.id', 'h.hearing_at', 'c.id as case_id', 'c.case_number')
                ->where('c.assigned_user_id', $uid)
                ->whereBetween('h.hearing_at', [now(), now()->addDays(14)])
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'h.id')
                        ->where('nr.type', 'hearing')
                        ->where('nr.user_id', $uid);
                })
        )
            ->orderBy('h.hearing_at')
            ->paginate(10, ['*'], 'hearings_page');

        $respondentViews = $this->applyCaseAccessScope(
            DB::table('court_respondent_case_views as v')
                ->join('court_cases as c', 'c.id', '=', 'v.case_id')
                ->join('court_respondents as r', 'r.id', '=', 'v.respondent_id')
                ->select(
                    'v.id',
                    'v.viewed_at',
                    'v.case_id',
                    'c.case_number',
                    'c.title',
                    DB::raw("TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) as respondent_name")
                )
                ->where(function ($q) use ($uid) {
                    $q->where('c.assigned_user_id', $uid)
                        ->orWhereNull('c.assigned_user_id');
                })
                ->where('v.viewed_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'v.id')
                        ->where('nr.type', 'respondent_view')
                        ->where('nr.user_id', $uid);
                })
        )
            ->orderByDesc('v.viewed_at')
            ->paginate(10, ['*'], 'respondent_views_page');

        // The page (React, Court/Notifications/Index): four lists, each paged on its own.
        $legacyApplicantUpdate = 'Applicant updated the case details. Please review the submission.';
        $at = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        return Inertia::render('Court/Notifications/Index', [
            'messages' => $msgs->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'case_number' => (string) $row->case_number,
                'body' => trim((string) $row->body) === $legacyApplicantUpdate ? __('court::cases.notifications.applicant_updated_submission') : (string) $row->body,
                'at' => $at($row->created_at),
            ]),
            'cases' => $cases->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'title' => $row->title,
                'at' => $at($row->created_at),
            ]),
            'hearings' => $hearings->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'case_number' => (string) $row->case_number,
                'type' => $row->type ?? null,
                'location' => $row->location ?? null,
                'at' => $at($row->hearing_at),
            ]),
            'respondentViews' => $respondentViews->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'case_number' => (string) $row->case_number,
                'respondent_name' => $row->respondent_name ?: null,
                'at' => $at($row->viewed_at),
            ]),
        ]);
    }

    public function markOne(Request $request)
    {
        $uid = auth()->id();
        abort_if(!$uid, 403);

        $data = $request->validate([
            'type'     => 'required|in:message,case,hearing,respondent_view',
            'sourceId' => 'required|integer',
        ]);

        DB::table('court_admin_notification_reads')->updateOrInsert(
            ['user_id' => $uid, 'type' => $data['type'], 'source_id' => $data['sourceId']],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );

        Cache::forget("admin_notif_count_{$uid}");

        return back()->with('success', __('court::app.admin_notifications.marked_as_seen'));
    }

    public function markAll()
    {
        $uid = auth()->id();
        abort_if(!$uid, 403);
        abort_unless($this->canSeeCaseNotifications(), 403);
        $now = now();

        // collect unseen IDs per type
        $msgIds = $this->applyCaseAccessScope(
            DB::table('court_case_messages as m')
                ->join('court_cases as c', 'c.id', '=', 'm.case_id')
                ->whereNotNull('m.sender_applicant_id')
                ->where('m.created_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'm.id')
                        ->where('nr.type', 'message')
                        ->where('nr.user_id', $uid);
                })
        )->pluck('m.id')->all();

        $caseIds = $this->applyCaseAccessScope(
            DB::table('court_cases as c')
                ->where('c.status', 'pending')->whereNull('c.assigned_user_id')
                ->where('c.created_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'c.id')
                        ->where('nr.type', 'case')
                        ->where('nr.user_id', $uid);
                })
        )->pluck('c.id')->all();

        $hearingIds = $this->applyCaseAccessScope(
            DB::table('court_case_hearings as h')
                ->join('court_cases as c', 'c.id', '=', 'h.case_id')
                ->where('c.assigned_user_id', $uid)
                ->whereBetween('h.hearing_at', [now(), now()->addDays(14)])
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'h.id')
                        ->where('nr.type', 'hearing')
                        ->where('nr.user_id', $uid);
                })
        )->pluck('h.id')->all();

        $respondentViewIds = $this->applyCaseAccessScope(
            DB::table('court_respondent_case_views as v')
                ->join('court_cases as c', 'c.id', '=', 'v.case_id')
                ->where(function ($q) use ($uid) {
                    $q->where('c.assigned_user_id', $uid)
                        ->orWhereNull('c.assigned_user_id');
                })
                ->where('v.viewed_at', '>=', now()->subDays(14))
                ->whereNotExists(function ($q) use ($uid) {
                    $q->from('court_admin_notification_reads as nr')
                        ->whereColumn('nr.source_id', 'v.id')
                        ->where('nr.type', 'respondent_view')
                        ->where('nr.user_id', $uid);
                })
        )->pluck('v.id')->all();

        $rows = [];
        foreach ($msgIds as $id)     $rows[] = ['user_id' => $uid, 'type' => 'message', 'source_id' => $id, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        foreach ($caseIds as $id)    $rows[] = ['user_id' => $uid, 'type' => 'case', 'source_id' => $id, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        foreach ($hearingIds as $id) $rows[] = ['user_id' => $uid, 'type' => 'hearing', 'source_id' => $id, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        foreach ($respondentViewIds as $id) $rows[] = ['user_id' => $uid, 'type' => 'respondent_view', 'source_id' => $id, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];

        if (!empty($rows)) {
            // upsert avoids duplicate-key issues
            DB::table('court_admin_notification_reads')->upsert(
                $rows,
                ['user_id', 'type', 'source_id'],
                ['seen_at', 'updated_at']
            );
        }

        Cache::forget("admin_notif_count_{$uid}");

        return back()->with('success', __('court::app.admin_notifications.all_marked_as_seen'));
    }

    private function canSeeCaseNotifications(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && method_exists($user, 'hasPermission')
            && $user->hasPermission('cases.view');
    }

    private function applyCaseAccessScope(\Illuminate\Database\Query\Builder $query, string $caseAlias = 'c'): \Illuminate\Database\Query\Builder
    {
        $user = auth()->user();
        $uid = auth()->id();
        if (!$user || !$uid) {
            return $query->whereRaw('1 = 0');
        }

        $memberScopeIds = $this->teamLeaderAssignmentIds($user);
        if (!empty($memberScopeIds)) {
            $leaderTeamId = Team::where('team_leader_id', $uid)->value('id');

            return $query->where(function ($q) use ($caseAlias, $memberScopeIds, $leaderTeamId) {
                $q->whereIn("{$caseAlias}.assigned_user_id", $memberScopeIds);
                if ($leaderTeamId) {
                    $q->orWhere("{$caseAlias}.assigned_team_id", $leaderTeamId);
                }
            });
        }

        $isTeamMember = DB::table('court_team_user')->where('user_id', $uid)->exists();
        $isLeader = $user->hasPermission('cases.assign.member');
        $canAssignTeams = $user->hasPermission('cases.assign.team');

        if ($isTeamMember && !$isLeader && !$canAssignTeams) {
            return $query->where(function ($q) use ($caseAlias, $uid) {
                $q->where("{$caseAlias}.assigned_member_user_id", $uid)
                    ->orWhere("{$caseAlias}.assigned_user_id", $uid);
            });
        }

        return $query;
    }

    private function teamLeaderAssignmentIds(User $user): array
    {
        $isLeader = $user->hasPermission('cases.assign.member');
        $hasAdminAssign = $user->hasPermission('cases.assign.team');

        if (!$isLeader || $hasAdminAssign) {
            return [];
        }

        $leaderTeam = Team::with(['users' => fn ($q) => $q->where('status', 'active')->orderBy('name')])
            ->where('team_leader_id', $user->id)
            ->first();

        $ids = collect([$user->id]);

        if ($leaderTeam) {
            $ids = $ids->merge($leaderTeam->users->pluck('id'));
        }

        return $ids->filter()->unique()->values()->all();
    }
}
