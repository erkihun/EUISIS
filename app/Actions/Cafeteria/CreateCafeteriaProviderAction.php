<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaLocationType;
use App\Models\CafeteriaProvider;
use App\Models\Provider;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Cafeteria\Network\CafeteriaNetworkService;
use App\Services\Cafeteria\Network\CafeteriaProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a cafeteria location end to end: the operating provider (payee)
 * — reusing one or registering a new one with its cafeteria service — the
 * network it belongs to (a main cafeteria may start a new network), the
 * validated placement, and the legacy service-provider registry link that
 * terminals and service transactions still use.
 */
readonly class CreateCafeteriaProviderAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private CafeteriaNetworkService $networks,
        private CafeteriaProviderRegistry $providers,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor, ?Request $request = null): CafeteriaProvider
    {
        return DB::transaction(function () use ($attributes, $actor, $request): CafeteriaProvider {
            $payee = ($attributes['provider_mode'] ?? 'existing') === 'new'
                ? $this->providers->create([
                    'provider_code' => $attributes['provider_code'],
                    'name_en' => $attributes['provider_name_en'],
                    'name_am' => $attributes['provider_name_am'] ?? null,
                    'contact_person' => $attributes['contact_person'] ?? null,
                    'phone_number' => $attributes['phone_number'] ?? null,
                    'email' => $attributes['email'] ?? null,
                    'address' => $attributes['location'] ?? null,
                ], $actor)
                : Provider::query()->findOrFail($attributes['provider_id']);
            $this->providers->ensureCafeteriaService($payee, $actor);

            $type = CafeteriaLocationType::from($attributes['location_type'] ?? CafeteriaLocationType::Main->value);
            $networkId = ($attributes['network_mode'] ?? 'existing') === 'new'
                ? $this->networks->createNetwork([
                    'provider_id' => $payee->id,
                    'code' => $attributes['network_code'],
                    'name_en' => $attributes['network_name_en'],
                    'name_am' => $attributes['network_name_am'] ?? null,
                ], $actor, $request)->id
                : ($attributes['cafeteria_service_network_id'] ?? null);

            $parentId = $type === CafeteriaLocationType::Main ? null : ($attributes['parent_cafeteria_id'] ?? null);
            if ($type !== CafeteriaLocationType::Main && $parentId === null && $networkId !== null) {
                // A branch without an explicit parent sits under the network's main cafeteria.
                $parentId = CafeteriaProvider::query()->where('cafeteria_service_network_id', $networkId)
                    ->where('location_type', CafeteriaLocationType::Main->value)->value('id');
            }
            $this->networks->assertPlacement(null, $payee->id, $networkId, $parentId, $type);

            $cafeteria = CafeteriaProvider::query()->create([
                'provider_id' => $payee->id,
                'cafeteria_service_network_id' => $networkId,
                'parent_cafeteria_id' => $parentId,
                'location_type' => $type->value,
                'operational_status' => $attributes['operational_status'] ?? 'open',
                'opening_time' => $attributes['opening_time'] ?? null,
                'closing_time' => $attributes['closing_time'] ?? null,
                'capacity' => $attributes['capacity'] ?? null,
                'code' => strtoupper(trim((string) $attributes['code'])),
                'name_en' => trim((string) $attributes['name_en']),
                'name_am' => filled($attributes['name_am'] ?? null) ? trim((string) $attributes['name_am']) : null,
                'organization_id' => $attributes['organization_id'] ?? null,
                'assigned_scope_type' => 'self',
                'contact_person' => $attributes['contact_person'] ?? null,
                'phone_number' => $attributes['phone_number'] ?? null,
                'email' => $attributes['email'] ?? null,
                'location' => $attributes['location'] ?? null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->linkToServiceProviderRegistry($cafeteria);

            $placement = [
                'code' => $cafeteria->code,
                'name_en' => $cafeteria->name_en,
                'provider_id' => $cafeteria->provider_id,
                'cafeteria_service_network_id' => $cafeteria->cafeteria_service_network_id,
                'parent_cafeteria_id' => $cafeteria->parent_cafeteria_id,
                'location_type' => $cafeteria->location_type?->value,
                'organization_id' => $cafeteria->organization_id,
            ];
            $this->writeAuditLogAction->execute(AuditEventType::CafeteriaProviderCreated, $actor, $cafeteria, $cafeteria->organization_id,
                newValues: $placement, request: $request);
            $this->writeAuditLogAction->execute(AuditEventType::CafeteriaLocationAdded, $actor, $cafeteria, $cafeteria->organization_id,
                newValues: $placement, request: $request);

            return $cafeteria;
        });
    }

    private function linkToServiceProviderRegistry(CafeteriaProvider $cafeteria): void
    {
        $cafeteriaType = ServiceType::query()->where('code', 'cafeteria')->first();
        if ($cafeteriaType === null) {
            return;
        }

        $code = $cafeteria->code;
        if (ServiceProvider::query()->where('code', $code)->exists()) {
            $code = 'CAF-'.$code.'-'.Str::upper(Str::random(4));
        }

        $serviceProvider = ServiceProvider::query()->create([
            'service_type_id' => $cafeteriaType->id,
            'organization_id' => $cafeteria->organization_id,
            'name' => $cafeteria->name_en,
            'code' => $code,
            'status' => $cafeteria->is_active ? 'active' : 'inactive',
            'is_demo' => false,
        ]);

        $cafeteria->withoutTimestamps(fn () => $cafeteria->updateQuietly(['service_provider_id' => $serviceProvider->id]));
    }
}
