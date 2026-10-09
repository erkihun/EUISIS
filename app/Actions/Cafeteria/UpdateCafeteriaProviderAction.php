<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaLocationType;
use App\Models\CafeteriaProvider;
use App\Models\Provider;
use App\Models\User;
use App\Services\Cafeteria\Network\CafeteriaNetworkService;
use App\Services\Cafeteria\Network\CafeteriaProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates a cafeteria location's identity, placement and operations. The
 * operating provider never changes here (a new operator is a new location),
 * so recorded transactions keep their payee.
 */
readonly class UpdateCafeteriaProviderAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private CafeteriaNetworkService $networks,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(CafeteriaProvider $provider, array $attributes, User $actor, ?Request $request = null): CafeteriaProvider
    {
        return DB::transaction(function () use ($provider, $attributes, $actor, $request): CafeteriaProvider {
            $placementKeys = ['cafeteria_service_network_id', 'parent_cafeteria_id', 'location_type', 'operational_status', 'opening_time', 'closing_time'];
            $old = [
                'name_en' => $provider->name_en,
                'is_active' => $provider->is_active,
                'organization_id' => $provider->organization_id,
                ...collect($placementKeys)->mapWithKeys(fn (string $key) => [$key => $this->plain($provider->getAttribute($key))])->all(),
            ];

            // A legacy location without a provider may be adopted once; a set provider never changes.
            $payeeId = $provider->provider_id;
            if (filled($attributes['provider_id'] ?? null) && $attributes['provider_id'] !== $payeeId) {
                if ($payeeId !== null) {
                    throw ValidationException::withMessages(['provider_id' => __('cafeteria-policy.validation.cafeteria_not_in_provider')]);
                }
                $payeeId = $attributes['provider_id'];
                app(CafeteriaProviderRegistry::class)->ensureCafeteriaService(Provider::query()->findOrFail($payeeId), $actor);
            }

            $type = CafeteriaLocationType::from($attributes['location_type'] ?? $provider->location_type?->value ?? CafeteriaLocationType::Main->value);
            $networkId = array_key_exists('cafeteria_service_network_id', $attributes)
                ? ($attributes['cafeteria_service_network_id'] ?: null)
                : $provider->cafeteria_service_network_id;
            $parentId = $type === CafeteriaLocationType::Main ? null : ($attributes['parent_cafeteria_id'] ?? null);
            $this->networks->assertPlacement($provider, (string) $payeeId, $networkId, $parentId, $type);

            $provider->forceFill([
                'provider_id' => $payeeId,
                'name_en' => trim((string) $attributes['name_en']),
                'name_am' => filled($attributes['name_am'] ?? null) ? trim((string) $attributes['name_am']) : null,
                'organization_id' => $attributes['organization_id'] ?? null,
                'cafeteria_service_network_id' => $networkId,
                'parent_cafeteria_id' => $parentId,
                'location_type' => $type->value,
                'operational_status' => $attributes['operational_status'] ?? $provider->operational_status?->value ?? 'open',
                'opening_time' => $attributes['opening_time'] ?? null,
                'closing_time' => $attributes['closing_time'] ?? null,
                'capacity' => $attributes['capacity'] ?? null,
                'contact_person' => $attributes['contact_person'] ?? null,
                'phone_number' => $attributes['phone_number'] ?? null,
                'email' => $attributes['email'] ?? null,
                'location' => $attributes['location'] ?? null,
                'is_active' => isset($attributes['is_active']) ? (bool) $attributes['is_active'] : $provider->is_active,
                'updated_by' => $actor->id,
            ])->save();

            // Keep the ServiceProvider registry in sync.
            $provider->serviceProvider?->update([
                'name' => $provider->name_en,
                'organization_id' => $provider->organization_id,
                'status' => $provider->is_active ? 'active' : 'inactive',
            ]);

            $new = [
                'name_en' => $provider->name_en,
                'is_active' => $provider->is_active,
                'organization_id' => $provider->organization_id,
                ...collect($placementKeys)->mapWithKeys(fn (string $key) => [$key => $this->plain($provider->getAttribute($key))])->all(),
            ];

            $this->writeAuditLogAction->execute(AuditEventType::CafeteriaProviderUpdated, $actor, $provider, $provider->organization_id,
                oldValues: $old, newValues: $new, request: $request);

            // A move between networks, a closure or new hours is its own audit event.
            if (array_intersect_key($old, array_flip($placementKeys)) !== array_intersect_key($new, array_flip($placementKeys))) {
                $this->writeAuditLogAction->execute(AuditEventType::CafeteriaLocationUpdated, $actor, $provider, $provider->organization_id,
                    oldValues: array_intersect_key($old, array_flip($placementKeys)),
                    newValues: array_intersect_key($new, array_flip($placementKeys)), request: $request);
            }

            return $provider;
        });
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
