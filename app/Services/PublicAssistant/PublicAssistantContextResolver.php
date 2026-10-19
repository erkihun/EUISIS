<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use App\Enums\PublicFeedbackTargetType;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\ServiceFeedback\PublicEmployeeFeedbackIdentity;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\ServiceFeedback\PublicFeedbackTargetResolver;
use App\Services\ServiceFeedback\TargetContext;
use App\Support\Phone\PublicContactPhone;
use Throwable;

/**
 * The public context resolver (P-2, §60–63): page kind from the registry,
 * the scanned target from the same resolver the QR pages use — the token
 * decides, never the message — reduced to what the page already shows.
 */
final class PublicAssistantContextResolver
{
    public function __construct(
        private readonly PublicAssistantSettings $settings,
        private readonly PublicFeedbackSettings $feedbackSettings,
        private readonly PublicFeedbackTargetResolver $resolver,
        private readonly LLMGateway $gateway,
        private readonly IntelligenceSettings $intelligence,
    ) {}

    /** @param  array{route?: ?string, path?: ?string, token?: ?string}  $page */
    public function resolve(array $page, ?string $locale = null): PublicAssistantContext
    {
        $locale ??= app()->getLocale();
        $route = isset($page['route']) && is_string($page['route']) ? mb_substr($page['route'], 0, 120) : null;
        $path = isset($page['path']) && is_string($page['path']) ? mb_substr($page['path'], 0, 255) : '';
        $kind = PublicAssistantPageRegistry::kind($route, $path);
        $token = PublicAssistantPageRegistry::tokenFromPath($path) ?? (isset($page['token']) && is_string($page['token']) && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $page['token']) === 1 ? $page['token'] : null);

        $target = null;
        if ($token !== null && in_array($kind, ['employee_qr', 'organization_qr', 'feedback_form'], true) && $this->feedbackSettings->enabled()) {
            $target = $this->target($kind, $path, $token, $locale);
            if ($target === null) {
                $token = null; // unknown, disabled or revoked: the assistant knows nothing about it
            }
        }

        return new PublicAssistantContext(
            locale: $locale,
            capabilities: [
                'knowledge' => $this->settings->knowledgeEnabled(),
                'services' => $this->settings->serviceInfoEnabled(),
                'support' => $this->settings->supportEnabled(),
                'status' => $this->settings->statusEnabled(),
            ],
            page: ['kind' => $kind, 'route' => $route, 'path' => $path, 'form_type' => PublicAssistantPageRegistry::formTypeFromPath($path), 'token' => $token, 'target' => $target],
            aiAvailable: $this->aiAvailable(),
        );
    }

    /** @return array{type: string, name: string, position_title: ?string, organization_id: ?string, phone: ?array{href: string, display: string}, links: array<string, string>}|null */
    private function target(string $kind, string $path, string $token, string $locale): ?array
    {
        $organization = $kind === 'organization_qr' || ($kind === 'feedback_form' && str_starts_with($path, '/feedback/'));
        try {
            $context = $organization ? $this->resolver->resolveOrganizationToken($token) : $this->resolver->resolveEmployeeToken($token);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if (! $context instanceof TargetContext) {
            return null;
        }

        if ($context->targetType === PublicFeedbackTargetType::Organization) {
            $display = $context->publicDisplay($locale);
            $phone = PublicContactPhone::parse($context->extras['public_contact_phone'] ?? null)?->toPublicArray();

            return [
                'type' => 'organization', 'name' => (string) ($display['display_name'] ?? ''), 'position_title' => null,
                'organization_id' => $context->organizationTargetId, 'phone' => $phone,
                'links' => [
                    'landing' => PublicAssistantPageRegistry::relative(route('public-feedback.organization.show', $token)),
                    'feedback' => PublicAssistantPageRegistry::relative(route('public-feedback.organization.form', ['token' => $token, 'type' => 'feedback'])),
                    'suggestion' => PublicAssistantPageRegistry::relative(route('public-feedback.organization.form', ['token' => $token, 'type' => 'suggestion'])),
                    'complaint' => PublicAssistantPageRegistry::relative(route('public-feedback.organization.form', ['token' => $token, 'type' => 'complaint'])),
                ],
            ];
        }

        // Employee QR: exactly what the page shows (display name, position title) and nothing else.
        $identity = PublicEmployeeFeedbackIdentity::fromContext($context, $token, $locale)->toArray();

        return [
            'type' => 'employee', 'name' => (string) ($identity['display_name'] ?? ''), 'position_title' => $identity['position_title'] ?? null,
            'organization_id' => null, 'phone' => null,
            'links' => [
                'landing' => PublicAssistantPageRegistry::relative(route('service-feedback.show', $token)),
                'feedback' => PublicAssistantPageRegistry::relative(route('service-feedback.form', ['token' => $token, 'type' => 'feedback'])),
                'suggestion' => PublicAssistantPageRegistry::relative(route('service-feedback.form', ['token' => $token, 'type' => 'suggestion'])),
                'complaint' => PublicAssistantPageRegistry::relative(route('service-feedback.form', ['token' => $token, 'type' => 'complaint'])),
            ],
        ];
    }

    /** AI counts as available for visitors only when public content may actually go outbound (P-9). */
    private function aiAvailable(): bool
    {
        try {
            return in_array('public_content', $this->intelligence->outboundDataClasses(), true) && $this->gateway->available();
        } catch (Throwable) {
            return false;
        }
    }
}
