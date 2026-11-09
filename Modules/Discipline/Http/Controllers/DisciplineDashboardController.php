<?php

declare(strict_types=1);

namespace Modules\Discipline\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Discipline\Application\Services\DisciplinaryCaseAccess;
use Modules\Discipline\Application\Services\DisciplinaryCasePresenter;
use Modules\Discipline\Contracts\Data\DisciplinaryCaseStatus;
use Modules\Discipline\Domain\Models\DisciplinaryCase;

/**
 * Discipline Management's home page: the open cases the viewer may see, where
 * they stand, which statutory deadlines (Regulation 201/2018) are close or
 * missed, suspensions in force and appeals awaiting the court. Read-only;
 * every figure goes through DisciplinaryCaseAccess::constrainList.
 */
class DisciplineDashboardController extends Controller
{
    /** A statutory deadline this close (calendar days) is "due soon". */
    public const DUE_SOON_DAYS = 3;

    /** Open stages shown as bars, in the order a case moves through them. */
    private const STAGES = ['draft', 'charged', 'under_investigation', 'report_submitted', 'decided', 'decision_delivered'];

    public function __construct(
        private readonly DisciplinaryCaseAccess $access,
        private readonly DisciplinaryCasePresenter $presenter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $this->access->authorize($user->can('discipline.view') || $user->can('discipline.investigate') || $user->can('discipline.decide'));
        $locale = app()->getLocale();
        $now = now();
        $soon = $now->copy()->addDays(self::DUE_SOON_DAYS);

        $visible = fn (): Builder => $this->access->constrainList(DisciplinaryCase::query(), $user);
        $open = fn (): Builder => $visible()->whereNotIn('status', [DisciplinaryCaseStatus::Closed->value, DisciplinaryCaseStatus::Discontinued->value, DisciplinaryCaseStatus::Terminated->value]);
        $openDeadline = fn ($q) => $q->whereNull('completed_at')->whereNull('superseded_at');
        $overdue = fn ($q) => $openDeadline($q)->where('due_at', '<', $now);
        $dueSoon = fn ($q) => $openDeadline($q)->whereBetween('due_at', [$now, $soon]);

        $byStatus = $open()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        // Open cases ordered by their nearest open statutory deadline; cases without one last.
        $byDeadline = fn (): Builder => $open()->select('disciplinary_cases.*')
            ->addSelect(['next_due' => DB::table('statutory_deadlines')->select('due_at')
                ->whereColumn('subject_id', 'disciplinary_cases.id')->where('subject_type', (new DisciplinaryCase)->getMorphClass())
                ->whereNull('completed_at')->whereNull('superseded_at')->orderBy('due_at')->limit(1)])
            ->orderByRaw('next_due is null')->orderBy('next_due')
            ->with(['accused', 'organization']);
        $row = fn (DisciplinaryCase $case): array => $this->presenter->row($case, $locale);

        return Inertia::render('Discipline/Dashboard', [
            'figures' => [
                'open' => (int) $byStatus->sum(),
                'due_soon' => $open()->whereHas('deadlines', $dueSoon)->whereDoesntHave('deadlines', $overdue)->count(),
                'overdue' => $open()->whereHas('deadlines', $overdue)->count(),
                'suspended' => $open()->whereHas('suspensions', fn ($q) => $q->whereNull('lifted_on')->whereDate('starts_on', '<=', $now)
                    ->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>=', $now)))->count(),
                'appeals' => $visible()->whereHas('appeals', fn ($q) => $q->whereNull('outcome'))->count(),
                'decided_30' => $visible()->whereHas('decisions', fn ($q) => $q->where('decided_at', '>=', $now->copy()->subDays(30)))->count(),
            ],
            'stages' => collect(self::STAGES)->mapWithKeys(fn (string $s) => [$s => (int) ($byStatus[$s] ?? 0)])->all(),
            'attention' => $byDeadline()->whereHas('deadlines', fn ($q) => $openDeadline($q)->where('due_at', '<=', $soon))->limit(8)->get()->map($row)->values(),
            'queue' => $byDeadline()->limit(10)->get()->map($row)->values(),
            'dueSoonDays' => self::DUE_SOON_DAYS,
            'can' => [
                'create' => $user->can('discipline.create'),
                'committees' => $user->can('discipline.view'),
            ],
        ]);
    }
}
