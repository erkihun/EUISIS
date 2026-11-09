<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts;

use Modules\Providers\Contracts\Data\NewProvider;
use Modules\Providers\Contracts\Data\ProviderDetails;
use Modules\Providers\Contracts\Data\ProviderOwnerAccount;
use Modules\Providers\Contracts\Data\ProviderRevision;

/**
 * The only way other modules create or change provider records (ADR 0017):
 * register a provider of a type, enable a service for it, change its details,
 * and archive it. Runs inside the caller's transaction when there is one.
 */
interface ProviderRegistry
{
    /**
     * Creates the provider, enables its service, and, when an owner account is
     * given, its first portal user (who must change the password on first
     * sign-in). Returns the new provider's id.
     */
    public function register(NewProvider $provider, ?ProviderOwnerAccount $owner = null): string;

    /**
     * Enables a service for the provider; nothing changes when it is already
     * enabled. With $serviceTypeNameIfMissing the service type is created when
     * it does not exist yet; without it a missing type fails.
     */
    public function enableService(string $providerId, string $serviceCode, ?int $actorId, ?string $serviceTypeNameIfMissing = null): void;

    public function updateDetails(string $providerId, ProviderDetails $details): void;

    /** Changes only the fields the revision names; the others keep their stored values. */
    public function revise(string $providerId, ProviderRevision $revision): void;

    /** Archives the provider (soft delete): it is no longer found, its history stays. */
    public function archive(string $providerId): void;
}
