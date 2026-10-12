<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\PublicFeedbackSubjectEmployee;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Investigation subjects on a public complaint (brief §61–62). */
class PublicFeedbackSubjectController extends Controller
{
    public function __construct(private readonly PublicFeedbackReferralService $referrals) {}

    public function store(Request $request, string $type, EmployeeServiceFeedback $submission): RedirectResponse
    {
        abort_unless($type === 'complaint' && $submission->type === ServiceFeedbackType::Complaint, 404);
        $this->authorize('manageSubjects', $submission);

        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'subject_role' => ['nullable', 'string', 'max:120'],
        ]);

        $this->referrals->addSubject($submission, (string) $data['employee_id'], $data['subject_role'] ?? null, $this->user(), $request);

        return back()->with('success', __('service-feedback.subject_added'));
    }

    public function destroy(Request $request, string $type, EmployeeServiceFeedback $submission, PublicFeedbackSubjectEmployee $subject): RedirectResponse
    {
        abort_unless($type === 'complaint' && $submission->type === ServiceFeedbackType::Complaint && $subject->submission_id === $submission->getKey(), 404);
        $this->authorize('manageSubjects', $submission);

        $this->referrals->removeSubject($submission, $subject, $this->user(), $request);

        return back()->with('success', __('service-feedback.subject_removed'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
