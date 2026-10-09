<?php

declare(strict_types=1);

namespace App\Services\Cafeteria\Network;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\CafeteriaLocationType;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceNetwork;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Structure of a provider's cafeteria networks (docs/cafeteria-network-access.md).
 *
 * The parent/child hierarchy is for display and grouping only: it never
 * grants access. Every location in a network belongs to the network's
 * provider, a network has at most one main cafeteria, a parent must sit in
 * the same network, and no chain of parents may loop.
 */
class CafeteriaNetworkService
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array<string, mixed> $data */
    public function createNetwork(array $data, User $actor, ?Request $request = null): CafeteriaServiceNetwork
    {
        $network = CafeteriaServiceNetwork::query()->create([
            'provider_id' => $data['provider_id'],
            'code' => strtoupper(trim((string) $data['code'])),
            'name_en' => trim((string) $data['name_en']),
            'name_am' => filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit->execute(AuditEventType::CafeteriaNetworkCreated, $actor, $network, null,
            newValues: $network->only(['provider_id', 'code', 'name_en', 'status']), request: $request);

        return $network;
    }

    /** @param array<string, mixed> $data */
    public function updateNetwork(CafeteriaServiceNetwork $network, array $data, User $actor, ?Request $request = null): CafeteriaServiceNetwork
    {
        $old = $network->only(['name_en', 'name_am', 'description', 'status']);
        $network->fill([
            'name_en' => trim((string) $data['name_en']),
            'name_am' => filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? $network->status,
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->execute(AuditEventType::CafeteriaNetworkUpdated, $actor, $network, null,
            oldValues: $old, newValues: $network->only(array_keys($old)), request: $request);

        return $network;
    }

    /**
     * Validate where a cafeteria sits. $cafeteria is null when it is being created.
     *
     * @throws ValidationException
     */
    public function assertPlacement(?CafeteriaProvider $cafeteria, string $providerId, ?string $networkId, ?string $parentId, CafeteriaLocationType $type): void
    {
        $fail = fn (string $field, string $key) => throw ValidationException::withMessages([$field => __('cafeteria-policy.validation.'.$key)]);

        if ($type === CafeteriaLocationType::Main && $parentId !== null) {
            $fail('parent_cafeteria_id', 'main_has_parent');
        }

        if ($networkId === null) {
            if ($type !== CafeteriaLocationType::Main) {
                $fail('cafeteria_service_network_id', 'branch_needs_network');
            }

            return;
        }

        $network = CafeteriaServiceNetwork::query()->find($networkId);
        if ($network === null || $network->provider_id !== $providerId) {
            $fail('cafeteria_service_network_id', 'network_not_in_provider');
        }

        if ($type === CafeteriaLocationType::Main) {
            $otherMain = CafeteriaProvider::query()
                ->where('cafeteria_service_network_id', $networkId)
                ->where('location_type', CafeteriaLocationType::Main->value)
                ->when($cafeteria !== null, fn ($q) => $q->whereKeyNot($cafeteria->id))
                ->exists();

            if ($otherMain) {
                $fail('location_type', 'network_main_exists');
            }

            return;
        }

        if ($parentId === null) {
            return;
        }

        $parent = CafeteriaProvider::query()->find($parentId);
        if ($parent === null || $parent->cafeteria_service_network_id !== $networkId || $parent->provider_id !== $providerId) {
            $fail('parent_cafeteria_id', 'parent_not_in_network');
        }

        if ($cafeteria !== null && $this->createsCycle($cafeteria->id, $parent)) {
            $fail('parent_cafeteria_id', 'parent_cycle');
        }
    }

    /**
     * The network as a tree for display: roots are locations without a parent
     * (normally the main cafeteria), each with its children.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(CafeteriaServiceNetwork $network): array
    {
        $locations = $network->cafeterias()->withTrashed()->orderBy('name_en')->get();

        $build = function (?string $parentId) use (&$build, $locations): array {
            return $locations
                ->filter(fn (CafeteriaProvider $location) => $location->parent_cafeteria_id === $parentId
                    // Orphans (parent outside this network) are shown as roots.
                    || ($parentId === null && $location->parent_cafeteria_id !== null && ! $locations->contains('id', $location->parent_cafeteria_id)))
                ->sortBy(fn (CafeteriaProvider $location) => [$location->location_type === CafeteriaLocationType::Main ? 0 : 1, $location->name_en])
                ->map(fn (CafeteriaProvider $location): array => [
                    'id' => $location->id,
                    'code' => $location->code,
                    'name_en' => $location->name_en,
                    'name_am' => $location->name_am,
                    'location_type' => $location->location_type?->value,
                    'operational_status' => $location->operational_status?->value,
                    'is_active' => $location->is_active && ! $location->trashed(),
                    'opening_time' => $location->opening_time ? substr((string) $location->opening_time, 0, 5) : null,
                    'closing_time' => $location->closing_time ? substr((string) $location->closing_time, 0, 5) : null,
                    'children' => $build($location->id),
                ])
                ->values()
                ->all();
        };

        return $build(null);
    }

    /** @return Collection<int, CafeteriaProvider> active locations able to serve */
    public function servingLocations(CafeteriaServiceNetwork $network): Collection
    {
        return $network->cafeterias()->where('is_active', true)->get()->filter(fn (CafeteriaProvider $c) => $c->isServing())->values();
    }

    private function createsCycle(string $cafeteriaId, CafeteriaProvider $parent): bool
    {
        $seen = [];
        $node = $parent;

        while ($node !== null) {
            if ($node->id === $cafeteriaId || isset($seen[$node->id])) {
                return true;
            }
            $seen[$node->id] = true;
            $node = $node->parent_cafeteria_id !== null
                ? CafeteriaProvider::query()->withTrashed()->find($node->parent_cafeteria_id)
                : null;
        }

        return false;
    }
}
