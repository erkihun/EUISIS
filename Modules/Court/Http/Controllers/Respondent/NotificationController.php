<?php

namespace Modules\Court\Http\Controllers\Respondent;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Respondent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function markOne(Request $request)
    {
        session(['acting_as_respondent' => true]);
        $respondentId = $this->resolveRespondentId();
        abort_if(!$respondentId, 403);

        $data = $request->validate([
            // Only the kinds the respondent feed lists (any string used to be stored).
            'type'     => 'required|in:hearing,message,status,respondent_view',
            'sourceId' => 'required|integer',
        ]);

        DB::table('court_respondent_notification_reads')->updateOrInsert(
            [
                'respondent_id' => $respondentId,
                'type'          => $data['type'],
                'source_id'     => $data['sourceId'],
            ],
            [
                'seen_at'    => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return back()->with('success', __('court::app.admin_notifications.marked_as_seen'));
    }

    public function markAll()
    {
        session(['acting_as_respondent' => true]);
        $respondentId = $this->resolveRespondentId();
        abort_if(!$respondentId, 403);

        $now = now();
        $tablesExist = DB::getSchemaBuilder()->hasTable('court_respondent_notification_reads')
            && DB::getSchemaBuilder()->hasTable('court_respondent_case_views')
            && DB::getSchemaBuilder()->hasTable('court_case_hearings')
            && DB::getSchemaBuilder()->hasTable('court_case_status_logs')
            && DB::getSchemaBuilder()->hasTable('court_case_messages');

        if (!$tablesExist) {
            return back()->with('success', __('court::app.admin_notifications.marked_as_seen'));
        }

        $caseIds = DB::table('court_respondent_case_views')
            ->where('respondent_id', $respondentId)
            ->pluck('case_id')
            ->all();

        $types = [
            'respondent_view' => DB::table('court_respondent_case_views as v')->select('v.id as source_id')
                ->where('v.respondent_id', $respondentId)
                ->whereNotExists(function ($q) use ($respondentId) {
                    $q->from('court_respondent_notification_reads as r')
                        ->whereColumn('r.source_id', 'v.id')
                        ->where('r.type', 'respondent_view')
                        ->where('r.respondent_id', $respondentId);
                }),
            'hearing' => DB::table('court_case_hearings as h')->select('h.id as source_id')
                ->whereIn('h.case_id', $caseIds)
                ->whereNotExists(function ($q) use ($respondentId) {
                    $q->from('court_respondent_notification_reads as r')
                        ->whereColumn('r.source_id', 'h.id')
                        ->where('r.type', 'hearing')
                        ->where('r.respondent_id', $respondentId);
                }),
            'status' => DB::table('court_case_status_logs as s')->select('s.id as source_id')
                ->whereIn('s.case_id', $caseIds)
                ->whereNotExists(function ($q) use ($respondentId) {
                    $q->from('court_respondent_notification_reads as r')
                        ->whereColumn('r.source_id', 's.id')
                        ->where('r.type', 'status')
                        ->where('r.respondent_id', $respondentId);
                }),
            'message' => DB::table('court_case_messages as m')->select('m.id as source_id')
                ->whereIn('m.case_id', $caseIds)
                ->whereNotExists(function ($q) use ($respondentId) {
                    $q->from('court_respondent_notification_reads as r')
                        ->whereColumn('r.source_id', 'm.id')
                        ->where('r.type', 'message')
                        ->where('r.respondent_id', $respondentId);
                }),
        ];

        $rows = [];
        foreach ($types as $type => $builder) {
            foreach ($builder->pluck('source_id') as $id) {
                $rows[] = [
                    'respondent_id' => $respondentId,
                    'type'          => $type,
                    'source_id'     => $id,
                    'seen_at'       => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }

        if (!empty($rows)) {
            // An upsert, so a double click (or two tabs) cannot hit the unique key.
            DB::table('court_respondent_notification_reads')->upsert($rows, ['respondent_id', 'type', 'source_id'], ['seen_at', 'updated_at']);
        }

        return back()->with('success', __('court::app.admin_notifications.all_marked_as_seen'));
    }

    private function resolveRespondentId(): ?int
    {
        if (auth('respondent')->check()) {
            return auth('respondent')->id();
        }

        $applicant = auth('applicant')->user();
        if (!$applicant) {
            return null;
        }

        $resp = Respondent::where('email', $applicant->email)->first();
        return $resp?->id;
    }
}
