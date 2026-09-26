<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Policy;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaExtraScanPolicy;
use App\Enums\CafeteriaGrantStatus;
use App\Enums\CafeteriaPolicyStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServiceNetwork;
use App\Models\CafeteriaServicePolicy;
use App\Models\Organization;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cafeteria service policy lifecycle (docs/cafeteria-policy-architecture.md).
 *
 *   draft → under_review → approved → active → superseded | expired
 *                     ↘ back to draft          (or cancelled before it applies)
 *
 * Only drafts are edited. A change to an approved policy is a new version:
 * approval trims the previous version so it ends the day before, and nothing
 * already recorded is ever re-priced. Approvals lock the organization so two
 * policies for one scope can never both bind the same day.
 */
class CafeteriaPolicyWorkflowService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        return DB::transaction(function () use ($data, $actor, $request): CafeteriaServicePolicy {
            $assignment = CafeteriaServiceAssignment::query()->findOrFail($data['cafeteria_service_assignment_id'] ?? null);
            [$networkId, $cafeteriaId] = $this->scopeWithinAssignment($assignment, $data);
            $terms = $this->terms($data);
            $this->assertDates($data);

            $policy = CafeteriaServicePolicy::query()->create([
                ...$terms,
                'policy_group_id' => (string) Str::uuid7(),
                'version_no' => 1,
                'cafeteria_service_assignment_id' => $assignment->id,
                'organization_id' => $assignment->organization_id,
                'provider_id' => $assignment->provider_id,
                'cafeteria_service_network_id' => $networkId,
                'cafeteria_id' => $cafeteriaId,
                'scope_key' => CafeteriaServicePolicy::scopeKeyFor($assignment->organization_id, $assignment->provider_id, $networkId, $cafeteriaId),
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'status' => CafeteriaPolicyStatus::Draft->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->execute(AuditEventType::CafeteriaPolicyCreated, $actor, $policy, $policy->organization_id,
                newValues: $this->auditable($policy), request: $request);

            return $policy;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(CafeteriaServicePolicy $policy, array $data, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        if (! $policy->status->isEditable()) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_not_draft')]);
        }

        $terms = $this->terms($data);
        $this->assertDates($data);
        $old = $this->auditable($policy);

        $policy->fill([
            ...$terms,
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaPolicyUpdated, $actor, $policy, $policy->organization_id,
            oldValues: $old, newValues: $this->auditable($policy), request: $request);

        return $policy;
    }

    public function submit(CafeteriaServicePolicy $policy, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        $this->assertStatus($policy, CafeteriaPolicyStatus::Draft);

        $policy->forceFill([
            'status' => CafeteriaPolicyStatus::UnderReview->value,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaPolicySubmitted, $actor, $policy, $policy->organization_id,
            newValues: ['status' => $policy->status->value], request: $request);

        return $policy;
    }

    public function returnToDraft(CafeteriaServicePolicy $policy, User $actor, ?string $reason = null, ?Request $request = null): CafeteriaServicePolicy
    {
        $this->assertStatus($policy, CafeteriaPolicyStatus::UnderReview);

        $policy->forceFill([
            'status' => CafeteriaPolicyStatus::Draft->value,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaPolicyReturned, $actor, $policy, $policy->organization_id,
            newValues: ['status' => $policy->status->value], reason: $reason, request: $request);

        return $policy;
    }

    public function approve(CafeteriaServicePolicy $policy, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        return DB::transaction(function () use ($policy, $actor, $request): CafeteriaServicePolicy {
            $this->lockScope($policy);
            $policy->refresh();
            $this->assertStatus($policy, CafeteriaPolicyStatus::UnderReview);

            if (config('cafeteria.policy_requires_distinct_approver', true)
                && in_array($actor->id, array_filter([$policy->created_by, $policy->submitted_by]), true)) {
                throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_same_approver')]);
            }

            $assignment = CafeteriaServiceAssignment::query()->find($policy->cafeteria_service_assignment_id);
            if ($assignment === null || $assignment->status !== CafeteriaGrantStatus::Active
                || $assignment->effective_from->gt($policy->effective_from)
                || ($assignment->effective_to !== null && $assignment->effective_to->lt($policy->effective_from))) {
                throw ValidationException::withMessages(['cafeteria_service_assignment_id' => __('cafeteria-policy.validation.assignment_not_active')]);
            }

            $predecessor = $policy->supersedes;
            if ($predecessor !== null && $predecessor->status->isBinding()) {
                if ($policy->effective_from->lte($predecessor->effective_from)) {
                    throw ValidationException::withMessages(['effective_from' => __('cafeteria-policy.validation.overlapping_policy')]);
                }

                // The new version takes over: the old one ends the day before.
                if ($predecessor->effective_to === null || $predecessor->effective_to->gte($policy->effective_from)) {
                    $policy->forceFill([
                        'predecessor_trimmed' => true,
                        'predecessor_effective_to' => $predecessor->effective_to?->toDateString(),
                    ]);
                    $predecessor->forceFill(['effective_to' => $policy->effective_from->copy()->subDay()->toDateString()])->save();
                }
            }

            if ($this->overlapsBindingPolicy($policy)) {
                throw ValidationException::withMessages(['effective_from' => __('cafeteria-policy.validation.overlapping_policy')]);
            }

            $policy->forceFill([
                'status' => CafeteriaPolicyStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reviewed_by' => $policy->reviewed_by ?? $actor->id,
                'reviewed_at' => $policy->reviewed_at ?? now(),
            ])->save();

            $this->audit->execute(AuditEventType::CafeteriaPolicyApproved, $actor, $policy, $policy->organization_id,
                oldValues: $predecessor ? $this->terms($predecessor->only(CafeteriaServicePolicy::TERMS), validate: false) : null,
                newValues: [...$this->auditable($policy), 'changes' => $this->changes($predecessor, $policy)],
                request: $request);

            // Effective already: it becomes active at once.
            if ($policy->effective_from->lte(today())) {
                $this->markActive($policy, $actor, $request);
            }

            return $policy;
        });
    }

    public function activate(CafeteriaServicePolicy $policy, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        return DB::transaction(function () use ($policy, $actor, $request): CafeteriaServicePolicy {
            $this->lockScope($policy);
            $policy->refresh();
            $this->assertStatus($policy, CafeteriaPolicyStatus::Approved);

            if ($policy->effective_from->gt(today())) {
                throw ValidationException::withMessages(['effective_from' => __('cafeteria-policy.validation.policy_not_yet_effective')]);
            }

            $this->markActive($policy, $actor, $request);

            return $policy;
        });
    }

    /** Sets the last day a binding policy applies. Never retroactive. */
    public function end(CafeteriaServicePolicy $policy, Carbon $lastDay, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        return DB::transaction(function () use ($policy, $lastDay, $actor, $request): CafeteriaServicePolicy {
            $this->lockScope($policy);
            $policy->refresh();

            if (! in_array($policy->status, [CafeteriaPolicyStatus::Approved, CafeteriaPolicyStatus::Active], true)) {
                throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
            }
            if ($lastDay->lt($policy->effective_from) || $lastDay->lt(today())
                || ($policy->effective_to !== null && $lastDay->gt($policy->effective_to))) {
                throw ValidationException::withMessages(['effective_to' => __('cafeteria-policy.validation.end_before_start')]);
            }

            $old = $this->auditable($policy);
            $policy->forceFill([
                'effective_to' => $lastDay->toDateString(),
                'ended_by' => $actor->id,
                'ended_at' => now(),
            ])->save();

            $this->audit->execute(AuditEventType::CafeteriaPolicyEnded, $actor, $policy, $policy->organization_id,
                oldValues: $old, newValues: $this->auditable($policy), request: $request);

            return $policy;
        });
    }

    /** Cancels a policy that never took effect, restoring what it had trimmed. */
    public function cancel(CafeteriaServicePolicy $policy, User $actor, ?string $reason = null, ?Request $request = null): CafeteriaServicePolicy
    {
        return DB::transaction(function () use ($policy, $actor, $reason, $request): CafeteriaServicePolicy {
            $this->lockScope($policy);
            $policy->refresh();

            $cancellable = in_array($policy->status, [CafeteriaPolicyStatus::Draft, CafeteriaPolicyStatus::UnderReview], true)
                || ($policy->status === CafeteriaPolicyStatus::Approved && $policy->effective_from->gt(today()));
            if (! $cancellable) {
                throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
            }

            if ($policy->status === CafeteriaPolicyStatus::Approved && $policy->predecessor_trimmed && $policy->supersedes !== null) {
                $policy->supersedes->forceFill(['effective_to' => $policy->predecessor_effective_to?->toDateString()])->save();
            }

            $policy->forceFill([
                'status' => CafeteriaPolicyStatus::Cancelled->value,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->execute(AuditEventType::CafeteriaPolicyCancelled, $actor, $policy, $policy->organization_id,
                newValues: ['status' => $policy->status->value], reason: $reason, request: $request);

            return $policy;
        });
    }

    /**
     * Drafts the next version from a binding policy, prefilled with its terms.
     * One open (draft or under-review) version per policy at a time.
     */
    public function createNewVersion(CafeteriaServicePolicy $policy, User $actor, ?Request $request = null): CafeteriaServicePolicy
    {
        return DB::transaction(function () use ($policy, $actor, $request): CafeteriaServicePolicy {
            $this->lockScope($policy);
            // Copy the stored terms, not whatever this instance happens to hold.
            $policy = $policy->fresh();

            if (! in_array($policy->status, [CafeteriaPolicyStatus::Approved, CafeteriaPolicyStatus::Active], true)) {
                throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
            }

            $open = CafeteriaServicePolicy::query()
                ->where('policy_group_id', $policy->policy_group_id)
                ->whereIn('status', [CafeteriaPolicyStatus::Draft->value, CafeteriaPolicyStatus::UnderReview->value])
                ->first();
            if ($open !== null) {
                return $open;
            }

            $from = today()->addDay();
            if ($from->lte($policy->effective_from)) {
                $from = $policy->effective_from->copy()->addDay();
            }

            $version = CafeteriaServicePolicy::query()->create([
                ...$policy->only(CafeteriaServicePolicy::TERMS),
                'extra_scan_policy' => $policy->extra_scan_policy?->value,
                'policy_group_id' => $policy->policy_group_id,
                'version_no' => (int) CafeteriaServicePolicy::query()->where('policy_group_id', $policy->policy_group_id)->max('version_no') + 1,
                'cafeteria_service_assignment_id' => $policy->cafeteria_service_assignment_id,
                'organization_id' => $policy->organization_id,
                'provider_id' => $policy->provider_id,
                'cafeteria_service_network_id' => $policy->cafeteria_service_network_id,
                'cafeteria_id' => $policy->cafeteria_id,
                'scope_key' => $policy->scope_key,
                'effective_from' => $from->toDateString(),
                'effective_to' => $policy->effective_to?->gte($from) ? $policy->effective_to->toDateString() : null,
                'status' => CafeteriaPolicyStatus::Draft->value,
                'supersedes_policy_id' => $policy->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->execute(AuditEventType::CafeteriaPolicyRevised, $actor, $version, $version->organization_id,
                newValues: ['supersedes_policy_id' => $policy->id, 'version_no' => $version->version_no], request: $request);

            return $version;
        });
    }

    /**
     * Brings statuses up to date with the calendar (scheduled daily). The
     * resolver never depends on this: binding follows dates, status is bookkeeping.
     */
    public function syncStatuses(?Carbon $today = null): int
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $changed = 0;

        CafeteriaServicePolicy::query()
            ->where('status', CafeteriaPolicyStatus::Approved->value)
            ->whereDate('effective_from', '<=', $today->toDateString())
            ->get()
            ->each(function (CafeteriaServicePolicy $policy) use (&$changed): void {
                DB::transaction(fn () => $this->markActive($policy, null));
                $changed++;
            });

        $changed += CafeteriaServicePolicy::query()
            ->whereIn('status', [CafeteriaPolicyStatus::Active->value, CafeteriaPolicyStatus::Approved->value])
            ->whereNotNull('effective_to')
            ->whereDate('effective_to', '<', $today->toDateString())
            ->update(['status' => CafeteriaPolicyStatus::Expired->value]);

        return $changed;
    }

    /**
     * Current vs proposed terms and configuration warnings, for review.
     *
     * @return array<string, mixed>
     */
    public function preview(CafeteriaServicePolicy $policy): array
    {
        $current = $policy->supersedes ?? CafeteriaServicePolicy::query()
            ->where('scope_key', $policy->scope_key)
            ->whereKeyNot($policy->id)
            ->bindingOn($policy->effective_from->copy()->subDay())
            ->first();

        $warnings = [];

        if ($this->overlapsBindingPolicy($policy, ignore: $policy->supersedes_policy_id)) {
            $warnings[] = 'overlap';
        }

        $dayBefore = $policy->effective_from->copy()->subDay();
        $coveredBefore = CafeteriaServicePolicy::query()
            ->where('scope_key', $policy->scope_key)
            ->whereKeyNot($policy->id)
            ->bindingOn($dayBefore)
            ->exists();
        if (! $coveredBefore && $policy->version_no > 1) {
            $warnings[] = 'gap';
        }

        $assignment = $policy->assignment;
        if ($assignment === null || $assignment->status !== CafeteriaGrantStatus::Active) {
            $warnings[] = 'missing_provider_relationship';
        }

        $networkIds = $policy->cafeteria_service_network_id !== null
            ? [$policy->cafeteria_service_network_id]
            : CafeteriaServiceNetwork::query()->where('provider_id', $policy->provider_id)->pluck('id')->all();
        $hasAccess = OrganizationCafeteriaAccess::query()
            ->where('organization_id', $policy->organization_id)
            ->whereIn('cafeteria_service_network_id', $networkIds)
            ->effectiveOn($policy->effective_from->lt(today()) ? today() : $policy->effective_from)
            ->exists();
        if (! $hasAccess) {
            $warnings[] = 'missing_access_assignment';
        }

        return [
            'current' => $current ? [
                'id' => $current->id,
                'version_no' => $current->version_no,
                'effective_from' => $current->effective_from?->toDateString(),
                'effective_to' => $current->effective_to?->toDateString(),
                'terms' => $this->presentTerms($current),
            ] : null,
            'proposed' => [
                'id' => $policy->id,
                'version_no' => $policy->version_no,
                'effective_from' => $policy->effective_from?->toDateString(),
                'effective_to' => $policy->effective_to?->toDateString(),
                'terms' => $this->presentTerms($policy),
            ],
            'changes' => $this->changes($current, $policy),
            'warnings' => $warnings,
        ];
    }

    /**
     * Validated, normalized policy terms.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function terms(array $data, bool $validate = true): array
    {
        $terms = [
            'daily_subsidy_amount' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) ($data['daily_subsidy_amount'] ?? '0'))),
            'employee_contribution_amount' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) ($data['employee_contribution_amount'] ?? '0'))),
            'provider_price' => CafeteriaPricing::format(CafeteriaPricing::toCents((string) ($data['provider_price'] ?? '0'))),
            'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'ETB')),
            'max_daily_uses' => max(1, (int) ($data['max_daily_uses'] ?? 1)),
            'allow_advance_usage' => (bool) ($data['allow_advance_usage'] ?? false),
            'advance_max_days' => filled($data['advance_max_days'] ?? null) ? (int) $data['advance_max_days'] : null,
            'extra_scan_policy' => ($data['extra_scan_policy'] ?? null) instanceof CafeteriaExtraScanPolicy
                ? $data['extra_scan_policy']->value
                : (CafeteriaExtraScanPolicy::tryFrom((string) ($data['extra_scan_policy'] ?? ''))?->value ?? CafeteriaExtraScanPolicy::Block->value),
            'exclude_public_holidays' => (bool) ($data['exclude_public_holidays'] ?? true),
            'block_employee_leave' => (bool) ($data['block_employee_leave'] ?? true),
        ];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $terms["{$day}_enabled"] = (bool) ($data["{$day}_enabled"] ?? false);
        }

        if (! $validate) {
            return $terms;
        }

        $errors = [];
        $subsidy = CafeteriaPricing::toCents($terms['daily_subsidy_amount']);
        $contribution = CafeteriaPricing::toCents($terms['employee_contribution_amount']);
        $price = CafeteriaPricing::toCents($terms['provider_price']);

        if ($subsidy < 0 || $contribution < 0 || $price <= 0) {
            $errors['provider_price'] = __('cafeteria-policy.validation.price_mismatch');
        } elseif ($subsidy + $contribution !== $price) {
            $errors['provider_price'] = __('cafeteria-policy.validation.price_mismatch');
        }

        $hasWorkingDay = false;
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $hasWorkingDay = $hasWorkingDay || $terms["{$day}_enabled"];
        }
        if (! $hasWorkingDay) {
            $errors['monday_enabled'] = __('cafeteria-policy.validation.no_working_day');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $terms;
    }

    /**
     * The policy scope must equal or narrow its assignment's scope, inside
     * the assignment's provider.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: string|null}
     */
    private function scopeWithinAssignment(CafeteriaServiceAssignment $assignment, array $data): array
    {
        $networkId = filled($data['cafeteria_service_network_id'] ?? null) ? (string) $data['cafeteria_service_network_id'] : $assignment->cafeteria_service_network_id;
        $cafeteriaId = filled($data['cafeteria_id'] ?? null) ? (string) $data['cafeteria_id'] : $assignment->cafeteria_id;
        $mismatch = fn () => throw ValidationException::withMessages(['cafeteria_id' => __('cafeteria-policy.validation.assignment_scope_mismatch')]);

        if ($cafeteriaId !== null) {
            $cafeteria = CafeteriaProvider::query()->find($cafeteriaId);
            if ($cafeteria === null || $cafeteria->provider_id !== $assignment->provider_id) {
                $mismatch();
            }
            $networkId ??= $cafeteria->cafeteria_service_network_id;
            if ($cafeteria->cafeteria_service_network_id !== $networkId) {
                $mismatch();
            }
        }

        if ($networkId !== null && ! CafeteriaServiceNetwork::query()->whereKey($networkId)->where('provider_id', $assignment->provider_id)->exists()) {
            $mismatch();
        }

        if ($assignment->cafeteria_id !== null && $cafeteriaId !== $assignment->cafeteria_id) {
            $mismatch();
        }
        if ($assignment->cafeteria_service_network_id !== null && $networkId !== $assignment->cafeteria_service_network_id) {
            $mismatch();
        }

        return [$networkId, $cafeteriaId];
    }

    /** @param array<string, mixed> $data */
    private function assertDates(array $data): void
    {
        if (blank($data['effective_from'] ?? null)) {
            throw ValidationException::withMessages(['effective_from' => __('validation.required', ['attribute' => 'effective from'])]);
        }
        if (filled($data['effective_to'] ?? null) && Carbon::parse($data['effective_to'])->lt(Carbon::parse($data['effective_from']))) {
            throw ValidationException::withMessages(['effective_to' => __('cafeteria-policy.validation.end_before_start')]);
        }
    }

    private function assertStatus(CafeteriaServicePolicy $policy, CafeteriaPolicyStatus $expected): void
    {
        if ($policy->status !== $expected) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
        }
    }

    /** Serializes approvals for the organization and locks the scope's rows. */
    private function lockScope(CafeteriaServicePolicy $policy): void
    {
        Organization::query()->whereKey($policy->organization_id)->lockForUpdate()->first();
        CafeteriaServicePolicy::query()->where('scope_key', $policy->scope_key)->lockForUpdate()->get();
    }

    private function overlapsBindingPolicy(CafeteriaServicePolicy $policy, ?string $ignore = null): bool
    {
        return CafeteriaServicePolicy::query()
            ->where('scope_key', $policy->scope_key)
            ->whereKeyNot($policy->id)
            ->when($ignore !== null, fn (Builder $q) => $q->whereKeyNot($ignore))
            ->whereIn('status', CafeteriaPolicyStatus::binding())
            ->when($policy->effective_to !== null, fn (Builder $q) => $q->whereDate('effective_from', '<=', $policy->effective_to->toDateString()))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $policy->effective_from->toDateString()))
            ->exists();
    }

    private function markActive(CafeteriaServicePolicy $policy, ?User $actor, ?Request $request = null): void
    {
        $policy->forceFill(['status' => CafeteriaPolicyStatus::Active->value, 'activated_at' => now()])->save();

        $predecessor = $policy->supersedes;
        if ($predecessor !== null && in_array($predecessor->status, [CafeteriaPolicyStatus::Approved, CafeteriaPolicyStatus::Active], true)) {
            $predecessor->forceFill(['status' => CafeteriaPolicyStatus::Superseded->value])->save();
            $this->audit->execute(AuditEventType::CafeteriaPolicySuperseded, $actor, $predecessor, $predecessor->organization_id,
                newValues: ['superseded_by' => $policy->id, 'effective_to' => $predecessor->effective_to?->toDateString()], request: $request);
        }

        $this->audit->execute(AuditEventType::CafeteriaPolicyActivated, $actor, $policy, $policy->organization_id,
            newValues: ['status' => CafeteriaPolicyStatus::Active->value, 'effective_from' => $policy->effective_from->toDateString()], request: $request);
    }

    /** @return array<string, array{from: mixed, to: mixed}> subsidy, working-day and advance-rule changes */
    private function changes(?CafeteriaServicePolicy $from, CafeteriaServicePolicy $to): array
    {
        if ($from === null) {
            return [];
        }

        $before = $this->presentTerms($from);
        $after = $this->presentTerms($to);
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function presentTerms(CafeteriaServicePolicy $policy): array
    {
        return $this->terms([...$policy->only(CafeteriaServicePolicy::TERMS), 'extra_scan_policy' => $policy->extra_scan_policy], validate: false);
    }

    /** @return array<string, mixed> */
    private function auditable(CafeteriaServicePolicy $policy): array
    {
        return [
            'version_no' => $policy->version_no,
            'scope_key' => $policy->scope_key,
            'effective_from' => $policy->effective_from?->toDateString(),
            'effective_to' => $policy->effective_to?->toDateString(),
            'status' => $policy->status?->value,
            ...$this->presentTerms($policy),
        ];
    }
}
