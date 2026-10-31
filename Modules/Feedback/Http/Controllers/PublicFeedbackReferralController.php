<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\Feedback\Application\Services\PublicFeedbackReferralService;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackReferral;

/**
 * Controlled discipline referral requests on a reviewed public complaint
 * (brief §59–60). Requesting and deciding take `service_feedback.refer_discipline`
 * and are never open to the employee the complaint is about or names.
 */
class PublicFeedbackReferralController extends Controller
{
    public function __construct(private readonly PublicFeedbackReferralService $referrals) {}

    public function store(Request $request, string $type, EmployeeServiceFeedback $submission): RedirectResponse
    {
        abort_unless($type === 'complaint' && $submission->type === ServiceFeedbackType::Complaint, 404);
        $this->authorize('view', $submission);
        abort_unless($this->referrals->canRefer($this->user(), $submission), 403);

        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        $this->referrals->request($submission, (string) $data['notes'], $this->user(), $request);

        return back()->with('success', __('service-feedback.referral_requested'));
    }

    public function update(Request $request, string $type, EmployeeServiceFeedback $submission, PublicFeedbackReferral $referral): RedirectResponse
    {
        abort_unless($type === 'complaint' && $submission->type === ServiceFeedbackType::Complaint && $referral->submission_id === $submission->getKey(), 404);
        $this->authorize('view', $submission);
        $user = $this->user();

        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'declined', 'withdrawn'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Only the requester withdraws; anyone else authorised decides.
        abort_unless($data['status'] === 'withdrawn'
            ? (int) $referral->requested_by === (int) $user->getKey() && $this->referrals->canRefer($user, $submission)
            : $this->referrals->canDecide($user, $referral) || (! $referral->isOpen() && $this->referrals->canRefer($user, $submission)), 403);

        $this->referrals->decide($referral, $data['status'], $data['notes'] ?? null, $user, $request);

        return back()->with('success', __('service-feedback.referral_decided'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
