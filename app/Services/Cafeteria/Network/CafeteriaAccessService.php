<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Network;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaGrantStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceNetwork;
use App\Models\OrganizationCafeteriaAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organization ↔ cafeteria network access (docs/cafeteria-network-access.md).
 * Access is explicit, dated and approved; a primary cafeteria is only the
 * default location, and cross-location usage opens the rest of the network.
 */
class CafeteriaAccessService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?Request $request = null): OrganizationCafeteriaAccess
    {
        return DB::transaction(function () use ($data, $actor, $request): OrganizationCafeteriaAccess {
            $this->validate($data);

            $access = OrganizationCafeteriaAccess::query()->create([
                'organization_id' => $data['organization_id'],
                'cafeteria_service_network_id' => $data['cafeteria_service_network_id'],
                'primary_cafeteria_id' => $data['primary_cafeteria_id'] ?? null,
                'allow_cross_location_usage' => (bool) ($data['allow_cross_location_usage'] ?? false),
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'status' => CafeteriaGrantStatus::PendingApproval->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncExceptions($access, $data['location_exceptions'] ?? [], $actor);

            $this->audit->execute(AuditEventType::CafeteriaAccessGranted, $actor, $access, $access->organization_id,
                newValues: $this->auditable($access), request: $request);

            return $access;
        });
    }

    /**
     * Organization and network never change; a different pair is new access.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(OrganizationCafeteriaAccess $access, array $data, User $actor, ?Request $request = null): OrganizationCafeteriaAccess
    {
        return DB::transaction(function () use ($access, $data, $actor, $request): OrganizationCafeteriaAccess {
            $data = [
                ...$data,
                'organization_id' => $access->organization_id,
                'cafeteria_service_network_id' => $access->cafeteria_service_network_id,
            ];
            $this->validate($data, $access);

            $old = $this->auditable($access);
            $access->fill([
                'primary_cafeteria_id' => $data['primary_cafeteria_id'] ?? null,
                'allow_cross_location_usage' => (bool) ($data['allow_cross_location_usage'] ?? false),
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            if (array_key_exists('location_exceptions', $data)) {
                $this->syncExceptions($access, $data['location_exceptions'] ?? [], $actor);
            }

            // Primary cafeteria and cross-location changes are audited with before/after.
            $this->audit->execute(AuditEventType::CafeteriaAccessUpdated, $actor, $access, $access->organization_id,
                oldValues: $old, newValues: $this->auditable($access->fresh('locationExceptions')), request: $request);

            return $access;
        });
    }

    public function approve(OrganizationCafeteriaAccess $access, User $actor, ?Request $request = null): OrganizationCafeteriaAccess
    {
        if ($access->status !== CafeteriaGrantStatus::PendingApproval) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
        }

        $access->forceFill([
            'status' => CafeteriaGrantStatus::Active->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaAccessApproved, $actor, $access, $access->organization_id,
            newValues: $this->auditable($access), request: $request);

        return $access;
    }

    /** Ends access from a date (the last day it still applies). */
    public function end(OrganizationCafeteriaAccess $access, Carbon $lastDay, User $actor, ?Request $request = null): OrganizationCafeteriaAccess
    {
        if (! in_array($access->status, [CafeteriaGrantStatus::Active, CafeteriaGrantStatus::PendingApproval], true)) {
            throw ValidationException::withMessages(['status' => __('cafeteria-policy.validation.policy_wrong_status')]);
        }
        if ($lastDay->lt($access->effective_from)) {
            throw ValidationException::withMessages(['effective_to' => __('cafeteria-policy.validation.end_before_start')]);
        }

        $old = $this->auditable($access);
        $access->forceFill([
            'effective_to' => $lastDay->toDateString(),
            // Pending access that ends is simply withdrawn.
            'status' => $access->status === CafeteriaGrantStatus::PendingApproval ? CafeteriaGrantStatus::Cancelled->value : $access->status->value,
            'ended_by' => $actor->id,
            'ended_at' => now(),
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaAccessEnded, $actor, $access, $access->organization_id,
            oldValues: $old, newValues: $this->auditable($access), request: $request);

        return $access;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function validate(array $data, ?OrganizationCafeteriaAccess $existing = null): void
    {
        $network = CafeteriaServiceNetwork::query()->find($data['cafeteria_service_network_id'] ?? null);
        $errors = [];

        if ($network === null) {
            $errors['cafeteria_service_network_id'] = __('validation.exists', ['attribute' => 'network']);
        }

        $primaryId = $data['primary_cafeteria_id'] ?? null;
        if ($network !== null && $primaryId !== null
            && ! CafeteriaProvider::query()->whereKey($primaryId)->where('cafeteria_service_network_id', $network->id)->exists()) {
            $errors['primary_cafeteria_id'] = __('cafeteria-policy.validation.cafeteria_not_in_network');
        }

        if ($primaryId === null && ! ($data['allow_cross_location_usage'] ?? false)) {
            $errors['primary_cafeteria_id'] = __('cafeteria-policy.validation.primary_required');
        }

        if (filled($data['effective_to'] ?? null) && Carbon::parse($data['effective_to'])->lt(Carbon::parse($data['effective_from']))) {
            $errors['effective_to'] = __('cafeteria-policy.validation.end_before_start');
        }

        foreach ($data['location_exceptions'] ?? [] as $index => $exception) {
            if ($network !== null && ! CafeteriaProvider::query()->whereKey($exception['cafeteria_id'] ?? null)->where('cafeteria_service_network_id', $network->id)->exists()) {
                $errors["location_exceptions.{$index}.cafeteria_id"] = __('cafeteria-policy.validation.cafeteria_not_in_network');
            }
        }

        if ($errors === [] && $this->overlapping($data, $existing)) {
            $errors['effective_from'] = __('cafeteria-policy.validation.overlapping_access');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<array<string, mixed>> $exceptions */
    private function syncExceptions(OrganizationCafeteriaAccess $access, array $exceptions, User $actor): void
    {
        $access->locationExceptions()->delete();

        foreach ($exceptions as $exception) {
            $access->locationExceptions()->create([
                'cafeteria_id' => $exception['cafeteria_id'],
                'is_allowed' => (bool) $exception['is_allowed'],
                'effective_from' => $exception['effective_from'] ?? $access->effective_from->toDateString(),
                'effective_to' => $exception['effective_to'] ?? null,
                'created_by' => $actor->id,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function overlapping(array $data, ?OrganizationCafeteriaAccess $existing): bool
    {
        return OrganizationCafeteriaAccess::query()
            ->where('organization_id', $data['organization_id'])
            ->where('cafeteria_service_network_id', $data['cafeteria_service_network_id'])
            ->whereIn('status', [CafeteriaGrantStatus::PendingApproval->value, CafeteriaGrantStatus::Active->value, CafeteriaGrantStatus::Suspended->value])
            ->when($existing !== null, fn (Builder $q) => $q->whereKeyNot($existing->id))
            ->when(filled($data['effective_to'] ?? null), fn (Builder $q) => $q->whereDate('effective_from', '<=', $data['effective_to']))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->lockForUpdate()
            ->exists();
    }

    /** @return array<string, mixed> */
    private function auditable(OrganizationCafeteriaAccess $access): array
    {
        return [
            'organization_id' => $access->organization_id,
            'cafeteria_service_network_id' => $access->cafeteria_service_network_id,
            'primary_cafeteria_id' => $access->primary_cafeteria_id,
            'allow_cross_location_usage' => $access->allow_cross_location_usage,
            'effective_from' => $access->effective_from?->toDateString(),
            'effective_to' => $access->effective_to?->toDateString(),
            'status' => $access->status?->value,
            'location_exceptions' => $access->locationExceptions()->get(['cafeteria_id', 'is_allowed'])->toArray(),
        ];
    }
}
