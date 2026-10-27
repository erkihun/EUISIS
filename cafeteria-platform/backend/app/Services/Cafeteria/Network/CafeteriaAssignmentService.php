<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Network;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaGrantStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceAssignment;
use App\Models\CafeteriaServiceNetwork;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organization ↔ provider (↔ network | cafeteria) service assignments: which
 * provider is contractually authorized to serve an organization. Policies
 * attach here; where employees may eat is organization access.
 */
class CafeteriaAssignmentService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?Request $request = null): CafeteriaServiceAssignment
    {
        return DB::transaction(function () use ($data, $actor, $request): CafeteriaServiceAssignment {
            $data = $this->normalize($data);
            $this->validate($data);

            $assignment = CafeteriaServiceAssignment::query()->create([
                'organization_id' => $data['organization_id'],
                'provider_id' => $data['provider_id'],
                'cafeteria_service_network_id' => $data['cafeteria_service_network_id'],
                'cafeteria_id' => $data['cafeteria_id'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'status' => CafeteriaGrantStatus::PendingApproval->value,
                'notes' => $data['notes'] ?? null,
                'assigned_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->execute(AuditEventType::CafeteriaAssignmentCreated, $actor, $assignment, $assignment->organization_id,
                newValues: $this->auditable($assignment), request: $request);

            return $assignment;
        });
    }

    /**
     * Only pending assignments change freely; an active one only gets a new
     * end date (end()), so policies attached to it keep their meaning.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CafeteriaServiceAssignment $assignment, array $data, User $actor, ?Request $request = null): CafeteriaServiceAssignment
    {
        if ($assignment->status !== CafeteriaGrantStatus::PendingApproval) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
        }

        return DB::transaction(function () use ($assignment, $data, $actor, $request): CafeteriaServiceAssignment {
            $data = $this->normalize([...$data, 'organization_id' => $assignment->organization_id]);
            $this->validate($data, $assignment);

            $old = $this->auditable($assignment);
            $assignment->fill([
                'provider_id' => $data['provider_id'],
                'cafeteria_service_network_id' => $data['cafeteria_service_network_id'],
                'cafeteria_id' => $data['cafeteria_id'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->execute(AuditEventType::CafeteriaAssignmentUpdated, $actor, $assignment, $assignment->organization_id,
                oldValues: $old, newValues: $this->auditable($assignment), request: $request);

            return $assignment;
        });
    }

    public function approve(CafeteriaServiceAssignment $assignment, User $actor, ?Request $request = null): CafeteriaServiceAssignment
    {
        if ($assignment->status !== CafeteriaGrantStatus::PendingApproval) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
        }

        $assignment->forceFill([
            'status' => CafeteriaGrantStatus::Active->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaAssignmentApproved, $actor, $assignment, $assignment->organization_id,
            newValues: $this->auditable($assignment), request: $request);

        return $assignment;
    }

    public function end(CafeteriaServiceAssignment $assignment, Carbon $lastDay, User $actor, ?Request $request = null): CafeteriaServiceAssignment
    {
        if (! in_array($assignment->status, [CafeteriaGrantStatus::Active, CafeteriaGrantStatus::PendingApproval], true)) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
        }
        if ($lastDay->lt($assignment->effective_from)) {
            throw ValidationException::withMessages(['effective_to' => __('cafeteria-policy.validation.end_before_start')]);
        }

        $old = $this->auditable($assignment);
        $assignment->forceFill([
            'effective_to' => $lastDay->toDateString(),
            'status' => $assignment->status === CafeteriaGrantStatus::PendingApproval ? CafeteriaGrantStatus::Cancelled->value : $assignment->status->value,
            'ended_by' => $actor->id,
            'ended_at' => now(),
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaAssignmentEnded, $actor, $assignment, $assignment->organization_id,
            oldValues: $old, newValues: $this->auditable($assignment), request: $request);

        return $assignment;
    }

    /**
     * A cafeteria implies its network; the provider must own both.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $data['cafeteria_id'] = filled($data['cafeteria_id'] ?? null) ? $data['cafeteria_id'] : null;
        $data['cafeteria_service_network_id'] = filled($data['cafeteria_service_network_id'] ?? null) ? $data['cafeteria_service_network_id'] : null;

        if ($data['cafeteria_id'] !== null && $data['cafeteria_service_network_id'] === null) {
            $data['cafeteria_service_network_id'] = CafeteriaProvider::query()->whereKey($data['cafeteria_id'])->value('cafeteria_service_network_id');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function validate(array $data, ?CafeteriaServiceAssignment $existing = null): void
    {
        $errors = [];

        if (! Organization::query()->whereKey($data['organization_id'] ?? null)->exists()) {
            $errors['organization_id'] = __('validation.exists', ['attribute' => 'organization']);
        }

        $provider = Provider::query()->find($data['provider_id'] ?? null);
        if ($provider === null) {
            $errors['provider_id'] = __('validation.exists', ['attribute' => 'provider']);
        }

        if ($provider !== null && $data['cafeteria_service_network_id'] !== null
            && ! CafeteriaServiceNetwork::query()->whereKey($data['cafeteria_service_network_id'])->where('provider_id', $provider->id)->exists()) {
            $errors['cafeteria_service_network_id'] = __('cafeteria-policy.validation.network_not_in_provider');
        }

        if ($provider !== null && $data['cafeteria_id'] !== null) {
            $cafeteria = CafeteriaProvider::query()->find($data['cafeteria_id']);
            if ($cafeteria === null || $cafeteria->provider_id !== $provider->id) {
                $errors['cafeteria_id'] = __('cafeteria-policy.validation.cafeteria_not_in_provider');
            } elseif ($cafeteria->cafeteria_service_network_id !== $data['cafeteria_service_network_id']) {
                $errors['cafeteria_id'] = __('cafeteria-policy.validation.cafeteria_not_in_network');
            }
        }

        if (filled($data['effective_to'] ?? null) && Carbon::parse($data['effective_to'])->lt(Carbon::parse($data['effective_from']))) {
            $errors['effective_to'] = __('cafeteria-policy.validation.end_before_start');
        }

        if ($errors === [] && $this->overlapping($data, $existing)) {
            $errors['effective_from'] = __('cafeteria-policy.validation.overlapping_assignment');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $data */
    private function overlapping(array $data, ?CafeteriaServiceAssignment $existing): bool
    {
        return CafeteriaServiceAssignment::query()
            ->where('organization_id', $data['organization_id'])
            ->where('provider_id', $data['provider_id'])
            ->where(fn (Builder $q) => $data['cafeteria_service_network_id'] === null ? $q->whereNull('cafeteria_service_network_id') : $q->where('cafeteria_service_network_id', $data['cafeteria_service_network_id']))
            ->where(fn (Builder $q) => $data['cafeteria_id'] === null ? $q->whereNull('cafeteria_id') : $q->where('cafeteria_id', $data['cafeteria_id']))
            ->whereIn('status', [CafeteriaGrantStatus::PendingApproval->value, CafeteriaGrantStatus::Active->value, CafeteriaGrantStatus::Suspended->value])
            ->when($existing !== null, fn (Builder $q) => $q->whereKeyNot($existing->id))
            ->when(filled($data['effective_to'] ?? null), fn (Builder $q) => $q->whereDate('effective_from', '<=', $data['effective_to']))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->lockForUpdate()
            ->exists();
    }

    /** @return array<string, mixed> */
    private function auditable(CafeteriaServiceAssignment $assignment): array
    {
        return [
            'organization_id' => $assignment->organization_id,
            'provider_id' => $assignment->provider_id,
            'cafeteria_service_network_id' => $assignment->cafeteria_service_network_id,
            'cafeteria_id' => $assignment->cafeteria_id,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'status' => $assignment->status?->value,
        ];
    }
}
