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
use App\Services\ServiceFeedback\PublicEmployeeFeedbackIdentity;
use App\Services\ServiceFeedback\PublicFeedbackCategoryService;
use App\Services\ServiceFeedback\PublicFeedbackFormToken;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\ServiceFeedback\PublicFeedbackSubmissionService;
use App\Services\ServiceFeedback\PublicFeedbackTargetResolver;
use App\Services\ServiceFeedback\PublicServiceFeedbackService;
use App\Services\ServiceFeedback\TargetContext;
use App\Support\Phone\PublicContactPhone;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Public Feedback, Suggestion and Complaint — no authentication.
 *
 * One experience for both QR kinds: the employee feedback QR
 * (/service-feedback/{token}) and the organization feedback QR
 * (/feedback/{token}). The token in the URL is the only credential; the
 * resolver turns it into the target server-side and nothing the browser
 * posts can replace it (brief §14, 17).
 *
 * Unknown, suspended, revoked and disabled tokens, inactive employees and
 * inactive organizations all produce the SAME "unavailable" page.
 * Distinguishing them would let anyone with a token list learn which targets
 * exist. Every submission resolves the token again, so a token revoked or an
 * employee leaving service while the form was open blocks the send. The
 * employee QR does not depend on the ID card's status.
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
        private readonly PublicFeedbackCategoryService $categories,
        private readonly PublicFeedbackSettings $settings,
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
        // Brief §93: a type the policy has switched off has no form.
        if ($type !== null && ! $this->settings->typeEnabled($type)) {
            abort(404);
        }

        $context = $this->settings->enabled() ? $this->resolve($kind, $token) : null;
        $links = $this->links($kind, $token);

        if ($context === null) {
            if ($this->settings->enabled()) {
                $this->feedback->auditBlockedScan($request);
            }

            return Inertia::render('Public/ServiceFeedback', [
                'available' => false,
                'token' => $token,
                'target' => null,
                'links' => $links,
                'serviceTypes' => [],
                'categories' => [],
                'submitted' => false,
                'type' => null,
                'reference' => null,
                'receipt' => null,
                'formToken' => null,
                'enabledTypes' => $this->settings->enabledTypes(),
                'identityMode' => $this->settings->identityMode()->value,
                'allowAttachments' => $this->settings->allowAttachments(),
                'call' => null,
            ]);
        }

        $this->recordScan($context);

        $locale = app()->getLocale();
        $receipt = $request->session()->get('feedback_receipt');
        $receiptContext = $request->session()->get('feedback_receipt_context');
        $receiptMatches = is_array($receipt)
            && is_string($receiptContext)
            && hash_equals($this->receiptContext($kind, $token), $receiptContext)
            && ($type === null || ($receipt['type'] ?? null) === $type->value);
        $receipt = $receiptMatches ? $receipt : null;

        return Inertia::render('Public/ServiceFeedback', [
            'available' => true,
            'token' => $token,
            // Employee: photo and full name only (PublicEmployeeFeedbackIdentity). Organization: unchanged.
            'target' => $context->targetType === PublicFeedbackTargetType::Employee
                ? PublicEmployeeFeedbackIdentity::fromContext($context, $token, $locale)->toArray()
                : $context->publicDisplay($locale),
            'links' => $links,
            'serviceTypes' => $context->targetType === PublicFeedbackTargetType::Employee
                ? $this->feedback->serviceOptions($context->contextPositionId, $locale)
                : [],
            'categories' => $type === null ? [] : $this->categories->optionsFor($context, $type, $locale),
            'submitted' => $receiptMatches && (bool) $request->session()->get('feedback_submitted', false),
            'type' => $type?->value,
            'reference' => $receipt['reference'] ?? null,
            'receipt' => $receipt,
            'formToken' => $type === null ? null : $this->formTokens->issue(),
            'enabledTypes' => $this->settings->enabledTypes(),
            'identityMode' => $this->settings->identityMode()->value,
            'allowAttachments' => $this->settings->allowAttachments(),
            /*
             * The Call action: the institution's validated public number as a
             * tel: link and a readable form — the scanned institution, or the
             * one the scanned employee serves now. Never the employee's own
             * number. Null (no Call button) when none is set or it is invalid.
             */
            'call' => PublicContactPhone::parse($context->extras['public_contact_phone'] ?? null)?->toPublicArray(),
        ]);
    }

    private function storeFor(Request $request, PublicFeedbackTargetType $kind, string $token, ServiceFeedbackType $type, bool $legacyRedirect = false): RedirectResponse
    {
        if (! $this->settings->typeEnabled($type)) {
            abort(404);
        }

        $links = $this->links($kind, $token);

        if (! $this->settings->enabled()) {
            return redirect()->to($links['landing']);
        }

        $context = $this->resolve($kind, $token);

        if ($context === null) {
            $this->feedback->auditBlockedScan($request);

            // Same generic outcome as a scan of an unknown token.
            return redirect()->to($links['landing']);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // A category is accepted only if this visitor could have been offered it.
        if (! empty($validated['category_id']) && ! $this->categories->isApplicable((string) $validated['category_id'], $context, $type)) {
            return back()->withErrors(['category_id' => __('service-feedback.category_not_applicable')]);
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

        $formToken = isset($validated['form_token']) ? (string) $validated['form_token'] : null;

        if ($formToken !== null && ! $this->formTokens->consume($formToken)) {
            return back()->withErrors(['form_token' => __('service-feedback.form_replayed')]);
        }

        try {
            $entry = $this->submissions->submit($context, $type, $validated, $request);
        } catch (ValidationException $exception) {
            $this->restoreFormToken($formToken);

            throw $exception;
        } catch (Throwable $exception) {
            $this->restoreFormToken($formToken);

            // Brief §76–78: storage or database trouble rolls the submission back (nothing is kept) and the
            // citizen is told to try again. A query exception embeds the bound values, so it is logged by class
            // and SQLSTATE only; the citizen's words never reach the log.
            if ($exception instanceof QueryException) {
                Log::error('Public feedback submission failed', ['exception' => $exception::class, 'sqlstate' => (string) $exception->getCode(), 'target' => $context->targetType->value]);
            } else {
                report($exception);
            }

            return back()->withErrors(['message' => __('service-feedback.submission_failed')])->withInput($request->except('attachments'));
        }

        return redirect()->to($legacyRedirect ? $links['landing'] : $links[$type->value])
            ->with('feedback_submitted', true)
            ->with('feedback_receipt', $this->receipt($entry, $context))
            ->with('feedback_receipt_context', $this->receiptContext($kind, $token));
    }

    private function receiptContext(PublicFeedbackTargetType $kind, string $token): string
    {
        return hash('sha256', $kind->value."\0".$token);
    }

    private function restoreFormToken(?string $token): void
    {
        if ($token === null) {
            return;
        }

        try {
            $this->formTokens->restore($token);
        } catch (Throwable $exception) {
            Log::warning('Public feedback form token could not be restored', ['exception' => $exception::class]);
        }
    }

    private function resolve(PublicFeedbackTargetType $kind, string $token): ?TargetContext
    {
        return $kind === PublicFeedbackTargetType::Employee
            ? $this->resolver->resolveEmployeeToken($token)
            : $this->resolver->resolveOrganizationToken($token);
    }

    /** The context was just resolved; count the scan on that record without resolving the token again. */
    private function recordScan(TargetContext $context): void
    {
        if ($context->sourceId === null) {
            return;
        }

        $context->targetType === PublicFeedbackTargetType::Employee
            ? $this->tokens->recordScanById($context->sourceId)
            : $this->organizationCodes->recordScanById($context->sourceId);
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
