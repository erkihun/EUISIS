<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\PublicFeedbackHandlerType;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\OrganizationUnit;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Routing of new Tip-offs (internal type `suggestion`) and Complaints (brief
 * §45–47). Rating feedback is not routed: it keeps the lightweight review
 * flow (§48).
 *
 * Rule precedence: a rule naming the organization beats a city-wide one, then
 * a named target type, then a named submission type, then a named tip type;
 * among equally specific rules the lowest priority
 * number wins, then the older rule. No match → UNROUTED and the routing
 * administrators are told; the submission never falls to the target employee
 * (§46).
 *
 * A Corruption Tip-off is routed ONLY by a rule that names the corruption tip
 * type. Rules written for suggestions before Tip-off types existed, and any
 * rule without a tip type, never receive one: with no designated handler it
 * stays UNROUTED for an authorized routing review. No corruption-investigation
 * route is assumed here (OFFICIAL_TIP_ROUTING_POLICY_REQUIRED).
 */
final class PublicFeedbackRoutingService
{
    public const PERMISSION = 'service_feedback.manage_routing';

    public function __construct(
        private readonly PublicFeedbackAssignmentService $assignments,
        private readonly PublicFeedbackWorkflow $workflow,
        private readonly PublicFeedbackNotifier $notifier,
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function resolve(EmployeeServiceFeedback $submission): ?PublicFeedbackRoutingRule
    {
        return $this->candidates($submission)->first();
    }

    /**
     * Every rule that matches the submission, most specific first. Routing
     * walks this list because the best rule may have become unusable since it
     * was written (its handler left the service, or now is the target): the
     * citizen's submission must still be stored and reach someone.
     *
     * @return Collection<int, PublicFeedbackRoutingRule>
     */
    public function candidates(EmployeeServiceFeedback $submission): Collection
    {
        if ($submission->type === ServiceFeedbackType::Feedback) {
            return new Collection;
        }

        return PublicFeedbackRoutingRule::query()
            ->inEffect()
            ->where(fn (Builder $q) => $q->whereNull('organization_id')
                ->when($submission->organization_id !== null, fn (Builder $q) => $q->orWhere('organization_id', $submission->organization_id)))
            ->where(fn (Builder $q) => $q->whereNull('target_type')->orWhere('target_type', $submission->target_type?->value ?? 'employee'))
            ->where(fn (Builder $q) => $q->whereNull('submission_type')->orWhere('submission_type', $submission->type->value))
            ->where(fn (Builder $q) => $submission->tip_type === PublicTipType::Corruption
                ? $q->where('tip_type', PublicTipType::Corruption->value)
                : $q->whereNull('tip_type')->when($submission->tip_type !== null, fn (Builder $q) => $q->orWhere('tip_type', $submission->tip_type->value)))
            // The relevant work unit the visitor named (organization form); "General / Not sure" matches only unit-less rules.
            ->where(fn (Builder $q) => $q->whereNull('organization_unit_id')
                ->when($submission->organization_unit_id !== null, fn (Builder $q) => $q->orWhere('organization_unit_id', $submission->organization_unit_id)))
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN organization_unit_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN target_type IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN submission_type IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN tip_type IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Called inside the submission transaction, right after the row exists.
     * A rule that cannot be applied (handler gone, handler is the target,
     * handler outside the institution) is skipped and logged by id; the
     * submission is never refused because of an internal misconfiguration.
     */
    public function route(EmployeeServiceFeedback $submission, ?Request $request = null): void
    {
        if ($submission->type === ServiceFeedbackType::Feedback || $submission->status !== ServiceFeedbackStatus::Pending) {
            return;
        }

        $skipped = [];

        foreach ($this->candidates($submission) as $rule) {
            try {
                $this->assignments->assign($submission, $rule->handler_type, $rule->handler_id, null, null, $rule, $request);

                return;
            } catch (ValidationException $e) {
                $skipped[] = $rule->getKey();
                Log::warning('public-feedback.routing.rule_skipped', [
                    'rule_id' => $rule->getKey(),
                    'handler_type' => $rule->handler_type->value,
                    'reason' => (string) ($e->errors()['handler_id'][0] ?? 'unusable'),
                ]);
            }
        }

        // No reason text for the reader: the UI explains an unrouted system transition in its own language.
        $this->workflow->transition($submission, ServiceFeedbackStatus::Unrouted, null, null, $request);
        $this->audit->execute(
            eventType: AuditEventType::ServiceFeedbackUnrouted,
            actor: null,
            auditable: $submission,
            organizationId: $submission->organization_id,
            reason: $skipped === []
                ? 'No routing rule matched this '.$submission->type->value
                : 'Matching routing rule(s) could not be applied: '.implode(', ', $skipped),
            request: $request,
        );
        $this->notifier->unrouted($submission);
    }

    public function canManage(User $user, ?string $organizationId): bool
    {
        if (! $user->hasAnyPermission([self::PERMISSION])) {
            return false;
        }

        return $organizationId === null
            ? $this->scope->isUnrestricted($user)
            : $this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, $organizationId);
    }

    /** @return Builder<PublicFeedbackRoutingRule> */
    public function queryFor(User $user): Builder
    {
        $query = PublicFeedbackRoutingRule::query()->with(['organization:id,name_en,name_am', 'unit:id,name_en,name_am']);

        if (! $this->scope->isUnrestricted($user)) {
            $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
        }

        return $query;
    }

    /** @param  array<string, mixed>  $data  validated */
    public function store(User $user, array $data, ?Request $request = null): PublicFeedbackRoutingRule
    {
        $attributes = $this->attributes($data);
        $this->assertRuleConsistent($attributes);

        $rule = PublicFeedbackRoutingRule::query()->create($attributes + ['created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
        $this->auditRule($user, $rule, 'created', $request);

        return $rule;
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(User $user, PublicFeedbackRoutingRule $rule, array $data, ?Request $request = null): PublicFeedbackRoutingRule
    {
        $attributes = $this->attributes($data);
        $this->assertRuleConsistent($attributes);

        $rule->fill($attributes + ['updated_by' => $user->getKey()])->save();
        $this->auditRule($user, $rule, 'updated', $request);

        return $rule;
    }

    /** @return 'deleted'|'retired' */
    public function destroy(User $user, PublicFeedbackRoutingRule $rule, ?Request $request = null): string
    {
        if ($rule->isReferenced()) {
            $rule->forceFill(['is_active' => false, 'updated_by' => $user->getKey()])->save();
            $this->auditRule($user, $rule, 'retired', $request);

            return 'retired';
        }

        $this->auditRule($user, $rule, 'deleted', $request);
        $rule->delete();

        return 'deleted';
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'organization_id' => $data['organization_id'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'submission_type' => $data['submission_type'] ?? null,
            // A tip type narrows only Tip-off rules; the request refuses it on a complaint rule.
            'tip_type' => ($data['submission_type'] ?? null) === 'complaint' ? null : ($data['tip_type'] ?? null),
            // A unit narrows only an institution's own rule; it is never the handler.
            'organization_unit_id' => ($data['organization_id'] ?? null) === null ? null : ($data['organization_unit_id'] ?? null),
            'handler_type' => PublicFeedbackHandlerType::from($data['handler_type']),
            'handler_id' => (string) $data['handler_id'],
            'priority' => (int) ($data['priority'] ?? 100),
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * The handler must be reachable from the rule's organization (a city-wide
     * rule cannot name one institution's unit, nor a user confined to one
     * institution).
     *
     * @throws ValidationException
     */
    private function assertRuleConsistent(array $attributes): void
    {
        /** @var PublicFeedbackHandlerType $type */
        $type = $attributes['handler_type'];

        if ($type->names($attributes['handler_id']) === null) {
            throw ValidationException::withMessages(['handler_id' => __('service-feedback.handler_invalid')]);
        }

        $this->assignments->assertHandlerWithinOrganization($type, $attributes['handler_id'], $attributes['organization_id']);

        if ($attributes['organization_unit_id'] !== null) {
            $unit = OrganizationUnit::query()->find($attributes['organization_unit_id']);

            if ($unit === null || $unit->organization_id !== $attributes['organization_id']) {
                throw ValidationException::withMessages(['organization_unit_id' => __('service-feedback.unit_out_of_scope')]);
            }
        }
    }

    private function auditRule(User $user, PublicFeedbackRoutingRule $rule, string $action, ?Request $request): void
    {
        $this->audit->execute(
            eventType: $action === 'deleted' ? AuditEventType::ServiceFeedbackRoutingRuleDeleted : AuditEventType::ServiceFeedbackRoutingRuleSaved,
            actor: $user,
            auditable: $rule,
            organizationId: $rule->organization_id,
            newValues: [
                'organization_id' => $rule->organization_id, 'target_type' => $rule->target_type, 'submission_type' => $rule->submission_type,
                'handler' => $rule->handler_type->value.':'.$rule->handler_id, 'priority' => $rule->priority,
                'is_active' => $rule->is_active,
            ],
            reason: 'Public feedback routing rule '.$action,
            request: $request,
        );
    }
}
