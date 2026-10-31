<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\BenchNote;
use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\Letter;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Display a system-wide report dashboard.
     */
    public function index(Request $request)
    {
        $caseStatuses = ['pending', 'active', 'adjourned', 'dismissed', 'closed'];
        $judges = User::where('status', 'active')->orderBy('name')->get(['id', 'name']);

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $selectedStatus = $request->input('status');
        $selectedJudge = $request->input('judge_id');

        $caseFilter = CourtCase::query();
        if ($startDate) {
            $caseFilter->where('court_cases.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $caseFilter->where('court_cases.created_at', '<=', $endDate . ' 23:59:59');
        }
        if ($selectedStatus) {
            // Qualified: the by-judge breakdown joins users, which has a status column too.
            $caseFilter->where('court_cases.status', $selectedStatus);
        }
        if ($selectedJudge) {
            $caseFilter->where('court_cases.judge_id', $selectedJudge);
        }

        $filteredCaseCount = (clone $caseFilter)->count();
        $filteredCaseStatus = (clone $caseFilter)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $casesByJudge = (clone $caseFilter)
            ->join('court_staff as u', 'u.id', '=', 'court_cases.judge_id')
            ->select('u.id as judge_id', 'u.name as judge_name', DB::raw('count(*) as total'))
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $caseStatusBreakdown = CourtCase::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $caseTypeBreakdown = CaseType::query()
            ->leftJoin('court_cases', 'court_case_types.id', '=', 'court_cases.case_type_id')
            ->select('court_case_types.id', 'court_case_types.name', DB::raw('count(court_cases.id) as total'))
            ->groupBy('court_case_types.id', 'court_case_types.name')
            ->orderByDesc('total')
            ->get();

        $appealStatusBreakdown = DB::table('court_appeals')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $decisionStatusBreakdown = Decision::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $letterApprovalBreakdown = Letter::query()
            ->selectRaw('COALESCE(approval_status, "pending") as status, count(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $applicantStatusBreakdown = Applicant::query()
            ->selectRaw('CASE WHEN is_active THEN "active" ELSE "inactive" END as state, count(*) as total')
            ->groupBy('state')
            ->get();

        $userStatusBreakdown = User::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $recentCases = CourtCase::with([
            'applicant:id,first_name,middle_name,last_name',
            'caseType:id,name',
        ])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentAppeals = DB::table('court_appeals as a')
            ->join('court_cases as c', 'c.id', '=', 'a.court_case_id')
            ->select('a.id', 'a.appeal_number', 'a.status', 'a.created_at', 'a.title', 'c.case_number', 'c.id as case_id')
            ->orderByDesc('a.created_at')
            ->limit(5)
            ->get();

        $summaryCards = [
            ['key' => 'cases_total', 'value' => CourtCase::count()],
            ['key' => 'cases_active', 'value' => CourtCase::where('status', 'active')->count()],
            ['key' => 'cases_pending', 'value' => CourtCase::where('status', 'pending')->count()],
            ['key' => 'applicants', 'value' => Applicant::count()],
            ['key' => 'respondents', 'value' => Respondent::count()],
            ['key' => 'appeals', 'value' => DB::table('court_appeals')->count()],
            ['key' => 'decisions', 'value' => Decision::count()],
            ['key' => 'bench_notes', 'value' => BenchNote::count()],
            ['key' => 'letters', 'value' => Letter::count()],
            ['key' => 'letter_templates', 'value' => LetterTemplate::count()],
            ['key' => 'teams', 'value' => Team::count()],
            ['key' => 'users', 'value' => User::count()],
            ['key' => 'hearings', 'value' => DB::table('court_case_hearings')->count()],
            ['key' => 'respondent_responses', 'value' => DB::table('court_respondent_responses')->count()],
        ];

        // The page (React, Court/Reports/Index): every breakdown as {key, total} pairs.
        $pairs = fn ($rows, string $keyColumn): array => collect($rows)
            ->map(fn (object $row): array => ['key' => (string) ($row->{$keyColumn} ?? ''), 'total' => (int) $row->total])
            ->values()->all();

        return Inertia::render('Court/Reports/Index', [
            'summaryCards' => $summaryCards,
            'breakdowns' => [
                'cases_status' => $pairs($caseStatusBreakdown, 'status'),
                'cases_types' => $pairs($caseTypeBreakdown, 'name'),
                'appeals_status' => $pairs($appealStatusBreakdown, 'status'),
                'decisions_status' => $pairs($decisionStatusBreakdown, 'status'),
                'letters_status' => $pairs($letterApprovalBreakdown, 'status'),
                'applicants_status' => $pairs($applicantStatusBreakdown, 'state'),
                'users_status' => $pairs($userStatusBreakdown, 'status'),
            ],
            'recentCases' => $recentCases->map(fn (CourtCase $case): array => [
                'id' => (int) $case->id,
                'case_number' => (string) $case->case_number,
                'title' => $case->title,
                'status' => (string) $case->status,
                'applicant' => $case->applicant?->full_name,
                'case_type' => $case->caseType?->name,
                'created_at' => $case->created_at?->toIso8601String(),
            ])->values()->all(),
            'recentAppeals' => $recentAppeals->map(fn (object $appeal): array => [
                'id' => (int) $appeal->id,
                'appeal_number' => (string) $appeal->appeal_number,
                'title' => $appeal->title,
                'status' => (string) $appeal->status,
                'case_id' => (int) $appeal->case_id,
                'case_number' => (string) $appeal->case_number,
                'created_at' => $appeal->created_at ? \Illuminate\Support\Carbon::parse($appeal->created_at)->toIso8601String() : null,
            ])->values()->all(),
            'caseStatuses' => $caseStatuses,
            'judges' => $judges->map(fn (User $judge): array => ['id' => (int) $judge->id, 'name' => (string) $judge->name])->values()->all(),
            'filtered' => [
                'count' => (int) $filteredCaseCount,
                'status' => $pairs($filteredCaseStatus, 'status'),
                'byJudge' => collect($casesByJudge)->map(fn (object $row): array => ['key' => (string) $row->judge_name, 'total' => (int) $row->total])->values()->all(),
            ],
            'filters' => [
                'start_date' => (string) ($startDate ?? ''),
                'end_date' => (string) ($endDate ?? ''),
                'status' => (string) ($selectedStatus ?? ''),
                'judge_id' => (string) ($selectedJudge ?? ''),
            ],
        ]);
    }
}
