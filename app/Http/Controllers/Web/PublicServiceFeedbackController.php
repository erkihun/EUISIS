<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicServiceEntryRequest;
use App\Http\Requests\StoreServiceFeedbackRequest;
use App\Models\EmployeeServiceFeedback;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackFormToken;
use App\Services\ServiceFeedback\PublicFeedbackSubmissionService;
use App\Services\ServiceFeedback\PublicFeedbackTargetResolver;
use App\Services\ServiceFeedback\PublicServiceFeedbackService;
use App\Services\ServiceFeedback\TargetContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public Feedback, Suggestion and Complaint — no authentication.
 *
 * One experience for both QR kinds: the employee feedback QR
 * (/service-feedback/{token}) and the organization feedback QR
 * (/feedback/{token}). The token in the URL is the only credential; the
 * resolver turns it into the target server-side and nothing the browser
 * posts can replace it (brief §14, 17).
 *
 * Unknown, suspended, revoked and disabled tokens, and inactive
 * organizations, all produce the SAME "unavailable" page. Distinguishing them
 * would let anyone with a token list learn which targets exist.
 */
class PublicServiceFeedbackController extends Controller
{
    public function __construct(
        private readonly PublicServiceFeedbackService $feedback,
        private readonly EmployeeFeedbackTokenService $tokens,
        private readonly OrganizationFeedbackQrService $organizationCodes,
        private readonly PublicFeedbackTargetResolver $resolver,
        private readonly PublicFeedbackSubmissionService $submissions,
        private readonly PublicFeedbackFormToken $formTokens,
    ) {}

    // ── Employee feedback QR ─────────────────────────────────────────────

    /** The page a scanned employee feedback QR opens. */
    public function show(Request $request, string $token): Response
    {
        return $this->render($request, PublicFeedbackTargetType::Employee, $token, null);
    }

    public function form(Request $request, string $token, string $type): Response
    {
        return $this->render($request, PublicFeedbackTargetType::Employee, $token, $this->type($type));
    }

    public function store(StoreServiceFeedbackRequest $request, string $token): RedirectResponse
    {
        return $this->storeFor($request, PublicFeedbackTargetType::Employee, $token, ServiceFeedbackType::Feedback, legacyRedirect: $request->routeIs('service-feedback.store'));
    }

    public function storeTypedFeedback(StoreServiceFeedbackRequest $request, string $token): RedirectResponse
    {
        return $this->storeFor($request, PublicFeedbackTargetType::Employee, $token, ServiceFeedbackType::Feedback);
    }

    public function storeEntry(StorePublicServiceEntryRequest $request, string $token, string $type): RedirectResponse
    {
        return $this->storeFor($request, PublicFeedbackTargetType::Employee, $token, $this->entryType($type));
    }

    // ── Organization feedback QR ─────────────────────────────────────────

    public function showOrganization(Request $request, string $token): Response
    {
        return $this->render($request, PublicFeedbackTargetType::Organization, $token, null);
    }

    public function formOrganization(Request $request, string $token, string $type): Response
    {
        return $this->render($request, PublicFeedbackTargetType::Organization, $token, $this->type($type));
    }

    public function storeOrganizationFeedback(StoreServiceFeedbackRequest $request, string $token): RedirectResponse
    {
        return $this->storeFor($request, PublicFeedbackTargetType::Organization, $token, ServiceFeedbackType::Feedback);
    }

    public function storeOrganizationEntry(StorePublicServiceEntryRequest $request, string $token, string $type): RedirectResponse
    {
        return $this->storeFor($request, PublicFeedbackTargetType::Organization, $token, $this->entryType($type));
    }

    // ── Shared ───────────────────────────────────────────────────────────

    private function render(Request $request, PublicFeedbackTargetType $kind, string $token, ?ServiceFeedbackType $type): Response
    {
        $context = $this->resolve($kind, $token);
        $links = $this->links($kind, $token);

        if ($context === null) {
            $this->feedback->auditBlockedScan($request);

            return Inertia::render('Public/ServiceFeedback', [
                'available' => false,
                'token' => $token,
                'target' => null,
                'links' => $links,
                'serviceTypes' => [],
                'submitted' => false,
                'type' => null,
                'reference' => null,
                'receipt' => null,
                'formToken' => null,
            ]);
        }

        $this->recordScan($kind, $token);

        $locale = app()->getLocale();
        $receipt = $request->session()->get('feedback_receipt');

        return Inertia::render('Public/ServiceFeedback', [
            'available' => true,
            'token' => $token,
            'target' => $context->publicDisplay($locale),
            'links' => $links,
            'serviceTypes' => $context->targetType === PublicFeedbackTargetType::Employee
                ? $this->feedback->serviceOptions($context->contextPositionId, $locale)
                : [],
            'submitted' => (bool) $request->session()->get('feedback_submitted', false),
            'type' => $type?->value,
            'reference' => $receipt['reference'] ?? null,
            'receipt' => $receipt,
            'formToken' => $type === null ? null : $this->formTokens->issue(),
        ]);
    }

