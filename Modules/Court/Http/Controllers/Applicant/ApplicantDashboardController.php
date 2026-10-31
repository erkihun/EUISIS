<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Application\Support\DecisionPdf;
use Modules\Court\Application\Support\EthiopianDate;
use Modules\Court\Application\Services\CaseParties;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ApplicantDashboardController extends Controller
{
    /**
     * Download the sealed decision PDF for a case the applicant owns.
     * Only available once the decision is approved AND published.
     */
    public function downloadDecision(Decision $decision)
    {
        $applicantId = auth('applicant')->id();

        $ownsCase = DB::table('court_cases')
            ->whereIn('id', CaseParties::caseIdsFor((int) $applicantId))
            ->where('case_number', $decision->case_number)
            ->exists();

        abort_unless($ownsCase, 404);
        abort_unless($decision->isDownloadableByParties(), 403);

        return DecisionPdf::render($decision)->download(DecisionPdf::filename($decision));
    }

    public function index()
    {
        $applicantId = auth('applicant')->id();

        $counts = DB::table('court_cases')
            ->whereIn('id', CaseParties::caseIdsFor((int) $applicantId))
            ->selectRaw("
                COUNT(*) as total,
                SUM(status = 'pending') as pending,
                SUM(status = 'active') as active,
                SUM(status IN ('closed', 'dismissed')) as closed
            ")
            ->first();

        $total   = (int) ($counts->total ?? 0);
        $pending = (int) ($counts->pending ?? 0);
        $active  = (int) ($counts->active ?? 0);
        $closed  = (int) ($counts->closed ?? 0);

        $caseNumbers = DB::table('court_cases')
            ->whereIn('id', CaseParties::caseIdsFor((int) $applicantId))
            ->pluck('case_number')
            ->filter()
            ->values();

        $lettersCount = 0;
        $responsesCount = 0;
        $decisionsCount = 0;
        $responseRepliesCount = 0;

        $caseLetters = collect();
        $caseResponses = collect();
        $caseDecisions = collect();
        $responseReplies = collect();

        if ($caseNumbers->isNotEmpty()) {
            $lettersCount = DB::table('court_letters')
                ->whereIn('case_number', $caseNumbers)
                ->where('approval_status', 'approved')
                ->count();

            $responsesCount = DB::table('court_respondent_responses')
                ->whereIn('case_number', $caseNumbers)
                ->where('review_status', 'accepted')
                ->count();

            $decisionsCount = DB::table('court_decisions')
                ->whereIn('case_number', $caseNumbers)
                ->count();

            $caseLetters = DB::table('court_letters as l')
                ->leftJoin('court_letter_templates as lt', 'lt.id', '=', 'l.letter_template_id')
                ->select(
                    'l.id',
                    'l.subject',
                    'l.reference_number',
                    'l.case_number',
                    'l.created_at',
                    'lt.title as template_title'
                )
                ->whereIn('l.case_number', $caseNumbers)
                ->where('l.approval_status', 'approved')
                ->orderByDesc('l.created_at')
                ->limit(5)
                ->get();

            $caseResponses = DB::table('court_respondent_responses as rr')
                ->leftJoin('court_cases as cc', function ($join) {
                    $join->on('cc.case_number', '=', 'rr.case_number');
                })
                ->select('rr.id', 'rr.title', 'rr.case_number', 'rr.created_at', 'cc.id as case_id')
                ->whereIn('rr.case_number', $caseNumbers)
                ->where('rr.review_status', 'accepted')
                ->orderByDesc('rr.created_at')
                ->limit(5)
                ->get();

            $caseDecisions = DB::table('court_decisions')
                ->select('id', 'name', 'case_number', 'decision_date', 'status', 'approved_at', 'created_at')
                ->whereIn('case_number', $caseNumbers)
                ->orderByDesc('decision_date')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
        }

        $responseRepliesCount = DB::table('court_applicant_response_replies')
            ->where('applicant_id', $applicantId)
            ->count();

        $responseReplies = DB::table('court_applicant_response_replies as arr')
            ->join('court_cases as cc', 'cc.id', '=', 'arr.case_id')
            ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
            ->select(
                'arr.id',
                'arr.case_id',
                'arr.respondent_response_id',
                'arr.review_status',
                'arr.review_note',
                'arr.created_at',
                'cc.case_number',
                'rr.response_number'
            )
            ->where('arr.applicant_id', $applicantId)
            ->orderByDesc('arr.created_at')
            ->limit(5)
            ->get();

        $recent = DB::table('court_cases')
            ->leftJoin('court_case_types', 'court_case_types.id', '=', 'court_cases.case_type_id')

            ->whereIn('court_cases.id', CaseParties::caseIdsFor((int) $applicantId))
            ->select(
                'court_cases.id',
                'court_cases.case_number',
                'court_cases.title',
                'court_cases.status',
                'court_case_types.name as case_type',

                'court_cases.created_at'
            )
            ->orderByDesc('court_cases.created_at')
            ->limit(6)
            ->get();

        $applicant = auth('applicant')->user();
        $iso = fn (mixed $value): ?string => $value ? Carbon::parse($value)->toIso8601String() : null;

        // The portal home (React, Court/Applicant/Dashboard).
        return Inertia::render('Court/Applicant/Dashboard', [
            'applicant' => [
                'name' => ($applicant?->full_name ?: $applicant?->email) ?: null,
                'is_lawyer' => (bool) ($applicant?->is_lawyer ?? false),
            ],
            'today' => now()->toIso8601String(),
            'counts' => [
                'total' => $total,
                'pending' => $pending,
                'active' => $active,
                'closed' => $closed,
                'letters' => (int) $lettersCount,
                'responses' => (int) $responsesCount,
                'decisions' => (int) $decisionsCount,
                'responseReplies' => (int) $responseRepliesCount,
            ],
            'recent' => $recent->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'title' => $row->title,
                'status' => (string) $row->status,
                'case_type' => $row->case_type,
                'created_at' => $iso($row->created_at),
                'created_relative' => EthiopianDate::smartRelative($row->created_at),
            ])->values()->all(),
            'letters' => $caseLetters->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'subject' => $row->subject ?: ($row->template_title ?: null),
                'reference_number' => $row->reference_number ?: null,
                'case_number' => $row->case_number,
                'created_at' => $iso($row->created_at),
            ])->values()->all(),
            'responses' => $caseResponses->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'case_number' => $row->case_number,
                'case_id' => $row->case_id ? (int) $row->case_id : null,
                'created_relative' => EthiopianDate::smartRelative($row->created_at, ''),
            ])->values()->all(),
            'responseReplies' => $responseReplies->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'case_id' => (int) $row->case_id,
                'respondent_response_id' => (int) $row->respondent_response_id,
                'case_number' => (string) $row->case_number,
                'response_number' => $row->response_number ?: null,
                'review_status' => (string) ($row->review_status ?? 'awaiting_review'),
                'review_note' => $row->review_note ?: null,
                'created_at' => $iso($row->created_at),
            ])->values()->all(),
            'decisions' => $caseDecisions->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'case_number' => $row->case_number,
                'status' => $row->status ?: null,
                'date' => $iso($row->decision_date ?: $row->created_at),
                'downloadable' => ($row->status ?? null) === 'published' && ! empty($row->approved_at),
            ])->values()->all(),
        ]);
    }
}
