<?php

declare(strict_types=1);

namespace Modules\Transfer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transfer\Application\Actions\SubmitTransferApplicationAction;
use Modules\Transfer\Application\Actions\WithdrawTransferApplicationAction;
use Modules\Transfer\Application\Services\TransferEligibilityService;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferAnnouncementPosition;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferApplicationDocument;
use Modules\Transfer\Domain\Models\TransferApproval;
use Modules\Transfer\Domain\Models\TransferSetting;
use Modules\Transfer\Http\Requests\PublicStoreTransferApplicationRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The employee's transfer screens inside My Portal: open announcements, apply,
 * withdraw, their own applications and evidence. Same URLs and route names as
 * when they lived in EmployeePortalController.
 */
class EmployeeTransferPortalController extends Controller
{
    /**
     * Open transfer announcements, inside the portal.
     *
     * The public listing renders in PublicLayout, so following an announcement
     * from the portal used to drop the employee out of the signed-in chrome
     * and onto the public site. These three screens keep the whole
     * browse → read → apply path under /my-portal; the presenter and the
     * submit action are shared with the public pages so the two never drift.
     */
    public function announcements(Request $request): Response
    {
        $announcements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published)
            ->whereDate('closing_date', '>=', today())
            ->with(['organization', 'position'])
            ->orderByDesc('closing_date')
            ->orderByDesc('published_at')
            ->paginate(15)
            ->through(fn (TransferAnnouncement $a): array => PublicTransferAnnouncementController::presentSummary($a));

        $employee = $request->user()?->employee;