    private function storeFor(Request $request, PublicFeedbackTargetType $kind, string $token, ServiceFeedbackType $type, bool $legacyRedirect = false): RedirectResponse
    {
        $context = $this->resolve($kind, $token);
        $links = $this->links($kind, $token);

        if ($context === null) {
            $this->feedback->auditBlockedScan($request);

            // Same generic outcome as a scan of an unknown token.
            return redirect()->to($links['landing']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        if (isset($validated['form_token']) && ! $this->formTokens->consume((string) $validated['form_token'])) {
            return back()->withErrors(['form_token' => __('service-feedback.form_replayed')]);
        }

        /*
         * Re-check a chosen service against the employee's own position. The
         * dropdown narrowed the options, but a submission is just a POST — a
         * caller can name any service id, including one belonging to a
         * different role, so the server decides what this officer is rated on.
         */
        if ($context->targetType === PublicFeedbackTargetType::Employee
            && ! empty($validated['position_service_id'])
            && ! $this->feedback->serviceIsAvailable((string) $validated['position_service_id'], $context->contextPositionId)) {
            return back()->withErrors(['position_service_id' => __('service-feedback.service_not_provided')]);
        }

        $entry = $this->submissions->submit($context, $type, $validated, $request);

        return redirect()->to($legacyRedirect ? $links['landing'] : $links[$type->value])
            ->with('feedback_submitted', true)
            ->with('feedback_receipt', $this->receipt($entry, $context));
    }

    private function resolve(PublicFeedbackTargetType $kind, string $token): ?TargetContext
    {
        return $kind === PublicFeedbackTargetType::Employee
            ? $this->resolver->resolveEmployeeToken($token)
            : $this->resolver->resolveOrganizationToken($token);
    }

    private function recordScan(PublicFeedbackTargetType $kind, string $token): void
    {
        if ($kind === PublicFeedbackTargetType::Employee) {
            $record = $this->tokens->resolveForPublic($token);

            if ($record !== null) {
                $this->tokens->recordScan($record);
            }

            return;
        }

        $code = $this->organizationCodes->resolveForPublic($token);

        if ($code !== null) {
            $this->organizationCodes->recordScan($code);
        }
    }

    /**
     * Every URL the page needs, built here so the page never assembles one.
     *
     * @return array<string, string>
     */
    private function links(PublicFeedbackTargetType $kind, string $token): array
    {
        if ($kind === PublicFeedbackTargetType::Employee) {
            return [
                'landing' => route('service-feedback.show', $token),
                'feedback' => route('service-feedback.form', ['token' => $token, 'type' => 'feedback']),
                'suggestion' => route('service-feedback.form', ['token' => $token, 'type' => 'suggestion']),
                'complaint' => route('service-feedback.form', ['token' => $token, 'type' => 'complaint']),
                'submitFeedback' => route('service-feedback.feedback.store', $token),
                'submitSuggestion' => route('service-feedback.entry.store', ['token' => $token, 'type' => 'suggestion']),
                'submitComplaint' => route('service-feedback.entry.store', ['token' => $token, 'type' => 'complaint']),
            ];
        }

        return [
            'landing' => route('public-feedback.organization.show', $token),
            'feedback' => route('public-feedback.organization.form', ['token' => $token, 'type' => 'feedback']),
            'suggestion' => route('public-feedback.organization.form', ['token' => $token, 'type' => 'suggestion']),
            'complaint' => route('public-feedback.organization.form', ['token' => $token, 'type' => 'complaint']),
            'submitFeedback' => route('public-feedback.organization.feedback.store', $token),
            'submitSuggestion' => route('public-feedback.organization.entry.store', ['token' => $token, 'type' => 'suggestion']),
            'submitComplaint' => route('public-feedback.organization.entry.store', ['token' => $token, 'type' => 'complaint']),
        ];
    }

    /**
     * What the thank-you page may show (brief §42): the type, the target as
     * the visitor saw it, the reference, and when. Never an internal id.
     *
     * @return array<string, string|null>
     */
    private function receipt(EmployeeServiceFeedback $entry, TargetContext $context): array
    {
        return [
            'type' => $entry->type->value,
            'target_type' => $context->targetType->value,
            'target_name' => (string) $context->publicDisplay(app()->getLocale())['display_name'],
            'reference' => $entry->reference_number,
            'submitted_at' => $entry->submitted_at?->toIso8601String(),
        ];
    }

    private function type(string $type): ServiceFeedbackType
    {
        abort_unless(in_array($type, array_column(ServiceFeedbackType::cases(), 'value'), true), 404);

        return ServiceFeedbackType::from($type);
    }

    private function entryType(string $type): ServiceFeedbackType
    {
        abort_unless(in_array($type, ['suggestion', 'complaint'], true), 404);

        return ServiceFeedbackType::from($type);
    }
}
