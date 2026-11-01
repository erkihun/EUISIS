<?php

namespace Modules\Court\Http\Controllers\Respondent;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Respondent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        Session::put('acting_as_respondent', true);

        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant, 403);

        $respondent = $this->resolveActingRespondent($applicant);
        // The same unseen items as the header bell; the card counts them all (it used to count only case views).
        $notifications = $this->unseenNotifications((int) $respondent->id);

        $stats = [
            'cases'        => DB::table('court_respondent_case_views')->where('respondent_id', $respondent->id)->count(),
            'responses'    => DB::table('court_respondent_responses')->where('respondent_id', $respondent->id)->count(),
            'response_replies' => DB::table('court_applicant_response_replies as arr')
                ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
                ->join('court_respondent_case_views as rcv', function ($join) use ($respondent) {
                    $join->on('rcv.case_id', '=', 'arr.case_id')
                        ->where('rcv.respondent_id', '=', $respondent->id);
                })
                ->where('rr.respondent_id', $respondent->id)
                ->where('arr.review_status', 'accepted')
                ->count(),
            'notifications' => $notifications['count'],
        ];

        $recentCases = DB::table('court_respondent_case_views as v')
            ->join('court_cases as c', 'c.id', '=', 'v.case_id')
            ->select('c.case_number', 'c.title', 'c.status', 'v.viewed_at')
            ->where('v.respondent_id', $respondent->id)
            ->orderByDesc('v.viewed_at')
            ->limit(5)
            ->get();

        $letters = DB::table('court_respondent_case_views as v')
            ->join('court_cases as c', 'c.id', '=', 'v.case_id')
            ->join('court_letters as l', 'l.case_number', '=', 'c.case_number')
            ->leftJoin('court_letter_templates as lt', 'lt.id', '=', 'l.letter_template_id')
            ->leftJoin('court_staff as u', 'u.id', '=', 'l.user_id')
            ->where('v.respondent_id', $respondent->id)
            ->where('l.approval_status', 'approved')
            ->orderByDesc('l.created_at')
            ->limit(5)
            ->select(
                'l.id',
                'l.subject',
                'l.reference_number',
                'l.created_at',
                'c.case_number',
                'c.title as case_title',
                'lt.title as template_title',
                'u.name as author_name'
            )
            ->get();

        $responseReplies = DB::table('court_applicant_response_replies as arr')
            ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
            ->join('court_cases as c', 'c.id', '=', 'arr.case_id')
            ->join('court_respondent_case_views as rcv', function ($join) use ($respondent) {
                $join->on('rcv.case_id', '=', 'arr.case_id')
                    ->where('rcv.respondent_id', '=', $respondent->id);
            })
            ->leftJoin('court_applicants as a', 'a.id', '=', 'arr.applicant_id')
            ->where('rr.respondent_id', $respondent->id)
            ->where('arr.review_status', 'accepted')
            ->orderByDesc('arr.created_at')
            ->limit(5)
            ->select(
                'arr.id',
                'arr.case_id',
                'arr.respondent_response_id',
                'arr.created_at',
                'arr.description',
                'c.case_number',
                'rr.response_number',
                'a.first_name as applicant_first_name',
                'a.middle_name as applicant_middle_name',
                'a.last_name as applicant_last_name'
            )
            ->get();

        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        // The respondent portal's home (React, Court/Respondent/Dashboard).
        return Inertia::render('Court/Respondent/Dashboard', [
            'stats' => array_map('intval', $stats),
            'recentCases' => $recentCases->map(fn (object $case): array => [
                'case_number' => (string) $case->case_number,
                'title' => $case->title,
                'status' => $case->status,
                'viewed_at' => $iso($case->viewed_at),
                'show_url' => route('court.respondent.cases.show', $case->case_number),
            ])->values()->all(),
            'letters' => $letters->map(fn (object $letter): array => [
                'id' => (int) $letter->id,
                'subject' => $letter->subject,
                'reference_number' => $letter->reference_number,
                'created_at' => $iso($letter->created_at),
                'case_number' => $letter->case_number,
                'case_title' => $letter->case_title,
                'template_title' => $letter->template_title,
                'author_name' => $letter->author_name,
                'preview_url' => $this->letterPreviewUrl((int) $letter->id),
            ])->values()->all(),
            'responseReplies' => $responseReplies->map(fn (object $reply): array => [
                'id' => (int) $reply->id,
                'case_number' => $reply->case_number,
                'response_number' => $reply->response_number,
                'applicant_name' => trim(($reply->applicant_first_name ?? '').' '.($reply->applicant_middle_name ?? '').' '.($reply->applicant_last_name ?? '')),
                'created_at' => $iso($reply->created_at),
            ])->values()->all(),
            // The newest unseen items, as the header bell lists them (PortalNotificationFeed).
            'notifications' => $notifications,
        ]);
    }

    /**
     * What the Blade portal header's bell listed (components/applicant-layout): unseen case views, hearings in the
     * day before to 60 days ahead, status changes and staff messages of the last 14 days on the respondent's cases.
     * Read through respondent.notifications.markOne / markAll.
     *
     * @return array{count: int, items: list<array{type: string, source_id: int, at: ?string, case_number: ?string, title: ?string, show_url: ?string}>}
     */
    private function unseenNotifications(int $respondentId): array
    {
        try {
            $caseIds = DB::table('court_respondent_case_views')->where('respondent_id', $respondentId)->pluck('case_id')->all();
            $now = now();
            $unseen = fn (string $alias, string $type) => function ($query) use ($alias, $type, $respondentId): void {
                $query->from('court_respondent_notification_reads as r')
                    ->whereColumn('r.source_id', $alias.'.id')
                    ->where('r.type', $type)
                    ->where('r.respondent_id', $respondentId);
            };

            $sources = [
                DB::table('court_respondent_case_views as v')->join('court_cases as c', 'c.id', '=', 'v.case_id')
                    ->select('v.id', 'v.viewed_at as at', 'c.case_number', 'c.title', DB::raw("'respondent_view' as notif_type"))
                    ->where('v.respondent_id', $respondentId)
                    ->whereNotExists($unseen('v', 'respondent_view')),
                DB::table('court_case_hearings as h')->join('court_cases as c', 'c.id', '=', 'h.case_id')
                    ->select('h.id', 'h.hearing_at as at', 'c.case_number', 'c.title', DB::raw("'hearing' as notif_type"))
                    ->whereIn('h.case_id', $caseIds)
                    ->whereBetween('h.hearing_at', [$now->copy()->subDay(), $now->copy()->addDays(60)])
                    ->whereNotExists($unseen('h', 'hearing')),
                DB::table('court_case_status_logs as s')->join('court_cases as c', 'c.id', '=', 's.case_id')
                    ->select('s.id', 's.created_at as at', 'c.case_number', 'c.title', DB::raw("'status' as notif_type"))
                    ->whereIn('s.case_id', $caseIds)
                    ->where('s.created_at', '>=', $now->copy()->subDays(14))
                    ->whereNotExists($unseen('s', 'status')),
                DB::table('court_case_messages as m')->join('court_cases as c', 'c.id', '=', 'm.case_id')
                    ->select('m.id', 'm.created_at as at', 'c.case_number', 'c.title', DB::raw("'message' as notif_type"))
                    ->whereIn('m.case_id', $caseIds)
                    ->whereNotNull('m.sender_user_id')
                    ->where('m.created_at', '>=', $now->copy()->subDays(14))
                    ->whereNotExists($unseen('m', 'message')),
            ];

            $count = array_sum(array_map(fn ($query): int => (int) (clone $query)->count(), $sources));
            $items = collect($sources)
                ->flatMap(fn ($query) => (clone $query)->limit(5)->get())
                ->sortByDesc('at')
                ->take(8)
                ->map(fn (object $item): array => [
                    'type' => (string) $item->notif_type,
                    'source_id' => (int) $item->id,
                    'at' => $item->at ? Carbon::parse($item->at)->toIso8601String() : null,
                    'case_number' => $item->case_number,
                    'title' => $item->title,
                    'show_url' => $item->case_number ? route('court.respondent.cases.show', $item->case_number) : null,
                ])
                ->values()->all();

            return ['count' => $count, 'items' => $items];
        } catch (\Throwable) {
            // As the Blade header did: no list rather than a broken page.
            return ['count' => 0, 'items' => []];
        }
    }

    /** The signed public preview of an approved letter (the Blade page built it the same way). */
    private function letterPreviewUrl(int $letterId): string
    {
        try {
            return URL::signedRoute('court.letters.case-preview', ['letter' => $letterId]);
        } catch (\Throwable) {
            return url('/court/case-letters/'.$letterId);
        }
    }

    private function resolveActingRespondent($applicant): Respondent
    {
        $respondent = Respondent::where('email', $applicant->email)->first();
        if (!$respondent) {
            $phone = $applicant->phone ?? 'resp_' . substr(md5((string) microtime(true)), 0, 12);
            if (Respondent::where('phone', $phone)->where('email', '!=', $applicant->email)->exists()) {
                $phone = 'resp_' . substr(md5(uniqid('', true)), 0, 12);
            }

            $respondent = Respondent::create([
                'first_name'        => $applicant->first_name ?? '',
                'middle_name'       => $applicant->middle_name ?? '',
                'last_name'         => $applicant->last_name ?? '',
                'gender'            => $applicant->gender ?? null,
                'position'          => $applicant->position ?? '',
                'organization_name' => $applicant->organization_name ?? '',
                'address'           => $applicant->address ?? '',
                'national_id'       => $this->applicantNationalId($applicant),
                'phone'             => $phone,
                'email'             => $applicant->email,
                'password'          => $applicant->password,
            ]);
        } else {
            $dirty = false;
            $maybeNationalId = $this->applicantNationalId($applicant);
            if (!$respondent->national_id && $maybeNationalId) {
                $respondent->national_id = $maybeNationalId;
                $dirty = true;
            }
            if ($dirty) {
                $respondent->save();
            }
        }

        return $respondent;
    }

    private function applicantNationalId($applicant): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($applicant->getRawOriginal('national_id_number') ?? $applicant->national_id_number ?? ''));
        if ($digits === '') {
            return null;
        }
        return substr($digits, 0, 16);
    }
}
