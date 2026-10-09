<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceFeedbackRequest;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Services\ServiceFeedback\PublicServiceFeedbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public Client Service Feedback — no authentication.
 *
 * The page an employee's printed feedback QR opens. A client picks the service
 * they received, rates it and optionally comments. Nothing identifying about
 * the employee is rendered: the page names the office, unit and role only.
 *
 * Unknown, suspended and revoked tokens all produce the SAME "unavailable"
 * page. Distinguishing them would let anyone with a token list learn which
 * employees exist and which QRs are live.
 */
class PublicServiceFeedbackController extends Controller
{
    public function __construct(
        private readonly PublicServiceFeedbackService $feedback,
        private readonly EmployeeFeedbackTokenService $tokens,
    ) {}

    /** The page a scanned feedback QR opens. */
    public function show(Request $request, string $token): Response
    {
        $record = $this->tokens->resolveForPublic($token);

        if ($record === null) {
            $this->feedback->auditBlockedScan($request);

            return Inertia::render('Public/ServiceFeedback', [
                'available' => false,
                'token' => $token,
                'context' => null,
                'serviceTypes' => [],
                'submitted' => false,
            ]);
        }

        $this->tokens->recordScan($record);

        $locale = (string) session('locale', config('app.locale', 'en'));

        return Inertia::render('Public/ServiceFeedback', [
            'available' => true,
            'token' => $token,
            'context' => $this->feedback->publicContext($record, $locale),
            'serviceTypes' => $this->feedback->serviceOptions(
                $this->feedback->positionIdForToken($record),
                $locale,
            ),
            'submitted' => (bool) $request->session()->get('feedback_submitted', false),
        ]);
    }

    public function store(StoreServiceFeedbackRequest $request, string $token): RedirectResponse
    {
        $record = $this->tokens->resolveForPublic($token);

        if ($record === null) {
            $this->feedback->auditBlockedScan($request);

            // Same generic outcome as a scan of an unknown token.
            return redirect()
                ->route('service-feedback.show', $token);
        }

        $validated = $request->validated();

        /*
         * Re-check the chosen service against the employee's own position. The
         * dropdown narrowed the options, but a submission is just a POST — a
         * caller can name any service_type_id, including one belonging to a
         * different role, so the server decides what this officer may be rated
         * on.
         */
        $positionId = $this->feedback->positionIdForToken($record);

        if (! $this->feedback->serviceIsAvailable($validated['position_service_id'], $positionId)) {
            return back()->withErrors([
                'position_service_id' => __('This service is not provided by this officer.'),
            ]);
        }

        $this->feedback->submit($record, $validated, $request);

        return redirect()
            ->route('service-feedback.show', $token)
            ->with('feedback_submitted', true);
    }
}
