<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicServiceEntryRequest;
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
        return $this->render($request, $token, null);
    }

    public function form(Request $request, string $token, string $type): Response
    {
        abort_unless(in_array($type, array_column(ServiceFeedbackType::cases(), 'value'), true), 404);

        return $this->render($request, $token, ServiceFeedbackType::from($type));
    }

    private function render(Request $request, string $token, ?ServiceFeedbackType $type): Response
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
                'type' => null,
                'reference' => null,
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
            'type' => $type?->value,
            'reference' => $request->session()->get('feedback_reference'),
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
                'position_service_id' => __('service-feedback.service_not_provided'),
            ]);
        }

        $this->feedback->submit($record, $validated, $request);

        return redirect()
            ->route($request->routeIs('service-feedback.store') ? 'service-feedback.show' : 'service-feedback.form',
                $request->routeIs('service-feedback.store') ? $token : ['token' => $token, 'type' => 'feedback'])
            ->with('feedback_submitted', true);
    }

    public function storeTypedFeedback(StoreServiceFeedbackRequest $request, string $token): RedirectResponse
    {
        return $this->store($request, $token);
    }

    public function storeEntry(StorePublicServiceEntryRequest $request, string $token, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ['suggestion', 'complaint'], true), 404);

        $record = $this->tokens->resolveForPublic($token);
        if ($record === null) {
            $this->feedback->auditBlockedScan($request);

            return to_route('service-feedback.show', $token);
        }

        $validated = $request->validated();
        if (isset($validated['position_service_id']) && ! $this->feedback->serviceIsAvailable(
            $validated['position_service_id'],
            $this->feedback->positionIdForToken($record),
        )) {
            return back()->withErrors(['position_service_id' => __('service-feedback.service_not_provided')]);
        }

        $entry = $this->feedback->submitEntry($record, ServiceFeedbackType::from($type), $validated, $request);

        return to_route('service-feedback.form', ['token' => $token, 'type' => $type])
            ->with('feedback_submitted', true)
            ->with('feedback_reference', $entry->reference_number);
    }
}
