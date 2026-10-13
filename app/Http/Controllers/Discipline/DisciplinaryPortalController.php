<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discipline;

use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\Discipline\DisciplinaryObjectionGround;
use App\Http\Controllers\Controller;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryEvidence;
use App\Services\Discipline\DisciplinaryCaseAccess;
use App\Services\Discipline\DisciplinaryCasePresenter;
use App\Services\Discipline\DisciplinaryCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My Portal → My Disciplinary Cases: the accused employee's own cases only.
 * Drafts are not shown (nothing has been charged yet), and the committee's
 * internal deliberation is never included (DisciplinaryCasePresenter).
 */
class DisciplinaryPortalController extends Controller
{
    public function __construct(
        private readonly DisciplinaryCaseService $cases,
        private readonly DisciplinaryCaseAccess $access,
        private readonly DisciplinaryCasePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->access->authorize($user->can('discipline.view_own') && $user->employee_id !== null);
        $locale = app()->getLocale();

        $cases = DisciplinaryCase::query()
            ->where('accused_employee_id', $user->employee_id)
            ->where('status', '!=', DisciplinaryCaseStatus::Draft->value)
            ->with(['accused', 'organization'])
            ->latest('charged_at')
            ->get()
            ->map(fn (DisciplinaryCase $c): array => $this->presenter->row($c, $locale));

        return Inertia::render('Discipline/Portal/Index', ['cases' => $cases]);
    }

    public function show(Request $request, DisciplinaryCase $case): Response
    {
        $this->own($request, $case);

        return Inertia::render('Discipline/Portal/Show', [
            'disciplinaryCase' => $this->presenter->detail($case, $request->user(), app()->getLocale()),
            'abilities' => $this->presenter->abilities($case, $request->user()),
            'objectionGrounds' => array_column(DisciplinaryObjectionGround::cases(), 'value'),
        ]);
    }

    public function respond(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->own($request, $case);
        $this->cases->recordResponse($case, $request->user(), DisciplinaryCaseController::validatedResponse($request));

        return back()->with('success', __('discipline.flash.response_recorded'));
    }

    public function evidence(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->own($request, $case);
        $request->merge(['side' => 'accused']);
        $this->cases->addEvidence($case, $request->user(), DisciplinaryCaseController::validatedEvidence($request), $request->file('file'));

        return back()->with('success', __('discipline.flash.evidence_added'));
    }

    public function finalStatement(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $this->own($request, $case);
        $data = $request->validate(['statement' => ['nullable', 'string', 'max:10000'], 'declined' => ['boolean']]);
        $this->cases->recordFinalStatement($case, $request->user(), $data['statement'] ?? null, (bool) ($data['declined'] ?? false));

        return back()->with('success', __('discipline.flash.final_statement'));
    }

    public function downloadEvidence(Request $request, DisciplinaryCase $case, DisciplinaryEvidence $evidence): StreamedResponse
    {
        $this->own($request, $case);
        [$disk, $path, $name, $mime] = $this->cases->evidenceDownload($case, $evidence, $request->user());

        return Storage::disk($disk)->download($path, $name, ['Content-Type' => $mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Only the accused's own, charged case; anything else is a plain 404 (no IDOR signal). */
    private function own(Request $request, DisciplinaryCase $case): void
    {
        $user = $request->user();
        if (! $this->access->isAccused($user, $case) || $case->status === DisciplinaryCaseStatus::Draft || ! $user->can('discipline.view_own')) {
            abort(404);
        }
    }
}
