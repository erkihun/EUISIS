<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Notifications;

use Modules\Court\Application\Services\CaseParties;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An applicant's or a respondent's notifications as one feed, for the header
 * bell of the portals (EUISIS NotificationBell: {unread_count, items, page,
 * last_page}). The sources, their windows and the "seen" rows are those the
 * portal pages already use, so the bell and the pages agree:
 *  - applicant: Applicant\ApplicantNotificationController (notification_reads),
 *  - respondent: Respondent\DashboardController and NotificationController
 *    (respondent_notification_reads, over the cases the respondent opened).
 */
final class PortalNotificationFeed
{
    public const TYPES = ['hearing', 'message', 'status', 'respondent_view'];

    private function __construct(
        private readonly string $portal,
        private readonly int $ownerId,
    ) {}

    public static function forApplicant(int $applicantId): self
    {
        return new self('applicant', $applicantId);
    }

    public static function forRespondent(int $respondentId): self
    {
        return new self('respondent', $respondentId);
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
        DB::table($this->readsTable())->updateOrInsert(
            [$this->ownerColumn() => $this->ownerId, 'type' => $type, 'source_id' => $sourceId],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function markAllRead(): void
    {
        $now = now();
        $rows = [];
        foreach ($this->sources() as $type => $source) {
            foreach ($source['query']->pluck($source['id']) as $id) {
                $rows[] = [$this->ownerColumn() => $this->ownerId, 'type' => $type, 'source_id' => (int) $id, 'seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows !== []) {
            DB::table($this->readsTable())->upsert($rows, [$this->ownerColumn(), 'type', 'source_id'], ['seen_at', 'updated_at']);
        }
    }

    /** Newest first; every item is unseen, as a seen one leaves the feed. @return Collection<int, array<string, mixed>> */
    private function items(): Collection
    {
        $items = collect();
        foreach ($this->sources() as $source) {
            foreach ($source['query']->limit(50)->get() as $row) {
                // The case it concerns, so a notice shown elsewhere (EUISIS My Portal) can open it.
                $items->push([...$source['notice']($row), 'case_id' => isset($row->case_id) ? (int) $row->case_id : null]);
            }
        }

        return $items->sortByDesc('created_at')->values();
    }

    /**
     * Each source: an unseen-rows query, the column its ids live in, and how a row becomes a notice.
     *
     * @return array<string, array{query: Builder, id: string, notice: callable(object): array<string, mixed>}>
     */
    private function sources(): array
    {
        $applicant = $this->portal === 'applicant';
        $owner = $this->ownerId;
        $unseen = fn (string $type, string $column) => fn (Builder $q) => $q->from($this->readsTable().' as nr')
            ->whereColumn('nr.source_id', $column)->where('nr.type', $type)->where('nr.'.$this->ownerColumn(), $owner);
        // The cases an applicant is a party to (CaseParties); a respondent's are those they opened (respondent_case_views).
        $cases = fn (Builder $q, string $caseColumn) => $applicant
            ? $q->whereIn('c.id', CaseParties::caseIdsFor((int) $owner))
            : $q->whereIn($caseColumn, DB::table('court_respondent_case_views')->where('respondent_id', $owner)->select('case_id'));
        $caseUrl = fn (object $row): ?string => $applicant
            ? route('court.applicant.cases.show', (int) $row->case_id)
            : ($row->case_number ? route('court.respondent.cases.show', $row->case_number) : null);
        $caseLabel = fn (object $row): string => (string) ($row->case_number ?: __('court::app.admin_notifications.unknown_case'));
        $typeTitle = fn (string $type, object $row): string => __('court::app.admin_notifications.types.'.$type).': '.$caseLabel($row);

        $sources = [
            'hearing' => [
                'query' => $cases(DB::table('court_case_hearings as h')
                    ->join('court_cases as c', 'c.id', '=', 'h.case_id')
                    ->select('h.id', 'h.hearing_at', 'h.type', 'h.location', 'c.id as case_id', 'c.case_number')
                    ->whereBetween('h.hearing_at', [now()->subDay(), now()->addDays(60)])
                    ->whereNotExists($unseen('hearing', 'h.id')), 'h.case_id')
                    ->orderBy('h.hearing_at'),
                'id' => 'h.id',
                // The notice's date is the hearing's, shown by the bell in the person's calendar.
                'notice' => fn (object $row): array => $this->notice('hearing', (int) $row->id,
                    $typeTitle('hearing', $row),
                    AdminNotificationFeed::hearingDetail($row),
                    $caseUrl($row), $row->hearing_at),
            ],
            'message' => [
                'query' => $cases(DB::table('court_case_messages as m')
                    ->join('court_cases as c', 'c.id', '=', 'm.case_id')
                    ->select('m.id', 'm.body', 'm.created_at', 'c.id as case_id', 'c.case_number')
                    ->whereNotNull('m.sender_user_id')
                    ->where('m.created_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('message', 'm.id')), 'm.case_id')
                    ->orderByDesc('m.created_at'),
                'id' => 'm.id',
                'notice' => function (object $row) use ($typeTitle, $caseUrl): array {
                    $body = trim((string) $row->body);
                    // A message that is a link (a letter preview) opens that link, as the notifications page does.
                    // Only an http(s) address becomes a link (FILTER_VALIDATE_URL alone lets javascript: through).
                    $isUrl = filter_var($body, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $body) === 1;

                    return $this->notice('message', (int) $row->id,
                        $typeTitle('message', $row),
                        $isUrl ? __('court::app.admin_notifications.view_letter_preview') : Str::limit(strip_tags($body), 140),
                        $isUrl ? $body : $caseUrl($row), $row->created_at);
                },
            ],
            'status' => [
                'query' => $cases(DB::table('court_case_status_logs as l')
                    ->join('court_cases as c', 'c.id', '=', 'l.case_id')
                    ->select('l.id', 'l.from_status', 'l.to_status', 'l.created_at', 'c.id as case_id', 'c.case_number')
                    ->where('l.created_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('status', 'l.id')), 'l.case_id')
                    ->orderByDesc('l.created_at'),
                'id' => 'l.id',
                'notice' => fn (object $row): array => $this->notice('status', (int) $row->id,
                    $typeTitle('status', $row),
                    __('court::app.admin_notifications.status_changed', ['from' => $this->statusLabel($row->from_status), 'to' => $this->statusLabel($row->to_status)]),
                    $caseUrl($row), $row->created_at),
            ],
        ];

        $sources['respondent_view'] = $applicant
            ? [
                // A respondent opened one of the applicant's cases.
                'query' => DB::table('court_respondent_case_views as v')
                    ->join('court_cases as c', 'c.id', '=', 'v.case_id')
                    ->join('court_respondents as r', 'r.id', '=', 'v.respondent_id')
                    ->select('v.id', 'v.viewed_at', 'v.case_id', 'c.case_number', DB::raw("TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) as respondent_name"))
                    ->whereIn('c.id', CaseParties::caseIdsFor((int) $owner))
                    ->where('v.viewed_at', '>=', now()->subDays(14))
                    ->whereNotExists($unseen('respondent_view', 'v.id'))
                    ->orderByDesc('v.viewed_at'),
                'id' => 'v.id',
                'notice' => fn (object $row): array => $this->notice('respondent_view', (int) $row->id,
                    __('court::app.admin_notifications.respondent_viewed_case_title').': '.$caseLabel($row),
                    __('court::app.admin_notifications.respondent_viewed_case', ['name' => $row->respondent_name ?: __('court::app.admin_notifications.respondent_default')]),
                    $caseUrl($row), $row->viewed_at),
            ]
            : [
                // The cases this respondent opened, as the respondent dashboard lists them.
                'query' => DB::table('court_respondent_case_views as v')
                    ->join('court_cases as c', 'c.id', '=', 'v.case_id')
                    ->select('v.id', 'v.viewed_at', 'v.case_id', 'c.case_number', 'c.title')
                    ->where('v.respondent_id', $owner)
                    ->whereNotExists($unseen('respondent_view', 'v.id'))
                    ->orderByDesc('v.viewed_at'),
                'id' => 'v.id',
                'notice' => fn (object $row): array => $this->notice('respondent_view', (int) $row->id,
                    $typeTitle('respondent_view', $row),
                    (string) ($row->title ?: __('court::app.admin_notifications.open_case_details')),
                    $caseUrl($row), $row->viewed_at),
            ];

        return $sources;
    }

    /** @return array<string, mixed> */
    private function notice(string $type, int $id, string $title, string $message, ?string $url, mixed $at): array
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

    private function statusLabel(?string $status): string
    {
        $key = "app.status.{$status}";

        return trans()->has($key) ? (string) __($key) : Str::headline($status ?? '-');
    }

    private function readsTable(): string
    {
        return $this->portal === 'applicant' ? 'notification_reads' : 'respondent_notification_reads';
    }

    private function ownerColumn(): string
    {
        return $this->portal === 'applicant' ? 'applicant_id' : 'respondent_id';
    }
}
