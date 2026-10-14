<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Provider;
use App\Models\ProviderUser;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared by the transport Form Requests, which serve two audiences:
 *
 * - the admin pages (`transport.*`, staff guard, `transport-*` permissions),
 *   where the provider comes from the request (`provider_id`), and
 * - the provider portal (`provider.portal.transport.*`, provider guard,
 *   provider-user permission keys), where it is always the signed-in
 *   provider user's own and the request may not name one.
 *
 * The route decides the audience, never "whichever guard is signed in": both
 * guards share one session, so a staff member who is also signed in to the
 * portal must not reach admin writes through a portal permission (or the
 * reverse). Every record a request references is checked to belong to the
 * same provider.
 */
trait TransportProviderRequest
{
    protected function onPortal(): bool
    {
        return $this->routeIs('provider.portal.*');
    }

    protected function authorizeTransport(string $adminPermission, string $portalPermission): bool
    {
        if ($this->onPortal()) {
            /** @var ProviderUser|null $providerUser */
            $providerUser = auth('provider')->user();

            return $providerUser !== null
                && $providerUser->hasService('transport')
                && $providerUser->canUseServicePermission($portalPermission);
        }

        return auth('web')->user()?->can($adminPermission) ?? false;
    }

    /** The record being updated (route-model bound), when there is one. */
    abstract protected function boundTransportRecord(): ?Model;

    /** The provider whose records this request writes and may reference. */
    protected function transportProviderId(): ?string
    {
        if ($this->onPortal()) {
            return auth('provider')->user()?->provider_id;
        }

        $requested = $this->input('provider_id');

        return is_string($requested) && $requested !== ''
            ? $requested
            : $this->boundTransportRecord()?->getAttribute('provider_id');
    }

    /** @return list<mixed> */
    protected function providerIdRules(): array
    {
        if ($this->onPortal()) {
            return ['prohibited'];
        }

        // A record stays with its provider: passes and trips point at it.
        $record = $this->boundTransportRecord();
        if ($record !== null) {
            return ['required', 'uuid', 'in:'.$record->getAttribute('provider_id')];
        }

        return [
            'required',
            'uuid',
            function (string $attribute, mixed $value, Closure $fail): void {
                $provider = Provider::query()->find($value);
                if ($provider === null || ! $provider->hasService('transport')) {
                    $fail(__('transport.provider_required'));
                }
            },
        ];
    }

    /** An id that must name a live row of $table owned by the same provider. */
    protected function ownedByProvider(string $table, bool $softDeletes = true): Exists
    {
        $rule = new Exists($table, 'id');
        $rule->where('provider_id', $this->transportProviderId() ?? '');

        return $softDeletes ? $rule->whereNull('deleted_at') : $rule;
    }

    /** A code unique within the provider, ignoring the record being updated. */
    protected function uniqueForProvider(string $table, string $column): Unique
    {
        $rule = (new Unique($table, $column))->where('provider_id', $this->transportProviderId() ?? '');

        $record = $this->boundTransportRecord();

        return $record === null ? $rule : $rule->ignore($record->getKey());
    }
}
