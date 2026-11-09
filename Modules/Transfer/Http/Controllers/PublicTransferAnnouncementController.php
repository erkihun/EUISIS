<?php

declare(strict_types=1);

namespace Modules\Transfer\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transfer\Application\Actions\SubmitTransferApplicationAction;
use Modules\Transfer\Application\Services\TransferAnnouncementPresenter;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferSetting;
use Modules\Transfer\Http\Requests\PublicStoreTransferApplicationRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicTransferAnnouncementController extends Controller
{
    /**
     * All published transfer announcements, open ones first.
     *
     * Paginated: the list used to load every published row in one query,
     * which grows without bound on a public, anonymous page.
     */
    public function index(): Response
    {
        $announcements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published)
            ->whereDate('closing_date', '>=', today())
            ->with(['organization', 'position'])
            ->orderByDesc('closing_date')
            ->orderByDesc('published_at')
            ->paginate(15)
            ->through(fn (TransferAnnouncement $a): array => self::presentSummary($a));

        return Inertia::render('Public/TransferAnnouncements', [
            'announcements' => $announcements,
        ]);
    }

    /**
     * The public summary of a transfer announcement (TransferAnnouncementPresenter).
     *
     * @return array<string, mixed>
     */
    public static function presentSummary(TransferAnnouncement $a): array
    {
        return TransferAnnouncementPresenter::summary($a);
    }

    public function show(TransferAnnouncement $announcement): Response
    {
        if ($announcement->status !== TransferAnnouncementStatus::Published || ($announcement->closing_date?->isPast() ?? true)) {
            throw new NotFoundHttpException;
        }

        $announcement->loadMissing(['organization', 'position']);

        $user = Auth::user();
        $alreadyApplied = false;

        if ($user !== null) {
            $employee = $user->employee;
            if ($employee !== null) {
                $alreadyApplied = TransferApplication::query()
                    ->where('announcement_id', $announcement->id)
                    ->where('employee_id', $employee->id)
                    ->whereNotIn('status', [
                        TransferApplicationStatus::Withdrawn->value,
                        TransferApplicationStatus::Cancelled->value,
                    ])
                    ->exists();
            }
        }

        return Inertia::render('Public/TransferAnnouncements/Show', [
            'announcement' => [
                'id' => $announcement->id,
                'organization_name_en' => $announcement->organization?->name_en,
                'organization_name_am' => $announcement->organization?->name_am,
                'position_title_en' => $announcement->position?->title_en,
                'position_title_am' => $announcement->position?->title_am,
                'grade_level' => $announcement->grade_level,
                'salary_min' => $announcement->salary_min,
                'salary_max' => $announcement->salary_max,
                'number_of_vacancies' => $announcement->totalVacancyCount(),
                'opening_date' => $announcement->opening_date?->toDateString(),
                'closing_date' => $announcement->closing_date?->toDateString(),
                'eligibility_rules' => $announcement->eligibility_rules,
                'required_documents' => self::requiredDocuments($announcement),
                'status' => $announcement->status->value,
                'is_open' => $announcement->isAcceptingApplications(),
                'published_at' => $announcement->published_at?->toDateString(),
            ],
            'already_applied' => $alreadyApplied,
            'is_authenticated' => $user !== null,
            'apply_url' => route('public.transfer-announcements.apply', $announcement),
            'login_url' => route('login').'?intended='.urlencode(route('public.transfer-announcements.apply', $announcement)),
        ]);
    }

    public function apply(TransferAnnouncement $announcement): Response|RedirectResponse
    {
        if (! $announcement->isAcceptingApplications()) {
            return redirect()->route('public.transfer-announcements.show', $announcement)
                ->with('flash', ['message' => __('transfers.notAcceptingApplications'), 'type' => 'error']);
        }

        $user = Auth::user();
        $employee = $user?->employee;

        if ($employee === null) {
            return redirect()->route('public.transfer-announcements.show', $announcement)
                ->with('flash', ['message' => __('transfers.noEmployeeProfile'), 'type' => 'error']);
        }

        $alreadyApplied = TransferApplication::query()
            ->where('announcement_id', $announcement->id)
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', [
                TransferApplicationStatus::Withdrawn->value,
                TransferApplicationStatus::Cancelled->value,
            ])
            ->exists();

        if ($alreadyApplied) {
            return redirect()->route('public.transfer-announcements.show', $announcement)
                ->with('flash', ['message' => __('transfers.alreadyApplied'), 'type' => 'error']);
        }

        $announcement->loadMissing(['organization', 'position', 'positions.position']);

        return Inertia::render('Public/TransferAnnouncements/Apply', [
            'announcement' => [
                'id' => $announcement->id,
                // An announcement can advertise several positions; the applicant names
                // one, or the application is refused ("Select one advertised destination").
                'positions' => $announcement->positions->map(fn ($line): array => [
                    'id' => $line->id,
                    'code' => $line->position?->job_position_code,
                    'title_en' => $line->position?->title_en,
                    'title_am' => $line->position?->title_am,
                ])->values(),
                'organization_name_en' => $announcement->organization?->name_en,
                'organization_name_am' => $announcement->organization?->name_am,
                'position_title_en' => $announcement->position?->title_en,
                'position_title_am' => $announcement->position?->title_am,
                'grade_level' => $announcement->grade_level,
                'closing_date' => $announcement->closing_date?->toDateString(),
                'required_documents' => self::requiredDocuments($announcement),
            ],
            'show_url' => route('public.transfer-announcements.show', $announcement),
        ]);
    }

    /**
     * Every document an applicant must attach: the transfer settings' list
     * first, then the announcement's own, trimmed and de-duplicated. This is
     * the exact order SubmitTransferApplicationAction reads uploads in, so the
     * apply page can offer one labelled slot per document.
     *
     * @return list<string>
     */
    public static function requiredDocuments(TransferAnnouncement $announcement): array
    {
        return collect([
            ...(array) TransferSetting::current()->required_documents,
            ...(array) $announcement->required_documents,
        ])->filter(fn (mixed $type): bool => is_string($type) && trim($type) !== '')
            ->map(fn (string $type): string => trim($type))->unique()->values()->all();
    }

    public function storeApply(
        PublicStoreTransferApplicationRequest $request,
        TransferAnnouncement $announcement,
        SubmitTransferApplicationAction $action,
    ): RedirectResponse {
        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return redirect()->route('public.transfer-announcements.show', $announcement)
                ->with('flash', ['message' => __('transfers.noEmployeeProfile'), 'type' => 'error']);
        }

        try {
            $action->execute(
                $announcement,
                $employee,
                $user,
                [
                    'announcement_position_id' => $request->input('announcement_position_id'),
                    'cover_letter' => $request->input('cover_letter'),
                    'documents' => $request->file('documents') ?? [],
                    'reuse_document_ids' => $request->input('reuse_document_ids') ?? [],
                ],
            );
        } catch (DomainException $e) {
            return back()->withErrors(['application' => $e->getMessage()]);
        }

        return redirect()
            ->route('public.transfer-announcements.show', $announcement)
            ->with('flash', ['message' => __('transfers.applicationSubmitted'), 'type' => 'success']);
    }
}
