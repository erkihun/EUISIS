<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\AssessmentRecord;
use App\Services\Assessment\Execution\CompetencyAssessmentResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal › My Assessments: the signed-in employee's own records only.
 * No employee id in the URL; no evaluator identities, review notes,
 * other evaluators' comments or drafts.
 */
class MyAssessmentsController extends Controller
{
    public function __construct(private readonly CompetencyAssessmentResultService $results) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('assessments.view_own_result'), 403);
        $records = $user->employee_id === null ? collect() : AssessmentRecord::query()->where('employee_id', $user->employee_id)
            ->whereNotIn('status', ['cancelled'])->with(['version.form.type', 'cycle'])->orderByDesc('period_end')->limit(100)->get();

        return Inertia::render('Employee/MyAssessments', [
            'records' => $records->map(fn (AssessmentRecord $r) => $this->results->employeeView($r))->values(),
        ]);
    }

    public function acknowledge(Request $request, AssessmentRecord $record): RedirectResponse
    {
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null;
        $this->results->acknowledge($request->user(), $record, $comment);

        return back()->with('success', __('assessments.execution.acknowledged'));
    }
}
