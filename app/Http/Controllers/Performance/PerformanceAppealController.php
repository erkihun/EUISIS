<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\Performance\AppealDecision;
use App\Models\GrievanceCommitteeMember;
use App\Models\PerformanceAppeal;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\PerformanceAppealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Appeal review queue: committee members see their committees' appeals; reviewers their scope. */
class PerformanceAppealController extends PerformanceController
{
    public function __construct(
        private readonly PerformanceAppealService $appeals,
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_appeals.review') || $user->can('performance_appeals.decide'), 403);

        $employeeId = $this->access->employeeOf($user)?->getKey();
        $committeeIds = $employeeId === null ? [] : GrievanceCommitteeMember::query()->where('employee_id', $employeeId)->where('status', 'active')->pluck('committee_id')->all();

        $appeals = PerformanceAppeal::query()->with(['employee:id,full_name,name_en,employee_number', 'result:id,final_score,rating_label_en,rating_label_am'])
            ->where(function ($q) use ($user, $committeeIds): void {
                $q->whereIn('committee_id', $committeeIds);
                if ($user->can('performance_appeals.review')) {
                    $q->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user));
                }
            })
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->latest('submitted_at')->paginate(20);

        return Inertia::render('Performance/Appeals/Index', [
            'appeals' => $appeals->through(fn (PerformanceAppeal $a) => $this->row($a)),
        ]);
    }

    public function show(Request $request, PerformanceAppeal $appeal): Response
    {
        $user = $request->user();
        $canDecide = $this->appeals->canDecide($user, $appeal);
        abort_unless($canDecide || $this->access->inScope($user, 'performance_appeals.review', $appeal->organization_id) && $this->access->employeeOf($user)?->getKey() !== $appeal->employee_id, 403);

        return Inertia::render('Performance/Appeals/Show', [
            'appeal' => [...$this->row($appeal), 'reason' => $appeal->reason, 'has_attachment' => $appeal->attachment_path !== null, 'attachment_name' => $appeal->attachment_name,
                'decision_reason' => $appeal->decision_reason, 'decided_score' => $appeal->decided_score, 'trace' => $appeal->result?->snapshot_json],
            'decisions' => AppealDecision::values(),
            'can' => ['decide' => $canDecide],
        ]);
    }

    public function decide(Request $request, PerformanceAppeal $appeal): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::enum(AppealDecision::class)],
            'decision_reason' => ['required', 'string', 'max:5000'],
            'decided_score' => ['nullable', 'numeric', 'min:0', 'max:200'],
        ]);
        $this->appeals->decide($appeal, AppealDecision::from($data['decision']), $data['decision_reason'], isset($data['decided_score']) ? (string) $data['decided_score'] : null, $request->user());

        return $this->saved();
    }

    public function attachment(Request $request, PerformanceAppeal $appeal): BinaryFileResponse
    {
        $user = $request->user();
        $own = $this->access->employeeOf($user)?->getKey() === $appeal->employee_id;
        abort_unless($appeal->attachment_path !== null && ($own || $this->appeals->canDecide($user, $appeal) || $this->access->inScope($user, 'performance_appeals.review', $appeal->organization_id)), 403);

        return response()->download(Storage::disk('local')->path($appeal->attachment_path), $appeal->attachment_name ?? 'attachment', ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> */
    private function row(PerformanceAppeal $a): array
    {
        return [
            'id' => $a->getKey(), 'appeal_no' => $a->appeal_no, 'status' => $a->status->value, 'decision' => $a->decision?->value,
            'submitted_at' => $a->submitted_at->toIso8601String(), 'decided_at' => $a->decided_at?->toIso8601String(),
            'employee' => ['name' => $a->employee?->full_name, 'name_en' => $a->employee?->name_en, 'number' => $a->employee?->employee_number],
            'result' => $a->result ? ['final_score' => $a->result->final_score, 'rating_en' => $a->result->rating_label_en, 'rating_am' => $a->result->rating_label_am] : null,
        ];
    }
}
