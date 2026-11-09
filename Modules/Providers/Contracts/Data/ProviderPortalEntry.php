<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts\Data;

/**
 * The provider portal's two front doors. Each service signs in on its own
 * page (and its own look) but into the same `provider` guard, so a provider
 * that runs both services keeps one account and one session. The sign-in
 * page is a convenience, not the boundary: every portal route still checks
 * the service and the permission itself.
 */
enum ProviderPortalEntry: string
{
    case Cafeteria = 'cafeteria';
    case Transport = 'transport';

    /** Remembers the portal a browser last signed in to, so sign-out and expiry return there. */
    public const COOKIE = 'provider_portal';

    /** The service (provider_services → service_types.code) an account needs to use this door. */
    public function serviceCode(): string
    {
        return $this->value;
    }

    public function loginRouteName(): string
    {
        return 'provider.portal.'.$this->value.'.login';
    }

    public function loginStoreRouteName(): string
    {
        return 'provider.portal.'.$this->value.'.login.store';
    }

    public function other(): self
    {
        return $this === self::Cafeteria ? self::Transport : self::Cafeteria;
    }
}
