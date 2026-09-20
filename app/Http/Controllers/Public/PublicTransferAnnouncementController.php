<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Transfers\SubmitTransferApplicationAction;
use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\PublicStoreTransferApplicationRequest;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
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
     * The public summary of a transfer announcement. Shared with the combined
     * announcements page so both show the same fields and nothing more.
     *
     * @return array<string, mixed>
     */
    public static function presentSummary(TransferAnnouncement $a): array
    {
        return [
            'id' => $a->id,
            'organization_name_en' => $a->organization?->name_en,
            'organization_name_am' => $a->organization?->name_am,
            'position_title_en' => $a->position?->title_en,
            'position_title_am' => $a->position?->title_am,
            'grade_level' => $a->grade_level,
            'number_of_vacancies' => $a->totalVacancyCount(),
            'opening_date' => $a->opening_date?->toDateString(),
            'closing_date' => $a->closing_date?->toDateString(),
            'published_at' => $a->published_at?->toDateString(),
            'is_open' => $a->isAcceptingApplications(),
        ];
    }

    public function show(TransferAnnouncement $announcement): Response
    {
        if ($announcement->status !== TransferAnnouncementStatus::Published) {
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
                'required_documents' => $announcement->required_documents,
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

        $announcement->loadMissing(['organization', 'position']);

        return Inertia::render('Public/TransferAnnouncements/Apply', [
            'announcement' => [
                'id' => $announcement->id,
                'organization_name_en' => $announcement->organization?->name_en,
                'organization_name_am' => $announcement->organization?->name_am,
                'position_title_en' => $announcement->position?->title_en,
                'position_title_am' => $announcement->position?->title_am,
                'grade_level' => $announcement->grade_level,
                'closing_date' => $announcement->closing_date?->toDateString(),
                'required_documents' => $announcement->required_documents,
            ],
            'show_url' => route('public.transfer-announcements.show', $announcement),
        ]);
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
                    'cover_letter' => $request->input('cover_letter'),
                    'documents' => $request->file('documents') ?? [],
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
