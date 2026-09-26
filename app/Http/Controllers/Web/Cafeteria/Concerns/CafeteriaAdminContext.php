<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Cafeteria\Concerns;

use App\Enums\OrganizationStatus;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaServiceNetwork;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Option lists and organization scope for the cafeteria administration pages.
 * A scoped administrator only ever sees or changes rows of organizations in
 * their scope; the organization id in a request is never trusted on its own.
 */
trait CafeteriaAdminContext
{
    /** @return list<string>|null null = unrestricted */
    protected function scopedOrganizationIds(User $user): ?array
    {
        $scope = app(OrganizationScopeService::class);

        return $scope->isUnrestricted($user) ? null : array_values(array_map('strval', $scope->allowedOrganizationIds($user)));
    }

    protected function assertOrganizationInScope(User $user, ?string $organizationId): void
    {
        $allowed = $this->scopedOrganizationIds($user);

        abort_if($organizationId === null || ($allowed !== null && ! in_array($organizationId, $allowed, true)), 403);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function withinOrganizationScope(Builder $query, User $user, string $column = 'organization_id'): Builder
    {
        $allowed = $this->scopedOrganizationIds($user);

        return $allowed === null ? $query : $query->whereIn($column, $allowed);
    }

    /** @return list<array<string, mixed>> */
    protected function organizationOptions(User $user): array
    {
        return $this->withinOrganizationScope(
            Organization::query()->where('status', OrganizationStatus::Active)->orderBy('name_en'),
            $user,
            'id',
        )->get(['id', 'code', 'name_en', 'name_am'])->toArray();
    }

    /** @return list<array<string, mixed>> */
    protected function providerOptions(): array
    {
        return Provider::query()
            ->whereHas('providerType', fn (Builder $q) => $q->where('code', 'CAFETERIA'))
            ->orderBy('name_en')
            ->get(['id', 'provider_code', 'name_en', 'name_am', 'status'])
            ->toArray();
    }

    /** @return list<array<string, mixed>> */
    protected function networkOptions(): array
    {
        return CafeteriaServiceNetwork::query()
            ->orderBy('name_en')
            ->get(['id', 'provider_id', 'code', 'name_en', 'name_am', 'status'])
            ->toArray();
    }

    /** @return list<array<string, mixed>> */
    protected function cafeteriaOptions(): array
    {
        return CafeteriaProvider::query()
            ->orderBy('name_en')
            ->get(['id', 'provider_id', 'cafeteria_service_network_id', 'code', 'name_en', 'name_am', 'location_type', 'is_active'])
            ->map(fn (CafeteriaProvider $c): array => [
                'id' => $c->id,
                'provider_id' => $c->provider_id,
                'cafeteria_service_network_id' => $c->cafeteria_service_network_id,
                'code' => $c->code,
                'name_en' => $c->name_en,
                'name_am' => $c->name_am,
                'location_type' => $c->location_type?->value,
                'is_active' => $c->is_active,
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    protected function namePair(?object $model, string $codeColumn = 'code'): ?array
    {
        return $model === null ? null : [
            'id' => $model->id,
            'code' => $model->{$codeColumn} ?? null,
            'name_en' => $model->name_en ?? null,
            'name_am' => $model->name_am ?? null,
        ];
    }
}
