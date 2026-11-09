<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\Employee;
use App\Models\User;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\OrganizationScope\OrganizationScopeService;
use Throwable;

/**
 * The server-side context resolver (docs/assistant/context-and-tools.md).
 * Capabilities come from grants and settings; scope from
 * OrganizationScopeService; the page from the registry; a declared record
 * is resolved through the module's own authorization or dropped.
 */
final class AssistantContextResolver
{
    public function __construct(
        private readonly AssistantSettings $settings,
        private readonly IntelligenceSettings $intelligence,
        private readonly OrganizationScopeService $scope,
        private readonly LLMGateway $gateway,
    ) {}

    /** @param  array{route?: ?string, path?: ?string, record_type?: ?string, record_id?: ?string}  $page */
    public function resolve(User $user, array $page, ?string $locale = null): AssistantContext
    {
        $locale ??= app()->getLocale();
        $route = isset($page['route']) && is_string($page['route']) ? mb_substr($page['route'], 0, 190) : null;
        $path = isset($page['path']) && is_string($page['path']) ? mb_substr($page['path'], 0, 255) : '';
        $module = AssistantPageRegistry::module($route, $path);

        $business = array_filter(IntelligencePermissions::business(), fn (string $p): bool => $p !== IntelligencePermissions::KNOWLEDGE && $user->can($p));
        $capabilities = [
            'help' => true,
            'knowledge' => $this->settings->knowledgeEnabled() && $this->intelligence->knowledgeEnabled() && $user->can(IntelligencePermissions::KNOWLEDGE),
            'analytics' => $this->settings->analyticsEnabled() && $business !== [] && $user->can(IntelligencePermissions::VIEW),
            'diagnostics' => $this->settings->diagnosticsEnabled() && $user->can(IntelligencePermissions::VIEW) && $user->can(IntelligencePermissions::SYSTEM_HEALTH),
            'incidents' => $this->settings->diagnosticsEnabled() && $user->can(IntelligencePermissions::VIEW) && $user->can(IntelligencePermissions::INCIDENTS),
            'support' => $this->settings->supportEnabled(),
        ];

        return new AssistantContext(
            user: $user,
            locale: $locale,
            unrestricted: $this->scope->isUnrestricted($user),
            capabilities: $capabilities,
            page: [
                'route' => $route,
                'path' => $path,
                'module' => $module,
                'module_label' => AssistantPageRegistry::label($module),
                'help_available' => AssistantPageRegistry::hasGuidance($module),
                'route_group' => RouteGroups::of($route, $path),
                'record' => $this->record($user, $module, $page['record_type'] ?? null, $page['record_id'] ?? null, $locale),
            ],
            aiAvailable: $this->aiAvailable(),
        );
    }

    private function aiAvailable(): bool
    {
        try {
            return $this->gateway->available();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A record the page declared, resolved with the module's own rules and
     * reduced to labels (docs/assistant/privacy.md): never a model, never a
     * sensitive field. Anything not permitted resolves to nothing.
     *
     * @return array{type: string, id: string, label: string, summary: array<string, string|null>}|null
     */
    private function record(User $user, string $module, mixed $type, mixed $id, string $locale): ?array
    {
        if (! is_string($type) || ! is_string($id) || $id === '' || ! in_array($type, AssistantPageRegistry::recordTypes($module), true)) {
            return null;
        }
        if ($type === 'employee') {
            if (! $user->can('employees.view')) {
                return null;
            }
            $employee = Employee::query()->with(['currentAssignment.position', 'currentAssignment.organization'])->find($id);
            if ($employee === null || ! $this->scope->canAccessEmployee($user, $employee)) {
                return null;
            }
            $assignment = $employee->currentAssignment;
            $name = $locale === 'am' ? ($employee->fullNameAm() ?? $employee->fullNameEn()) : ($employee->fullNameEn() ?? $employee->fullNameAm());
            $status = $employee->status;

            return [
                'type' => 'employee', 'id' => (string) $employee->getKey(), 'label' => (string) $name,
                'summary' => [
                    'status' => is_object($status) && property_exists($status, 'value') ? (string) $status->value : (is_scalar($status) ? (string) $status : null),
                    'position' => $assignment?->position?->title_en ?? null,
                    'organization' => $assignment?->organization === null ? null : ($locale === 'am' ? ($assignment->organization->name_am ?? $assignment->organization->name_en) : ($assignment->organization->name_en ?? $assignment->organization->name_am)),
                ],
            ];
        }

        return null;
    }
}
