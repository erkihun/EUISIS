<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Application\Support\EthiopianDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ApplicantNotificationController extends Controller
{
    /**
     * List unseen notifications (hearings, admin messages, status changes)
     * with independent pagination for each section.
     */
    public function index(Request $request)
    {
        $aid = auth('applicant')->id();
        abort_if(!$aid, 403);

        // Unseen hearings (next 60d + last 1d)
        $unseenHearings = DB::table('court_case_hearings as h')
            ->join('court_cases as c', 'c.id', '=', 'h.case_id')
            ->select('h.id', 'h.hearing_at', 'h.type', 'h.location', 'c.id as case_id', 'c.case_number')
            ->where('c.applicant_id', $aid)
            ->whereBetween('h.hearing_at', [now()->subDay(), now()->addDays(60)])
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'h.id')
                    ->where('nr.type', 'hearing')
                    ->where('nr.applicant_id', $aid);
            })
            ->orderBy('h.hearing_at')
            ->paginate(
                perPage: 10,
                // The page shows each hearing's type and room, as the Blade list meant to.
                columns: ['h.id', 'h.hearing_at', 'h.type', 'h.location', 'c.id as case_id', 'c.case_number'],
                pageName: 'hearings_page'
            );

        // Unseen admin messages (last 14d)
        $unseenMsgs = DB::table('court_case_messages as m')
            ->join('court_cases as c', 'c.id', '=', 'm.case_id')
            ->select('m.id', 'm.body', 'm.created_at', 'c.id as case_id', 'c.case_number')
            ->whereNotNull('m.sender_user_id')
            ->where('c.applicant_id', $aid)
            ->where('m.created_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'm.id')
                    ->where('nr.type', 'message')
                    ->where('nr.applicant_id', $aid);
            })
            ->orderByDesc('m.created_at')
            ->paginate(
                perPage: 10,
                columns: ['m.id', 'm.body', 'm.created_at', 'c.id as case_id', 'c.case_number'],
                pageName: 'messages_page'
            );

        // Unseen status changes (last 14d)
        $unseenStatus = DB::table('court_case_status_logs as l')
            ->join('court_cases as c', 'c.id', '=', 'l.case_id')
            ->select('l.id', 'l.from_status', 'l.to_status', 'l.created_at', 'c.id as case_id', 'c.case_number')
            ->where('c.applicant_id', $aid)
            ->where('l.created_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'l.id')
                    ->where('nr.type', 'status')
                    ->where('nr.applicant_id', $aid);
            })
            ->orderByDesc('l.created_at')
            ->paginate(
                perPage: 10,
                columns: ['l.id', 'l.from_status', 'l.to_status', 'l.created_at', 'c.id as case_id', 'c.case_number'],
                pageName: 'status_page'
            );

        $respondentViews = DB::table('court_respondent_case_views as v')
            ->join('court_cases as c', 'c.id', '=', 'v.case_id')
            ->join('court_respondents as r', 'r.id', '=', 'v.respondent_id')
            ->select(
                'v.id',
                'v.viewed_at',
                'v.case_id',
                'c.case_number',
                DB::raw("TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) as respondent_name")
            )
            ->where('c.applicant_id', $aid)
            ->where('v.viewed_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'v.id')
                    ->where('nr.type', 'respondent_view')
                    ->where('nr.applicant_id', $aid);
            })
            ->orderByDesc('v.viewed_at')
            ->paginate(10, ['*'], 'respondent_views_page');

        $at = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;
        $legacyApplicantUpdate = 'Applicant updated the case details. Please review the submission.';
        $statusLabel = function (?string $status): string {
            $key = "app.status.{$status}";

            return trans()->has($key) ? (string) __($key) : Str::headline($status ?? '-');
        };

        // The page (React, Court/Applicant/Notifications): four lists, each paged on its own.
        return Inertia::render('Court/Applicant/Notifications', [
            'hearings' => $unseenHearings->onEachSide(0)->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'case_number' => (string) $row->case_number,
                'type' => $row->type ?: null,
                'location' => $row->location ?: null,
                'at' => $at($row->hearing_at),
            ]),
            'messages' => $unseenMsgs->onEachSide(0)->through(function (object $row) use ($at, $legacyApplicantUpdate): array {
                $body = trim((string) $row->body);
                // Only an http(s) address becomes a link (FILTER_VALIDATE_URL alone lets javascript: through).
                $isUrl = filter_var($body, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $body) === 1;

                return [
                    'id' => (int) $row->id,
                    'case_id' => (int) $row->case_id,
                    'case_number' => (string) $row->case_number,
                    // A message that is a link (a letter preview) is shown as that link.
                    'url' => $isUrl ? $body : null,
                    'excerpt' => $isUrl ? null : Str::limit($body === $legacyApplicantUpdate ? (string) __('court::cases.notifications.applicant_updated_submission') : $body, 80),
                    'at' => $at($row->created_at),
                    'relative' => EthiopianDate::smartRelative($row->created_at),
                ];
            }),
            'respondentViews' => $respondentViews->onEachSide(0)->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'case_number' => (string) $row->case_number,
                'respondent_name' => $row->respondent_name ?: null,
                'at' => $at($row->viewed_at),
                'relative' => EthiopianDate::smartRelative($row->viewed_at),
            ]),
            'statusChanges' => $unseenStatus->onEachSide(0)->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'case_number' => (string) $row->case_number,
                'from' => $statusLabel($row->from_status),
                'to' => $statusLabel($row->to_status),
                'at' => $at($row->created_at),
                'relative' => EthiopianDate::smartRelative($row->created_at),
            ]),
        ]);
    }

    /**
     * Mark a single notification (by type + source id) as seen.
     */
    public function markOne(Request $request)
    {
        $aid = auth('applicant')->id();
        abort_if(!$aid, 403);

        $data = $request->validate([
            'type'     => 'required|in:hearing,message,status,respondent_view',
            'sourceId' => 'required|integer',
        ]);

        DB::table('court_notification_reads')->updateOrInsert(
            ['applicant_id' => $aid, 'type' => $data['type'], 'source_id' => $data['sourceId']],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', __('court::app.admin_notifications.marked_as_seen'));
    }

    /**
     * Mark ALL currently-unseen items as seen for this applicant.
     */
    public function markAll()
    {
        $aid = auth('applicant')->id();
        abort_if(!$aid, 403);

        $now = now();

        // Collect unseen IDs for all three types, then bulk insert
        $hIds = DB::table('court_case_hearings as h')
            ->join('court_cases as c', 'c.id', '=', 'h.case_id')
            ->where('c.applicant_id', $aid)
            ->whereBetween('h.hearing_at', [now()->subDay(), now()->addDays(60)])
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'h.id')
                    ->where('nr.type', 'hearing')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('h.id')
            ->all();

        $mIds = DB::table('court_case_messages as m')
            ->join('court_cases as c', 'c.id', '=', 'm.case_id')
            ->whereNotNull('m.sender_user_id')
            ->where('c.applicant_id', $aid)
            ->where('m.created_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'm.id')
                    ->where('nr.type', 'message')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('m.id')
            ->all();

        $sIds = DB::table('court_case_status_logs as l')
            ->join('court_cases as c', 'c.id', '=', 'l.case_id')
            ->where('c.applicant_id', $aid)
            ->where('l.created_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'l.id')
                    ->where('nr.type', 'status')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('l.id')
            ->all();

        $respondentViewIds = DB::table('court_respondent_case_views as v')
            ->join('court_cases as c', 'c.id', '=', 'v.case_id')
            ->where('c.applicant_id', $aid)
            ->where('v.viewed_at', '>=', now()->subDays(14))
            ->whereNotExists(function ($q) use ($aid) {
                $q->from('court_notification_reads as nr')
                    ->whereColumn('nr.source_id', 'v.id')
                    ->where('nr.type', 'respondent_view')
                    ->where('nr.applicant_id', $aid);
            })
            ->pluck('v.id')
            ->all();

        $rows = [];
        foreach ($hIds as $id) {
            $rows[] = [
                'applicant_id' => $aid,
                'type'         => 'hearing',
                'source_id'    => $id,
                'seen_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        foreach ($mIds as $id) {
            $rows[] = [
                'applicant_id' => $aid,
                'type'         => 'message',
                'source_id'    => $id,
                'seen_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        foreach ($sIds as $id) {
            $rows[] = [
                'applicant_id' => $aid,
                'type'         => 'status',
                'source_id'    => $id,
                'seen_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        foreach ($respondentViewIds as $id) {
            $rows[] = [
                'applicant_id' => $aid,
                'type'         => 'respondent_view',
                'source_id'    => $id,
                'seen_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (!empty($rows)) {
            // Avoid unique key errors on (applicant_id,type,source_id)
            DB::table('court_notification_reads')->upsert(
                $rows,
                ['applicant_id', 'type', 'source_id'],
                ['seen_at', 'updated_at'] // fields to update on conflict
            );
        }

        return back()->with('success', __('court::app.admin_notifications.all_marked_as_seen'));
    }

    /**
     * Show email preferences. Falls back to sane defaults if missing.
     */
    public function settingsEdit()
    {
        $aid = auth('applicant')->id();
        abort_if(!$aid, 403);

        $prefs = DB::table('court_applicant_notification_prefs')
            ->where('applicant_id', $aid)
            ->first();

        if (!$prefs) {
            // Defaults match your SQL defaults
            $prefs = (object) [
                'email_status'        => 1,
                'email_hearing'       => 1,
                'email_message'       => 1,
                'email_weekly_digest' => 0,
            ];
        }

        // React, Court/Applicant/NotificationSettings.
        return Inertia::render('Court/Applicant/NotificationSettings', [
            'prefs' => [
                'email_status' => (bool) ($prefs->email_status ?? 1),
                'email_hearing' => (bool) ($prefs->email_hearing ?? 1),
                'email_message' => (bool) ($prefs->email_message ?? 1),
                'email_weekly_digest' => (bool) ($prefs->email_weekly_digest ?? 0),
            ],
        ]);
    }

    /**
     * Save email preferences.
     * Input names must match the Blade checkboxes: email_status, email_hearing, email_message, email_weekly_digest
     */
    public function settingsUpdate(Request $request)
    {
        $aid = auth('applicant')->id();
        abort_if(!$aid, 403);

        $request->validate([
            'email_status'        => 'sometimes|boolean',
            'email_hearing'       => 'sometimes|boolean',
            'email_message'       => 'sometimes|boolean',
            'email_weekly_digest' => 'sometimes|boolean',
        ]);

        DB::table('court_applicant_notification_prefs')->updateOrInsert(
            ['applicant_id' => $aid],
            [
                'email_status'        => (int) $request->boolean('email_status'),
                'email_hearing'       => (int) $request->boolean('email_hearing'),
                'email_message'       => (int) $request->boolean('email_message'),
                'email_weekly_digest' => (int) $request->boolean('email_weekly_digest'),
                'updated_at'          => now(),
                'created_at'          => now(), // ignored on update
            ]
        );

        return back()->with('success', __('court::app.notification_preferences.updated'));
    }
}
