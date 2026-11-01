<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\BenchNote;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Court\Application\Support\Purifier;

/**
 * Routes are gated by perm:bench-notes.manage; each action additionally needs
 * its own bench-notes.{view,create,update,delete} permission, which the pages
 * only enforced by hiding buttons. Only the author or an administrator may
 * edit or delete a note.
 */
class BenchNoteController extends Controller
{
    /**
     * The bench notes, newest first, optionally one case's (React, Court/BenchNotes/Index).
     */
    public function index(Request $request): Response
    {
        $this->authorizeAction('view');

        $caseId = $request->integer('case_id') ?: null;

        $benchNotes = BenchNote::query()
            ->with([
                'case:id,case_number,title',
                'user:id,name',
                'judgeOne:id,name,signature_path',
                'judgeTwo:id,name,signature_path',
                'judgeThree:id,name,signature_path',
            ])
            ->when($caseId, fn ($q) => $q->where('case_id', $caseId))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (BenchNote $note): array => $this->noteRow($note));

        return Inertia::render('Court/BenchNotes/Index', [
            'notes' => $benchNotes,
            'cases' => $this->caseOptions(),
            'filters' => ['case_id' => $caseId ? (string) $caseId : ''],
            'can' => $this->permissions(),
        ]);
    }

    /**
     * The new-note form (React, Court/BenchNotes/Form with a null note).
     */
    public function create(Request $request): Response
    {
        $this->authorizeAction('create');

        $caseId = $request->integer('case_id') ?: null;

        return Inertia::render('Court/BenchNotes/Form', $this->formProps(null, $caseId));
    }

    public function store(Request $request)
    {
        $this->authorizeAction('create');

        $data = $request->validate([
            'case_id' => ['required', 'integer', 'exists:court_cases,id'],
            'title' => ['required', 'string', 'max:255'],
            'note_date' => ['nullable', 'date'],
            'note' => ['required', 'string'],
            'judges' => ['nullable', 'array', 'size:3'],
            'judges.*.admin_user_id' => ['nullable', 'integer', 'exists:court_staff,id'],
        ]);

        $cleanNote = $this->sanitizeNote($data['note']);

        $panelJudges = $this->normalizePanelJudges($data['judges'] ?? []);

        $payload = [
            'case_id' => $data['case_id'],
            'user_id' => $request->user()->id,
            'note' => $cleanNote,
            'hearing_id' => null,
        ];
        if (Schema::hasColumn('court_bench_notes', 'title')) {
            $payload['title'] = trim($data['title']);
        }
        if (Schema::hasColumn('court_bench_notes', 'note_date')) {
            $payload['note_date'] = $data['note_date'] ?? null;
        }
        if (Schema::hasColumn('court_bench_notes', 'judge_one_id')) {
            $payload['judge_one_id'] = $panelJudges[0] ?? null;
        }
        if (Schema::hasColumn('court_bench_notes', 'judge_two_id')) {
            $payload['judge_two_id'] = $panelJudges[1] ?? null;
        }
        if (Schema::hasColumn('court_bench_notes', 'judge_three_id')) {
            $payload['judge_three_id'] = $panelJudges[2] ?? null;
        }

        $benchNote = BenchNote::create($payload);

        return redirect()
            ->route('court.bench-notes.index', ['case_id' => $benchNote->case_id])
            ->with('success', [
                'key' => 'court::messages.success.created',
                'replace' => ['resource' => __('court::messages.resources.bench_note')],
            ]);
    }

    /**
     * One note as it reads on the bench: the panel, the content, the first judge's signature (React, Court/BenchNotes/Show).
     */
    public function show(BenchNote $benchNote): Response
    {
        $this->authorizeAction('view');

        $benchNote->load([
            'case:id,case_number,title',
            'user:id,name',
            'judgeOne:id,name,signature_path',
            'judgeTwo:id,name,signature_path',
            'judgeThree:id,name,signature_path',
        ]);

        return Inertia::render('Court/BenchNotes/Show', [
            'note' => $this->noteRow($benchNote),
            'can' => $this->permissions(),
        ]);
    }

    /**
     * The edit form (React, Court/BenchNotes/Form with the note).
     */
    public function edit(BenchNote $benchNote): Response
    {
        $this->authorizeAction('update');
        $this->authorizeOwnership($benchNote);

        $benchNote->load([
            'case:id,case_number,title',
            'user:id,name',
            'judgeOne:id,name,signature_path',
            'judgeTwo:id,name,signature_path',
            'judgeThree:id,name,signature_path',
        ]);

        return Inertia::render('Court/BenchNotes/Form', $this->formProps($benchNote, null));
    }

    public function update(Request $request, BenchNote $benchNote)
    {
        $this->authorizeAction('update');
        $this->authorizeOwnership($benchNote);

        $data = $request->validate([
            'case_id' => ['required', 'integer', 'exists:court_cases,id'],
            'title' => ['required', 'string', 'max:255'],
            'note_date' => ['nullable', 'date'],
            'note' => ['required', 'string'],
            'judges' => ['nullable', 'array', 'size:3'],
            'judges.*.admin_user_id' => ['nullable', 'integer', 'exists:court_staff,id'],
        ]);

        $cleanNote = $this->sanitizeNote($data['note']);

        $panelJudges = $request->has('judges')
            ? $this->normalizePanelJudges($data['judges'] ?? [])
            : null;

        $updatePayload = [
            'case_id' => $data['case_id'],
            'note' => $cleanNote,
        ];
        if (Schema::hasColumn('court_bench_notes', 'title')) {
            $updatePayload['title'] = trim($data['title']);
        }
        if (Schema::hasColumn('court_bench_notes', 'note_date')) {
            $updatePayload['note_date'] = $data['note_date'] ?? null;
        }

        if ($panelJudges !== null) {
            if (Schema::hasColumn('court_bench_notes', 'judge_one_id')) {
                $updatePayload['judge_one_id'] = $panelJudges[0] ?? null;
            }
            if (Schema::hasColumn('court_bench_notes', 'judge_two_id')) {
                $updatePayload['judge_two_id'] = $panelJudges[1] ?? null;
            }
            if (Schema::hasColumn('court_bench_notes', 'judge_three_id')) {
                $updatePayload['judge_three_id'] = $panelJudges[2] ?? null;
            }
        }

        $benchNote->update($updatePayload);

        return redirect()
            ->route('court.bench-notes.index', ['case_id' => $benchNote->case_id])
            ->with('success', [
                'key' => 'court::messages.success.updated',
                'replace' => ['resource' => __('court::messages.resources.bench_note')],
            ]);
    }

    public function destroy(BenchNote $benchNote)
    {
        $this->authorizeAction('delete');
        $this->authorizeOwnership($benchNote);

        $caseId = $benchNote->case_id;
        $benchNote->delete();

        return redirect()
            ->route('court.bench-notes.index', ['case_id' => $caseId])
            ->with('success', [
                'key' => 'court::messages.success.deleted',
                'replace' => ['resource' => __('court::messages.resources.bench_note')],
            ]);
    }

    /**
     * One note as the list, the show page and the form read it. The content is
     * cleaned again on the way out, as the Blade pages did with `\Modules\Court\Application\Support\Purifier::clean()`.
     */
    private function noteRow(BenchNote $note): array
    {
        $judge = fn (?User $user): ?array => $user ? [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'signature_url' => $user->signature_url,
        ] : null;

        $title = Schema::hasColumn('court_bench_notes', 'title') ? $note->title : null;
        $noteDate = Schema::hasColumn('court_bench_notes', 'note_date') ? $note->note_date : null;

        return [
            'id' => (int) $note->id,
            'case_id' => (int) $note->case_id,
            'case_number' => $note->case?->case_number,
            'case_title' => $note->case?->title,
            'title' => $title !== null && $title !== '' ? strip_tags(\Modules\Court\Application\Support\Purifier::clean((string) $title, 'cases')) : null,
            'note_date' => $noteDate ? $noteDate->toDateString() : null,
            'note' => $note->note ? \Modules\Court\Application\Support\Purifier::clean((string) $note->note, 'default') : '',
            'author_name' => $note->user?->name,
            // The author or an administrator; the edit and delete buttons follow it.
            'mine' => $this->mayModify($note),
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at && $note->updated_at->ne($note->created_at) ? $note->updated_at->toIso8601String() : null,
            'judges' => [$judge($note->judgeOne), $judge($note->judgeTwo), $judge($note->judgeThree)],
        ];
    }

    /** The props of the create and edit form: the note (null when new), the choices, the judge lists and the prefill. */
    private function formProps(?BenchNote $note, ?int $prefillCaseId): array
    {
        $user = auth()->user();
        $judgeUsers = $this->loadJudgeUsers();

        // The Blade form offered the third seat only the judges of the viewer's own team, when the viewer has one.
        $currentTeamId = $user?->team_id ?? null;
        $teamJudgeUsers = $currentTeamId
            ? $judgeUsers->filter(fn ($admin) => ($admin->team_id ?? null) === $currentTeamId)->values()
            : $judgeUsers;

        $toOption = fn ($admin): array => ['id' => (int) $admin->id, 'name' => (string) $admin->name];

        return [
            'note' => $note ? $this->noteRow($note) : null,
            'cases' => $this->caseOptions(),
            'judgeUsers' => $judgeUsers->map($toOption)->values()->all(),
            'thirdJudgeUsers' => ($teamJudgeUsers->isNotEmpty() ? $teamJudgeUsers : $judgeUsers)->map($toOption)->values()->all(),
            'noTeammates' => $currentTeamId !== null && $teamJudgeUsers->isEmpty(),
            'currentUser' => $user ? ['id' => (int) $user->id, 'name' => (string) $user->name] : null,
            'prefill' => ['case_id' => $prefillCaseId ? (string) $prefillCaseId : ''],
        ];
    }

    private function authorizeAction(string $action): void
    {
        abort_unless(\Modules\Court\Application\Support\CourtPermissions::check('bench-notes.'.$action), 403);
    }

    /** A judge's notes are their own: only the author or an administrator may change or delete one. */
    private function authorizeOwnership(BenchNote $note): void
    {
        abort_unless($this->mayModify($note), 403);
    }

    private function mayModify(BenchNote $note): bool
    {
        $user = Auth::user();

        return $user !== null && ((int) $note->user_id === (int) $user->id || $user->hasRole('admin'));
    }

    private function permissions(): array
    {
        $user = auth()->user();

        return [
            'view' => $user?->hasPermission('bench-notes.view') ?? true,
            'create' => $user?->hasPermission('bench-notes.create') ?? false,
            'update' => $user?->hasPermission('bench-notes.update') ?? false,
            'delete' => $user?->hasPermission('bench-notes.delete') ?? false,
        ];
    }

    private function caseOptions(): array
    {
        return CourtCase::query()
            ->select('id', 'case_number', 'title')
            ->orderBy('case_number')
            ->limit(300)
            ->get()
            ->map(fn (CourtCase $case): array => ['id' => (int) $case->id, 'case_number' => (string) $case->case_number, 'title' => $case->title])
            ->all();
    }

    private function sanitizeNote(?string $html): string
    {
        $s = (string) ($html ?? '');
        if ($s === '') {
            return '';
        }
        if (str_contains($s, '&lt;') || str_contains($s, '&gt;')) {
            $s = htmlspecialchars_decode($s, ENT_QUOTES);
        }

        return \Modules\Court\Application\Support\Purifier::clean($s, 'default');
    }

    private function loadJudgeUsers()
    {
        $user = auth()->user();
        $teamIds = $user ? $user->teams()->pluck('court_teams.id') : collect();

        $query = User::query()->orderBy('name');
        if ($teamIds->isNotEmpty()) {
            $query->whereHas('teams', fn ($q) => $q->whereIn('court_teams.id', $teamIds));
        }

        $judgeUsers = $query->get(['id', 'name']);
        if ($user && ! $judgeUsers->contains('id', $user->id)) {
            $judgeUsers->push($user);
        }

        return $judgeUsers->unique('id')->sortBy('name')->values();
    }

    private function normalizePanelJudges(array $panel): array
    {
        $ordered = collect($panel)->pad(3, [])->take(3)->values();

        return $ordered->map(function ($row) {
            $id = Arr::get($row, 'admin_user_id');
            if ($id === null || $id === '') {
                return null;
            }

            return (int) $id;
        })->all();
    }
}