        return Inertia::render('Employee/Announcements', [
            'announcements' => $announcements,
            'applied_ids' => $employee === null ? [] : $this->activeApplicationAnnouncementIds($employee),
            'has_employee' => $employee !== null,
        ]);
    }

    public function announcementShow(Request $request, TransferAnnouncement $announcement, TransferEligibilityService $eligibility): Response
    {
        abort_unless($announcement->status === TransferAnnouncementStatus::Published && ! ($announcement->closing_date?->isPast() ?? true), 404);

        $announcement->loadMissing(['organization', 'position', 'positions.position.organizationUnit']);
        $employee = $request->user()?->employee;

        return Inertia::render('Employee/AnnouncementShow', [
            'announcement' => $this->presentAnnouncementDetail($announcement, $employee, $eligibility),
            'already_applied' => $employee !== null
                && in_array($announcement->id, $this->activeApplicationAnnouncementIds($employee), true),
            'has_employee' => $employee !== null,
        ]);
    }

    public function announcementApply(Request $request, TransferAnnouncement $announcement, TransferEligibilityService $eligibility): Response|RedirectResponse
    {
        if (! $announcement->isAcceptingApplications()) {
            return $this->backToAnnouncement($announcement, __('transfers.notAcceptingApplications'));
        }

        $employee = $request->user()?->employee;

        if ($employee === null) {
            return $this->backToAnnouncement($announcement, __('transfers.noEmployeeProfile'));
        }

        if (in_array($announcement->id, $this->activeApplicationAnnouncementIds($employee), true)) {
            return $this->backToAnnouncement($announcement, __('transfers.alreadyApplied'));
        }

        $announcement->loadMissing(['organization', 'position', 'positions.position.organizationUnit']);

        return Inertia::render('Employee/AnnouncementApply', [
            'announcement' => $this->presentAnnouncementDetail($announcement, $employee, $eligibility),
            'employee_context' => $this->employeeContext($employee),
            'employee_documents' => EmployeeDocument::query()->where('employee_id', $employee->id)
                ->whereIn('document_type', collect([
                    ...(array) TransferSetting::current()->required_documents,
                    ...(array) $announcement->required_documents,
                ])->filter()->unique()->all())
                ->get(['id', 'document_type', 'file_path'])
                ->map(fn (EmployeeDocument $document): array => [
                    'id' => $document->id,
                    'document_type' => $document->document_type,
                    'name' => basename($document->file_path),
                ])->values(),
        ]);
    }

    public function announcementApplyStore(
        PublicStoreTransferApplicationRequest $request,
        TransferAnnouncement $announcement,
        SubmitTransferApplicationAction $action,
    ): RedirectResponse {
        $employee = $request->user()?->employee;

        if ($employee === null) {
            return $this->backToAnnouncement($announcement, __('transfers.noEmployeeProfile'));
        }

        try {
            $action->execute($announcement, $employee, $request->user(), [
                'announcement_position_id' => $request->input('announcement_position_id'),
                'cover_letter' => $request->input('cover_letter'),
                'documents' => $request->file('documents') ?? [],
                'reuse_document_ids' => $request->input('reuse_document_ids') ?? [],
            ]);
        } catch (DomainException $e) {
            return back()->withErrors(['application' => $e->getMessage()]);
        }

        return to_route('employee.announcements.show', $announcement)->with('flash', [
            'message' => __('transfers.applicationSubmitted'),
            'type' => 'success',
        ]);
    }

    public function withdrawTransferApplication(Request $request, string $application, WithdrawTransferApplicationAction $action): RedirectResponse
    {
        $employee = $request->user()?->employee;
        abort_unless($employee !== null, 403);
        $transferApplication = TransferApplication::query()
            ->where('employee_id', $employee->id)->findOrFail($application);
        $this->authorize('withdraw', $transferApplication);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $action->execute($transferApplication, $request->user(), $data['reason']);
        } catch (DomainException $e) {
            // The page posts from a dialog, not a form, so a field error under
            // `withdrawal_reason` had nowhere to render; the refusal is a toast.
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('flash', ['message' => __('transfers.applicationWithdrawn'), 'type' => 'success']);
    }

    /**
     * Announcement ids this employee holds a live application against.
     *
     * @return array<int, string>
     */
    private function activeApplicationAnnouncementIds(Employee $employee): array
    {
        return TransferApplication::query()
            ->where('employee_id', $employee->id)
            ->whereNotIn('status', [
                TransferApplicationStatus::Withdrawn->value,
                TransferApplicationStatus::Cancelled->value,
            ])
            ->pluck('announcement_id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentAnnouncementDetail(TransferAnnouncement $announcement, ?Employee $employee = null, ?TransferEligibilityService $eligibility = null): array
    {
        $positions = $announcement->positions;
        if ($positions->isEmpty() && $announcement->position !== null) {
            $positions = collect([(object) [
                'id' => null,
                'position' => $announcement->position,
                'position_id' => $announcement->position_id,
                'organization_id' => $announcement->organization_id,
                'grade_level' => $announcement->grade_level,
                'vacancy_count' => $announcement->number_of_vacancies,
            ]]);
        }

        return [
            ...PublicTransferAnnouncementController::presentSummary($announcement),
            'salary_min' => $announcement->salary_min,
            'salary_max' => $announcement->salary_max,
            'eligibility_rules' => $announcement->eligibility_rules,
            'required_documents' => collect([
                ...(array) TransferSetting::current()->required_documents,
                ...(array) $announcement->required_documents,
            ])->filter(fn (mixed $type): bool => is_string($type) && trim($type) !== '')
                ->map(fn (string $type): string => trim($type))->unique()->values()->all(),
            'status' => $announcement->status->value,
            'positions' => $positions->map(function ($line) use ($employee, $eligibility, $announcement): array {
                $position = $line->position;
                $result = $employee !== null && $eligibility !== null
                    ? $eligibility->evaluate($employee, $announcement, announcementPosition: $line instanceof TransferAnnouncementPosition ? $line : null)
                    : null;

                return [
                    'id' => $line->id,
                    'position_title_en' => $position?->title_en,
                    'position_title_am' => $position?->title_am,
                    'position_code' => $position?->job_position_code,
                    'organization_unit_en' => $position?->organizationUnit?->name_en,
                    'organization_unit_am' => $position?->organizationUnit?->name_am,
                    'grade_level' => $line->grade_level ?? $position?->grade_level,
                    'advertised_slots' => $line->vacancy_count,
                    'eligible' => $result['eligible'] ?? null,
                    'eligibility_status' => $result['status'] ?? null,
                    'eligibility_reasons' => $result === null || $result['eligible'] ? [] : ['requirements_not_met'],
                ];
            })->values(),
        ];
    }

    /** @return array<string, string|null> */
    private function employeeContext(Employee $employee): array
    {
        $assignment = $employee->currentAssignment?->loadMissing(['organization', 'organizationUnit', 'position']);

        return [
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'organization' => $assignment?->organization?->name_en,
            'organization_unit' => $assignment?->organizationUnit?->name_en,
            'position' => $assignment?->position?->title_en,
            'grade_level' => $assignment?->position?->grade_level,
        ];
    }

    private function backToAnnouncement(TransferAnnouncement $announcement, string $message): RedirectResponse
    {
        return to_route('employee.announcements.show', $announcement)
            ->with('flash', ['message' => $message, 'type' => 'error']);
    }

    public function myTransferApplications(Request $request): Response
    {
        $user = $request->user();
        $employee = $user->employee;

        $applications = null;

        if ($employee !== null) {
            $applications = TransferApplication::query()
                ->where('employee_id', $employee->id)
                ->with(['announcement.organization', 'announcement.position', 'announcementPosition.position', 'documents', 'approvals'])
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
                ->when($request->filled('search'), function ($query) use ($request): void {
                    $term = '%'.$request->string('search').'%';
                    $query->whereHas('announcement.position', fn ($position) => $position->where('title_en', ci_like_operator(), $term)
                        ->orWhere('title_am', ci_like_operator(), $term));
                })
                ->orderByDesc('submitted_at')
                ->paginate(15)->withQueryString()
                ->through(fn (TransferApplication $app) => [
                    'id' => $app->id,
                    'application_number' => $app->application_number,
                    'status' => $app->status?->value,
                    'status_label' => $app->status?->label(),
                    'submitted_at' => $app->submitted_at?->toDateString(),
                    'applicant_notes' => $app->applicant_notes,
                    'organization_name' => $app->announcement?->organization?->name_en,
                    'organization_name_am' => $app->announcement?->organization?->name_am,
                    'position_title' => $app->announcementPosition?->position?->title_en ?? $app->announcement?->position?->title_en,
                    'position_title_am' => $app->announcementPosition?->position?->title_am ?? $app->announcement?->position?->title_am,
                    'announcement_id' => $app->announcement_id,
                    'closing_date' => $app->announcement?->closing_date?->toDateString(),
                    'rejected_reason' => $app->rejected_reason,
                    'effective_date' => $app->effective_date?->toDateString(),
                    // The employee's own evidence, so they can see what was sent.
                    'documents' => $app->documents->map(fn (TransferApplicationDocument $document): array => [
                        'id' => $document->id,
                        'document_type' => $document->document_type,
                        'original_name' => $document->original_name,
                    ])->values(),
                    // Which approval stages this application goes through, and where each stands.
                    'approvals' => $app->approvals->map(fn (TransferApproval $approval): array => [
                        'approval_type' => $approval->approval_type?->value,
                        'status' => $approval->status?->value,
                    ])->values(),
                    'can_withdraw' => $user->can('withdraw', $app),
                ]);
        }

        return Inertia::render('Employee/MyTransferApplications', [
            'applications' => $applications,
            'has_employee' => $employee !== null,
            'filters' => $request->only('status', 'search'),
        ]);
    }

    /** Private application evidence is available only to the owning employee. */
    public function downloadTransferApplicationDocument(Request $request, TransferApplicationDocument $document): StreamedResponse
    {
        $employee = $request->user()?->employee;
        $document->loadMissing('transferApplication');
        abort_unless($employee !== null && $document->transferApplication?->employee_id === $employee->id, 403);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }
}
