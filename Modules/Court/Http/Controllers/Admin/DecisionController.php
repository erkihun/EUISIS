<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Http\Requests\Admin\StoreDecisionRequest;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Domain\Models\DecisionReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Court\Application\Support\Purifier;

class DecisionController extends Controller
{
    private const string VIEW_ALL_PERMISSION = 'decision.view.all';

    private const STATUS_OPTIONS = [
        'draft',
        'published',
    ];

    private const CASE_LIMIT = 250;

    public function index(Request $request)
    {
        $search = trim($request->string('q')->toString());
        $decisions = Decision::with(['courtCase.judge', 'reviewer'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('case_number', 'like', "%{$search}%")
                        ->orWhere('case_file_number', 'like', "%{$search}%")
                        ->orWhere('decision_content', 'like', "%{$search}%");
                });
            })
            ->tap(fn ($query) => $this->scopeToUserTeam($query))
            ->orderByDesc('decision_date')
            ->paginate(12)
            ->withQueryString();

        // The register (React, Court/Decisions/Index); the row actions follow the Blade page's rules.
        $user = auth()->user();
        $canEdit = $user?->hasPermission('decision.update') ?? false;
        $canDelete = $user?->hasPermission('decision.delete') ?? false;

        return Inertia::render('Court/Decisions/Index', [
            'decisions' => $decisions->through(function (Decision $decision) use ($canEdit, $canDelete): array {
                $open = $this->isPresidingJudgeOrUnassigned($decision) && $decision->status !== 'published';

                return [
                    'id' => (int) $decision->id,
                    'case_id' => $decision->courtCase?->id,
                    'case_number' => $decision->case_number,
                    'case_filed_date' => $decision->case_filed_date?->toDateString(),
                    'applicant' => $decision->applicant_full_name ?: ($decision->courtCase?->title ?: null),
                    'respondent' => $decision->respondent_full_name ?: null,
                    'judge' => $decision->reviewing_admin_user_name
                        ?: ($decision->reviewer?->name
                        ?: (($decision->panel_judges[1]['admin_user_name'] ?? null)
                        ?: ($decision->courtCase?->judge?->name ?: null))),
                    'decision_date' => $decision->decision_date?->toDateString(),
                    'status' => $decision->status,
                    'approved' => $decision->isApproved(),
                    'can_edit' => $canEdit && $open,
                    'can_delete' => $canDelete && $open,
                ];
            }),
            'filters' => ['q' => $search],
            'canCreate' => $user?->hasPermission('decision.create') ?? false,
        ]);
    }

    /**
     * Only the presiding (middle) judge may change a decision. Drafts saved
     * before a panel was mandatory have none, and any authorised editor may
     * open them to assign one (see ensureMiddleJudge()).
     */
    private function isPresidingJudgeOrUnassigned(Decision $decision): bool
    {
        $presiding = $decision->presidingJudgeId();

        return $presiding === null || $presiding === (int) auth()->id();
    }

    /**
     * Restrict a decisions query to cases assigned to the current user's team(s).
     *
     * - Admins and users with decision.view.all see everything.
     * - Team members see decisions for any case assigned to their team(s).
     * - Other users with no team see nothing.
     */
    private function scopeToUserTeam(Builder $query): void
    {
        if ($this->canViewAllDecisions()) {
            return;
        }

        $user = auth()->user();

        $teamIds = $user
            ? \Modules\Court\Domain\Models\Team::query()
                ->whereHas('users', fn ($q) => $q->where('court_staff.id', $user->id))
                ->pluck('id')
                ->all()
            : [];

        if (empty($teamIds)) {
            $query->whereRaw('1 = 0'); // No team => no decisions.
            return;
        }

        $query->whereHas('courtCase', fn ($q) => $q->whereIn('assigned_team_id', $teamIds));
    }

    /**
     * Whether the current user may access this decision (team-scoped).
     */
    private function canAccessDecision(Decision $decision): bool
    {
        if ($this->canViewAllDecisions()) {
            return true;
        }

        $user = auth()->user();
        $teamId = $decision->courtCase?->assigned_team_id;
        if (! $teamId) {
            return false;
        }

        return $user
            ? \Modules\Court\Domain\Models\Team::query()
                ->where('id', $teamId)
                ->whereHas('users', fn ($q) => $q->where('court_staff.id', $user->id))
                ->exists()
            : false;
    }

    private function canViewAllDecisions(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->hasRole('admin') || $user->hasPermission(self::VIEW_ALL_PERMISSION));
    }

    public function create(Request $request)
    {
        // The form (React, Court/Decisions/Create): only closed cases may receive a decision.
        return Inertia::render('Court/Decisions/Create', $this->formProps(
            cases: $this->loadCases(status: 'closed'),
            decision: null,
            preselectedCaseId: $request->integer('case_id') ?: null,
        ));
    }

    public function show(Decision $decision)
    {
        $decision->loadMissing(['courtCase', 'reviews.reviewer', 'approver']);
        abort_unless($this->canAccessDecision($decision), 403);

        $user = auth()->user();
        $isMiddleJudge = $this->isPresidingJudgeOrUnassigned($decision);
        $isPublished = $decision->isPublished();
        $canUpdate = $user?->hasPermission('decision.update') ?? false;

        // The author-first panel, padded to the three seats the page draws.
        $panel = collect($decision->panelJudgesAuthorFirst())->pad(3, [])->take(3)->values()
            ->map(fn (array $judge, int $index): array => [
                'number' => $index + 1,
                'name' => ($judge['admin_user_name'] ?? null) ?: null,
                'vote' => ($judge['vote'] ?? null) ?: null,
            ])->all();

        return Inertia::render('Court/Decisions/Show', [
            'decision' => [
                'id' => (int) $decision->id,
                'name' => $decision->name,
                'case_id' => $decision->courtCase?->id,
                'case_number' => $decision->case_number,
                'case_file_number' => $decision->case_file_number ?: $decision->case_number,
                'decision_date' => $decision->decision_date?->toDateString(),
                'applicant_full_name' => $decision->applicant_full_name ?: null,
                'respondent_full_name' => $decision->respondent_full_name ?: null,
                'status' => $decision->status ?: 'draft',
                'approved' => $decision->isApproved(),
                'approved_at' => $decision->approved_at?->toIso8601String(),
                'approver_name' => $decision->approver?->name,
                // Purified on save; cleaned again on output for rows saved before that.
                'decision_content' => \Modules\Court\Application\Support\Purifier::clean((string) $decision->decision_content, 'cases'),
                'panel' => $panel,
                'panel_complete' => $decision->hasCompletePanel(),
            ],
            'reviews' => $decision->reviews->map(fn (DecisionReview $review): array => [
                'id' => (int) $review->id,
                'reviewer_name' => $review->reviewer?->name,
                'outcome' => $review->outcome ?: 'pending',
                'review_note' => $review->review_note ?: null,
                'updated_at' => $review->updated_at?->toIso8601String(),
                'is_owner' => $user?->id === $review->reviewer_id,
            ])->values()->all(),
            'templates' => \Modules\Court\Domain\Models\DecisionTemplate::orderBy('title')->get(['id', 'title'])
                ->map(fn (\Modules\Court\Domain\Models\DecisionTemplate $template): array => ['id' => (int) $template->id, 'title' => $template->title])
                ->all(),
            'statuses' => self::STATUS_OPTIONS,
            'can' => [
                // The middle judge edits a decision until it is published.
                'edit' => $isMiddleJudge && ! $isPublished,
                'changeStatus' => $canUpdate,
                'approve' => $user?->hasPermission('decision.approve') ?? false,
                'review' => $canUpdate,
            ],
        ]);
    }

    /**
     * The props of the create and edit forms: the cases to choose from, the
     * judges, the signed-in judge (always the second seat) and the values.
     */
    private function formProps($cases, ?Decision $decision, ?int $preselectedCaseId = null): array
    {
        $user = auth()->user();

        // Seats by storage index (1 = presiding judge), as normalizePanelJudges() stores them.
        $panel = is_array($decision?->panel_judges) ? $decision->panel_judges : [];
        $judges = collect(range(0, 2))
            ->map(fn (int $seat): ?int => ! empty($panel[$seat]['admin_user_id']) ? (int) $panel[$seat]['admin_user_id'] : null)
            ->all();
        // The session judge holds the middle seat on a new decision.
        $judges[1] ??= $user?->id;

        return [
            'decision' => $decision ? [
                'id' => (int) $decision->id,
                'name' => $decision->name,
                'case_id' => $decision->court_case_id,
                'case_file_number' => $decision->case_file_number,
                'applicant_full_name' => $decision->applicant_full_name,
                'respondent_full_name' => $decision->respondent_full_name,
                'decision_date' => $decision->decision_date?->toDateString(),
                'decision_content' => $decision->decision_content,
                'judges' => $judges,
            ] : null,
            'cases' => collect($cases)->map(fn (CourtCase $case): array => [
                'id' => (int) $case->id,
                'case_number' => $case->case_number,
                'title' => $case->title,
                'applicant' => $case->title ?: ($case->applicant?->full_name ?? ''),
                'respondent' => $case->respondent_name ?? '',
            ])->values()->all(),
            'judgeUsers' => $this->loadJudgeUsers()
                ->map(fn (User $judge): array => ['id' => (int) $judge->id, 'name' => $judge->name])->values()->all(),
            'currentUser' => ['id' => $user?->id, 'name' => $user?->name],
            'defaults' => [
                'decision_date' => now()->toDateString(),
                'case_id' => $preselectedCaseId,
                'judges' => $judges,
            ],
        ];
    }

    /**
     * Render the final decision output for both applicant and respondent
     * using a chosen decision template, as a downloadable/streamed PDF.
     */
    public function output(Request $request, Decision $decision)
    {
        $decision->loadMissing('courtCase');
        abort_unless($this->canAccessDecision($decision), 403);

        $validated = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:court_decision_templates,id'],
            'mode'        => ['nullable', 'in:stream,download'],
        ]);

        $template = ! empty($validated['template_id'])
            ? \Modules\Court\Domain\Models\DecisionTemplate::find($validated['template_id'])
            : null;

        $pdf = \Modules\Court\Application\Support\DecisionPdf::render($decision, $template);
        $filename = \Modules\Court\Application\Support\DecisionPdf::filename($decision);

        return ($validated['mode'] ?? 'stream') === 'download'
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /**
     * Approve a decision (the gate to publishing). Stamps the seal on the PDF
     * and is a prerequisite for publishing and party downloads.
     */
    public function approve(Decision $decision)
    {
        $decision->loadMissing('courtCase');
        abort_unless($this->canAccessDecision($decision), 403);

        // A decision can only be approved after it has been published.
        if (! $decision->isPublished()) {
            return back()->withErrors(['approve' => __('court::decisions.approve_requires_published')]);
        }

        if (! $decision->hasCompletePanel()) {
            return back()->withErrors(['approve' => __('court::decisions.validation.publish_requires_panel')]);
        }

        if ($decision->isApproved()) {
            return redirect()->route('court.decisions.show', $decision);
        }

        $decision->update([
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return redirect()->route('court.decisions.show', $decision)->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.decision')],
        ]);
    }

    /**
     * Replace {{placeholder}} tokens in a template body with decision values.
     */
    private function fillTemplatePlaceholders(string $body, Decision $decision): string
    {
        $panel = is_array($decision->panel_judges) ? array_values($decision->panel_judges) : [];

        $judgeName = static function (array $panel, int $index): string {
            return (string) ($panel[$index]['admin_user_name'] ?? '');
        };
        $judgeVote = static function (array $panel, int $index): string {
            return (string) ($panel[$index]['vote'] ?? '');
        };

        $replacements = [
            'applicant_name'     => (string) ($decision->applicant_full_name ?? ''),
            'respondent_name'    => (string) ($decision->respondent_full_name ?? ''),
            'case_number'        => (string) ($decision->case_number ?? ''),
            'case_file_number'   => (string) ($decision->case_file_number ?? ''),
            'judge_name'         => (string) ($decision->courtCase?->judge?->name ?? ''),
            'decision_date'      => \Modules\Court\Application\Support\EthiopianDate::format($decision->decision_date, fallback: ''),
            'decision_content'   => (string) ($decision->decision_content ?? ''),
            'decision_name'      => (string) ($decision->name ?? ''),

            // Panel judges (1 = presiding/first, 2 = middle, 3 = third)
            'judge_one'          => $judgeName($panel, 0),
            'judge_two'          => $judgeName($panel, 1),
            'judge_three'        => $judgeName($panel, 2),
            'judge_one_vote'     => $judgeVote($panel, 0),
            'judge_two_vote'     => $judgeVote($panel, 1),
            'judge_three_vote'   => $judgeVote($panel, 2),

            // Signature block for all panel judges (HTML)
            'judges_signatures'  => $this->buildJudgesSignatures($panel),
        ];

        foreach ($replacements as $key => $value) {
            // Allow optional spaces inside the braces: {{ key }} or {{key}}
            $body = preg_replace('/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/', $value, $body);
        }

        return $body;
    }

    /**
     * Build an HTML signature block for the panel judges, suitable for the PDF.
     */
    private function buildJudgesSignatures(array $panel): string
    {
        $labels = [__('court::decisions.judges.judge', ['number' => 1]), __('court::decisions.judges.judge', ['number' => 2]), __('court::decisions.judges.judge', ['number' => 3])];

        $cells = '';
        for ($i = 0; $i < 3; $i++) {
            $name = trim((string) ($panel[$i]['admin_user_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $cells .= '<td style="width:33%;text-align:center;padding:8px;vertical-align:bottom;">'
                . '<div style="border-top:1px solid #111827;margin-top:36px;padding-top:4px;">'
                . '<div style="font-size:9pt;color:#6b7280;">' . e($labels[$i]) . '</div>'
                . '<div style="font-weight:600;">' . e($name) . '</div>'
                . '</div></td>';
        }

        if ($cells === '') {
            return '';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-top:18px;"><tr>' . $cells . '</tr></table>';
    }

    public function store(StoreDecisionRequest $request)
    {
        $payload = $this->preparePayload($request->validated());
        $case = CourtCase::with('applicant')
            ->where('status', 'closed')
            ->findOrFail($payload['case_id']);
        $data = $this->hydrateCaseData($payload, $case);

        // New decisions always start as draft; status is changed later from the show page.
        $data['status'] = 'draft';

        Decision::create($data);

        return redirect()->route('court.decisions.index')->with('success', [
            'key' => 'court::messages.success.created',
            'replace' => ['resource' => __('court::messages.resources.decision')],
        ]);
    }

    public function edit(Decision $decision)
    {
        $this->ensureMiddleJudge($decision);
        $this->ensureNotPublished($decision);

        return Inertia::render('Court/Decisions/Edit', $this->formProps($this->loadCases($decision), $decision));
    }

    public function update(Request $request, Decision $decision)
    {
        $this->ensureMiddleJudge($decision);
        $this->ensureNotPublished($decision);
        $payload = $this->preparePayload(
            $request->validate($this->rules(), StoreDecisionRequest::panelMessages())
        );
        $case = CourtCase::with('applicant')->findOrFail($payload['case_id']);
        $decision->update($this->hydrateCaseData($payload, $case));

        return redirect()->route('court.decisions.index')->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.decision')],
        ]);
    }

    /**
     * Change only the status of a decision (from the show page).
     */
    public function updateStatus(Request $request, Decision $decision)
    {
        $decision->loadMissing('courtCase');
        abort_unless($this->canAccessDecision($decision), 403);

        // Once published, the status is final and can no longer be changed.
        $this->ensureNotPublished($decision);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:32', Rule::in(self::STATUS_OPTIONS)],
        ]);

        if ($data['status'] === 'published' && ! $decision->hasCompletePanel()) {
            return back()->withErrors(['status' => __('court::decisions.validation.publish_requires_panel')]);
        }

        $decision->update(['status' => $data['status']]);

        return redirect()->route('court.decisions.show', $decision)->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.decision')],
        ]);
    }

    /**
     * Validation rules for a decision review.
     *
     * Outcomes (stored value => meaning):
     *   approve => Agree        (note optional)
     *   reject  => Have difference (note required: state the difference)
     *   improve => Improvement   (note required: the part to improve)
     */
    private function reviewRules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['approve', 'reject', 'improve'])],
            'review_note' => ['nullable', 'string', 'max:2000', 'required_if:outcome,reject', 'required_if:outcome,improve'],
        ];
    }

    private function reviewMessages(): array
    {
        return [
            'review_note.required_if' => __('court::decisions.reviews.note_required'),
        ];
    }

    public function storeReview(Request $request, Decision $decision)
    {
        $this->ensureReviewable($decision);
        $data = $request->validate(
            $this->reviewRules(),
            $this->reviewMessages()
        );

        DecisionReview::create([
            'decision_id' => $decision->id,
            'case_number' => $decision->case_number,
            'reviewer_id' => auth()->id(),
            'review_note' => $data['review_note'] ?? null,
            'outcome' => $data['outcome'],
        ]);

        return back()->with('success', [
            'key' => 'court::messages.success.submitted',
            'replace' => ['resource' => __('court::messages.resources.review')],
        ]);
    }

    public function updateReview(Request $request, Decision $decision, DecisionReview $review)
    {
        $this->ensureReviewable($decision);
        abort_if($review->decision_id !== $decision->id, 404);
        abort_if(auth()->id() !== $review->reviewer_id, 403);

        $data = $request->validate(
            $this->reviewRules(),
            $this->reviewMessages()
        );

        $review->update([
            'outcome' => $data['outcome'],
            'review_note' => $data['review_note'] ?? null,
        ]);

        return redirect()->route('court.decisions.show', $decision)->with('success', [
            'key' => 'court::messages.success.updated',
            'replace' => ['resource' => __('court::messages.resources.review')],
        ]);
    }

    public function destroyReview(Decision $decision, DecisionReview $review)
    {
        $this->ensureReviewable($decision);
        abort_if($review->decision_id !== $decision->id, 404);
        abort_if(auth()->id() !== $review->reviewer_id, 403);

        $review->delete();

        return back()->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.review')],
        ]);
    }

    public function editReview(Decision $decision, DecisionReview $review)
    {
        $decision->loadMissing('courtCase');
        abort_unless($this->canAccessDecision($decision), 403);
        abort_if($review->decision_id !== $decision->id, 404);
        abort_if(auth()->id() !== $review->reviewer_id, 403);
        $review->loadMissing('reviewer');

        return Inertia::render('Court/Decisions/EditReview', [
            'decision' => ['id' => (int) $decision->id, 'case_number' => $decision->case_number],
            'review' => [
                'id' => (int) $review->id,
                'outcome' => $review->outcome,
                'review_note' => $review->review_note ?: '',
                'reviewer_name' => $review->reviewer?->name,
            ],
        ]);
    }

    public function destroy(Decision $decision)
    {
        $this->ensureMiddleJudge($decision);
        $this->ensureNotPublished($decision);
        $decision->delete();
        return back()->with('success', [
            'key' => 'court::messages.success.deleted',
            'replace' => ['resource' => __('court::messages.resources.decision')],
        ]);
    }

    private function rules(): array
    {
        return [
            'case_id' => ['required', 'exists:court_cases,id'],
            'case_file_number' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'decision_content' => ['required', 'string'],
            'decision_date' => ['required', 'date'],
            'reviewing_admin_user_names' => ['nullable', 'array'],
            'reviewing_admin_user_names.*' => ['string', 'max:255'],
            'judges_comments' => ['nullable', 'string', 'max:5000'],
            ...StoreDecisionRequest::panelRules(),
        ];
    }

    private function preparePayload(array $payload): array
    {
        $payload['name'] = trim((string) ($payload['name'] ?? ''));
        $payload['case_file_number'] = trim((string) ($payload['case_file_number'] ?? ''));
        $payload['description'] = isset($payload['description']) ? strip_tags(trim($payload['description'])) : null;
        $payload['decision_content'] = \Modules\Court\Application\Support\Purifier::clean(trim($payload['decision_content'] ?? ''), 'cases');
        $payload['reviewing_admin_user_names'] = $payload['reviewing_admin_user_names'] ?? [];
        $payload['judges'] = $payload['judges'] ?? [];

        if ($payload['name'] === '') {
            $payload['name'] = $payload['case_file_number'] !== '' ? $payload['case_file_number'] : 'Decision';
        }

        return $payload;
    }

    private function hydrateCaseData(array $payload, CourtCase $case): array
    {
        $panelJudges = $this->normalizePanelJudges($payload['judges']);

        $reviewerName = auth()->user()?->name ?? 'Admin';
        $reviewers = $this->normalizeReviewerNames($payload['reviewing_admin_user_names'] ?? []);
        if (empty($reviewers)) {
            $reviewers = [$reviewerName];
        }

        return array_merge($payload, [
            'court_case_id' => $case->id,
            'case_number' => $case->case_number,
            'case_file_number' => $payload['case_file_number'] ?: $case->case_number,
            // The applicant name is stored on the case as `title`.
            'applicant_full_name' => trim((string) ($case->title ?: ($case->applicant?->full_name ?? ''))),
            'respondent_full_name' => $case->respondent_name,
            'case_filed_date' => $case->filing_date?->toDateString() ?? $case->created_at?->toDateString(),
            'panel_judges' => $panelJudges,
            'panel_decision' => 'pending',
            'reviewing_admin_user_id' => auth()->id(),
            'reviewing_admin_user_name' => $reviewers[0] ?? $reviewerName,
            'reviewing_admin_user_names' => $reviewers,
            'judges_comments' => $payload['judges_comments'] ?? null,
        ]);
    }

    private function normalizeReviewerNames(array|string|null $raw): array
    {
        if (is_array($raw)) {
            return collect($raw)
                ->map(fn($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (trim($raw ?? '') === '') {
            return [];
        }

        return collect(preg_split('/[\r\n,]+/', $raw))
            ->map(fn($value) => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizePanelJudges(array $panel): array
    {
        // Seats are addressed by storage index (1 = presiding judge). Read them
        // by key rather than array order: validated() may rebuild the array in
        // rule order, and re-indexing that would silently reassign the seats.
        $ordered = collect(range(0, 2))->map(fn (int $seat): array => (array) ($panel[$seat] ?? []));
        $ids = $ordered->pluck('admin_user_id')->filter()->unique();
        $namesById = User::whereIn('id', $ids)->pluck('name', 'id');

        return $ordered->map(function ($row, $index) use ($namesById) {
            $id = (int) Arr::get($row, 'admin_user_id');
            return [
                'order' => $index + 1,
                'admin_user_id' => $id ?: null,
                'admin_user_name' => $id && isset($namesById[$id]) ? $namesById[$id] : null,
                'vote' => null,
            ];
        })->all();
    }

    private function ensureMiddleJudge(Decision $decision): void
    {
        $middleJudgeId = $decision->presidingJudgeId();

        // Drafts saved before a panel was mandatory have no presiding judge,
        // which would leave them permanently uneditable. Let a user who can
        // access the decision open it; saving requires a complete panel with
        // that user in the presiding seat (see StoreDecisionRequest::panelRules).
        if ($middleJudgeId === null) {
            $decision->loadMissing('courtCase');
            abort_unless($this->canAccessDecision($decision), 403);

            return;
        }

        if ($middleJudgeId !== (int) auth()->id()) {
            abort(403, 'Only the middle judge can modify this decision.');
        }
    }

    private function ensureReviewable(Decision $decision): void
    {
        $decision->loadMissing('courtCase');
        abort_unless($this->canAccessDecision($decision), 403);

        if ($decision->status === 'published') {
            abort(403, 'Reviews are locked for published decisions.');
        }
    }

    /**
     * Block any modification once a decision has been published.
     */
    private function ensureNotPublished(Decision $decision): void
    {
        if ($decision->status === 'published') {
            abort(403, 'This decision is published and can no longer be changed.');
        }
    }

    private function loadCases(?Decision $decision = null, ?string $status = null)
    {
        $cases = CourtCase::with('applicant')
            ->orderByDesc('filing_date')
            ->limit(self::CASE_LIMIT)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->get(['id', 'case_number', 'title', 'filing_date', 'applicant_id', 'respondent_name', 'status']);

        if ($decision && $decision->court_case_id && !$cases->contains('id', $decision->court_case_id)) {
            $current = CourtCase::find($decision->court_case_id, ['id', 'case_number', 'title', 'filing_date', 'status']);
            if ($current) {
                $cases->push($current);
            }
        }

        return $cases;
    }

    private function loadJudgeUsers()
    {
        $user = auth()->user();
        $teamIds = $user?->teams()->pluck('court_teams.id') ?? collect();

        $query = User::query()->orderBy('name');

        if ($teamIds->isNotEmpty()) {
            $query->whereHas('teams', fn($q) => $q->whereIn('court_teams.id', $teamIds));
        }

        $judges = $query->get(['id', 'name']);

        // Ensure current user is present
        if ($user && !$judges->contains('id', $user->id)) {
            $judges->push($user);
        }

        return $judges->unique('id')->sortBy('name')->values();
    }
}
