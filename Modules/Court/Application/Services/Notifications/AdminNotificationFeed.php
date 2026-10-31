<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Notifications;

use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * An admin's notifications as one feed, for the header bell of the React
 * screens (EUISIS NotificationBell: {unread_count, items, page, last_page}).
 * The four sources, their 14-day window, the case-access scope and the
 * admin_notification_reads "seen" rows are those of the Blade notifications
 * page (Admin\AdminNotificationController), so both show the same things;
 * that controller goes when the Blade page does.
 */
final class AdminNotificationFeed
{
    public const TYPES = ['message', 'case', 'hearing', 'respondent_view', 'staff_account', 'lawyer_application'];

    public function __construct(private readonly User $user)
    {
    }

    /** @return array{unread_count: int, items: list<array<string, mixed>>, page: int, last_page: int} */
    public function feed(int $page, int $perPage = 10): array
    {
        $items = $this->items();
        $lastPage = max(1, (int) ceil($items->count() / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'unread_count' => $items->count(),
            'items' => $items->forPage($page, $perPage)->values()->all(),
            'page' => $page,
            'last_page' => $lastPage,
        ];
    }

    public function markRead(string $type, int $sourceId): void
    {
        DB::table('court_admin_notification_reads')->updateOrInsert(
            ['user_id' => $this->user->id, 'type' => $type, 'source_id' => $sourceId],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
        $this->forgetCount();
    }

    public function markAllRead(): void
    {
        $now = now();
        $rows = [];
        foreach ($this->sources() as $type => $source) {
            foreach ($source['query']->pluck($source['id']) as $id) {
                $rows[] = ['user_id' => $this->user->id, 'type' => $type, 'source_id' => (int) $id, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows !== []) {
            DB::table('court_admin_notification_reads')->upsert($rows, ['user_id', 'type', 'source_id'], ['seen_at', 'updated_at']);
        }
        $this->forgetCount();
    }

    /** Newest first; every item is unseen, as a seen one leaves the feed. @return Collection<int, array<string, mixed>> */
    private function items(): Collection
    {
        $items = collect();
        foreach ($this->sources() as $type => $source) {
            foreach ($source['query']->limit(50)->get() as $row) {
                $items->push($source['notice']($row));
            }
        }

        return $items->sortByDesc('created_at')->values();
    }

    /**
     * The sources: each an unseen-rows query, the column its ids live in, and how
     * a row becomes a notice. The four case sources need `cases.view`; staff
     * accounts the EUISIS staff check suspended or restored need `users.manage`;
     * lawyers' applications waiting for the registry need `applicants.manage`.
     *
     * @return array<string, array{query: Builder, id: string, notice: callable(object): array<string, mixed>}>
     */
    private function sources(): array
    {
        $uid = $this->user->id;
        $unseen = fn (string $type, string $column) => fn (Builder $q) => $q->from('court_admin_notification_reads as nr')
            ->whereColumn('nr.source_id', $column)->where('nr.type', $type)->where('nr.user_id', $uid);
        $caseUrl = fn (int $caseId): string => route('court.cases.show', $caseId);

        $sources = $this->user->hasPermission('cases.view') ? $this->caseSources($unseen, $caseUrl) : [];
        if ($this->user->hasPermission('users.manage')) {
            $sources['staff_account'] = [
                'query' => DB::table('court_euisis_staff_notices as s')
                    ->join('court_staff as u', 'u.id', '=', 's.user_id')
                    ->select('s.id', 's.event', 's.reason', 's.created_at', 's.user_id', 'u.name')
                    ->where('s.created_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('staff_account', 's.id'))
                    ->orderByDesc('s.created_at'),
                'id' => 's.id',
                'notice' => fn (object $row): array => $this->notice('staff_account', (int) $row->id,
                    __('court::euisis_staff.notice.'.$row->event, ['name' => $row->name]),
                    $row->event === 'suspended' ? (string) __('court::euisis_staff.reasons.'.$row->reason) : (string) __('court::euisis_staff.notice.restored_detail'),
                    route('court.users.show', (int) $row->user_id), $row->created_at),
            ];
        }

        // Lawyers who applied at /lawyer/register, until the registry verifies or refuses them.
        if ($this->user->hasPermission('applicants.manage')) {
            $sources['lawyer_application'] = [
                'query' => DB::table('court_applicants as a')
                    ->select('a.id', 'a.lawyer_license_number', 'a.lawyer_applied_at', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name)) as name"))
                    ->where('a.is_lawyer', true)->whereNotNull('a.lawyer_applied_at')
                    ->whereNull('a.lawyer_verified_at')->whereNull('a.lawyer_refused_at')
                    ->whereNotExists($unseen('lawyer_application', 'a.id'))
                    ->orderByDesc('a.lawyer_applied_at'),
                'id' => 'a.id',
                'notice' => fn (object $row): array => $this->notice('lawyer_application', (int) $row->id,
                    __('court::lawyer.apply.notice', ['name' => $row->name]),
                    __('court::lawyer.apply.notice_detail', ['license' => (string) $row->lawyer_license_number]),
                    route('court.lawyers.index', ['q' => (string) $row->lawyer_license_number]), $row->lawyer_applied_at),
            ];
        }

        return $sources;
    }

    /**
     * @return array<string, array{query: Builder, id: string, notice: callable(object): array<string, mixed>}>
     */
    private function caseSources(callable $unseen, callable $caseUrl): array
    {
        $uid = $this->user->id;

        return [
            'message' => [
                'query' => $this->scope(DB::table('court_case_messages as m')
                    ->join('court_cases as c', 'c.id', '=', 'm.case_id')
                    ->leftJoin('court_applicants as a', 'a.id', '=', 'm.sender_applicant_id')
                    ->select('m.id', 'm.body', 'm.created_at', 'c.case_number', 'c.id as case_id', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name)) as sender_name"))
                    ->whereNotNull('m.sender_applicant_id')
                    ->where('m.created_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('message', 'm.id')))
                    ->orderByDesc('m.created_at'),
                'id' => 'm.id',
                'notice' => fn (object $row): array => $this->notice('message', (int) $row->id,
                    __('court::app.admin_notifications.new_message_from', ['name' => $row->sender_name ?: __('court::app.admin_notifications.case_label').' '.$row->case_number]),
                    __('court::app.admin_notifications.case_label').' '.$row->case_number.': '.mb_strimwidth(strip_tags((string) $row->body), 0, 140, '…'),
                    $caseUrl((int) $row->case_id), $row->created_at),
            ],
            'case' => [
                'query' => $this->scope(DB::table('court_cases as c')
                    ->select('c.id', 'c.case_number', 'c.title', 'c.created_at')
                    ->where('c.status', 'pending')->whereNull('c.assigned_user_id')
                    ->where('c.created_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('case', 'c.id')))
                    ->orderByDesc('c.created_at'),
                'id' => 'c.id',
                'notice' => fn (object $row): array => $this->notice('case', (int) $row->id,
                    __('court::app.admin_notifications.types.new_case').': '.$row->case_number,
                    (string) ($row->title ?? ''), $caseUrl((int) $row->id), $row->created_at),
            ],
            'hearing' => [
                'query' => $this->scope(DB::table('court_case_hearings as h')
                    ->join('court_cases as c', 'c.id', '=', 'h.case_id')
                    ->select('h.id', 'h.hearing_at', 'h.type', 'h.location', 'h.created_at', 'c.id as case_id', 'c.case_number')
                    ->where('c.assigned_user_id', $uid)
                    ->whereBetween('h.hearing_at', [now(), now()->addDays(14)])
                    ->whereNotExists($unseen('hearing', 'h.id')))
                    ->orderBy('h.hearing_at'),
                'id' => 'h.id',
                // The notice's date is the hearing's, shown by the bell in the person's calendar;
                // the text names the kind of hearing and the room.
                'notice' => fn (object $row): array => $this->notice('hearing', (int) $row->id,
                    __('court::app.admin_notifications.types.hearing').': '.$row->case_number,
                    self::hearingDetail($row),
                    $caseUrl((int) $row->case_id), $row->hearing_at),
            ],
            'respondent_view' => [
                'query' => $this->scope(DB::table('court_respondent_case_views as v')
                    ->join('court_cases as c', 'c.id', '=', 'v.case_id')
                    ->join('court_respondents as r', 'r.id', '=', 'v.respondent_id')
                    ->select('v.id', 'v.viewed_at', 'v.case_id', 'c.case_number', DB::raw("TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) as respondent_name"))
                    ->where(fn ($q) => $q->where('c.assigned_user_id', $uid)->orWhereNull('c.assigned_user_id'))
                    ->where('v.viewed_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('respondent_view', 'v.id')))
                    ->orderByDesc('v.viewed_at'),
                'id' => 'v.id',
                'notice' => fn (object $row): array => $this->notice('respondent_view', (int) $row->id,
                    __('court::app.admin_notifications.respondent_viewed_case_title').': '.$row->case_number,
                    __('court::app.admin_notifications.respondent_viewed_case', ['name' => $row->respondent_name ?: __('court::app.admin_notifications.respondent_default')]),
                    $caseUrl((int) $row->case_id), $row->viewed_at),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function notice(string $type, int $id, string $title, string $message, string $url, mixed $at): array
    {
        return [
            'id' => $type.':'.$id,
            'title' => $title,
            'message' => $message,
            'url' => $url,
            'read' => false,
            'created_at' => $at ? Carbon::parse($at)->toIso8601String() : null,
        ];
    }

    /** "Preliminary · Courtroom 2", or the word "Hearing" when neither is recorded. */
    public static function hearingDetail(object $row): string
    {
        $parts = array_filter([trim((string) ($row->type ?? '')), trim((string) ($row->location ?? ''))], fn (string $part): bool => $part !== '');

        return $parts !== [] ? implode(' · ', $parts) : (string) __('court::app.admin_notifications.types.hearing');
    }

    private function forgetCount(): void
    {
        Cache::forget("admin_notif_count_{$this->user->id}");
    }

    /** The case-access scope of Admin\AdminNotificationController::applyCaseAccessScope. */
    private function scope(Builder $query): Builder
    {
        $user = $this->user;
        $uid = $user->id;

        $memberScopeIds = $this->teamLeaderAssignmentIds();
        if ($memberScopeIds !== []) {
            $leaderTeamId = Team::where('team_leader_id', $uid)->value('id');

            return $query->where(function ($q) use ($memberScopeIds, $leaderTeamId) {
                $q->whereIn('c.assigned_user_id', $memberScopeIds);
                if ($leaderTeamId) {
                    $q->orWhere('c.assigned_team_id', $leaderTeamId);
                }
            });
        }

        $isTeamMember = DB::table('court_team_user')->where('user_id', $uid)->exists();
        $isLeader = $user->hasPermission('cases.assign.member');
        $canAssignTeams = $user->hasPermission('cases.assign.team');

        if ($isTeamMember && ! $isLeader && ! $canAssignTeams) {
            return $query->where(fn ($q) => $q->where('c.assigned_member_user_id', $uid)->orWhere('c.assigned_user_id', $uid));
        }

        return $query;
    }

    /** @return list<int> */
    private function teamLeaderAssignmentIds(): array
    {
        $user = $this->user;
        if (! $user->hasPermission('cases.assign.member') || $user->hasPermission('cases.assign.team')) {
            return [];
        }

        $leaderTeam = Team::with(['users' => fn ($q) => $q->where('status', 'active')->orderBy('name')])
            ->where('team_leader_id', $user->id)
            ->first();

        $ids = collect([$user->id]);
        if ($leaderTeam) {
            $ids = $ids->merge($leaderTeam->users->pluck('id'));
        }

        return $ids->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }
}
