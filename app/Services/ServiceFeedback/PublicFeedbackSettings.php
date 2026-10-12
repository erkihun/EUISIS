<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceFeedbackType;
use App\Enums\SubmitterIdentityMode;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\Request;

/**
 * The city-wide public feedback policy (brief §93): whether the public pages
 * are on, which of the three types may be submitted, whether complaints may
 * carry files, and what identity is asked of the submitter. Stored in the
 * `service_feedback` system-settings group; per-institution overrides are a
 * NEEDS_DECISION and are not modelled (§94).
 */
final class PublicFeedbackSettings
{
    public const GROUP = SystemSettingsRegistry::GROUP_SERVICE_FEEDBACK;

    public const PERMISSION = 'service_feedback.settings.manage';

    public const KEYS = ['enabled', 'identity_mode', 'allow_attachments', 'feedback_enabled', 'suggestion_enabled', 'complaint_enabled'];

    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly UpdateSystemSettingsGroupAction $update,
        private readonly WriteAuditLogAction $audit,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::GROUP, 'enabled', true);
    }

    public function identityMode(): SubmitterIdentityMode
    {
        return SubmitterIdentityMode::tryFrom((string) $this->settings->get(self::GROUP, 'identity_mode', 'optional'))
            ?? SubmitterIdentityMode::Optional;
    }

    public function allowAttachments(): bool
    {
        return (bool) $this->settings->get(self::GROUP, 'allow_attachments', true);
    }

    public function typeEnabled(ServiceFeedbackType $type): bool
    {
        return (bool) $this->settings->get(self::GROUP, $type->value.'_enabled', true);
    }

    /** @return list<string> */
    public function enabledTypes(): array
    {
        return array_values(array_map(
            fn (ServiceFeedbackType $type): string => $type->value,
            array_filter(ServiceFeedbackType::cases(), fn (ServiceFeedbackType $type): bool => $this->typeEnabled($type)),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'identity_mode' => $this->identityMode()->value,
            'allow_attachments' => $this->allowAttachments(),
            'feedback_enabled' => $this->typeEnabled(ServiceFeedbackType::Feedback),
            'suggestion_enabled' => $this->typeEnabled(ServiceFeedbackType::Suggestion),
            'complaint_enabled' => $this->typeEnabled(ServiceFeedbackType::Complaint),
        ];
    }

    /** The policy is city-wide, so it takes the permission and a city-level account. */
    public function canManage(User $user): bool
    {
        return $user->hasAnyPermission([self::PERMISSION]) && $this->scope->isUnrestricted($user);
    }

    /** @param  array<string, mixed>  $data  validated, keys from self::KEYS */
    public function update(User $user, array $data, ?Request $request = null): void
    {
        $before = $this->toArray();
        $this->update->execute(self::GROUP, array_intersect_key($data, array_flip(self::KEYS)), $user);
        $this->settings->clearCache();

        $this->audit->execute(
            eventType: AuditEventType::ServiceFeedbackSettingsUpdated,
            actor: $user,
            oldValues: $before,
            newValues: $this->toArray(),
            reason: 'Public feedback policy updated',
            request: $request,
        );
    }
}
