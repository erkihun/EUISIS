<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Data;

/**
 * The explicit security context every protected operation receives: who acts,
 * which Reporting permissions they hold, and which organizations they reach.
 * It is resolved on the server (ReportingAccessResolver); an organization id
 * sent by a client is only ever checked against it, never trusted.
 */
final readonly class ActorContext
{
    /**
     * @param  list<string>  $organizationIds  reachable organizations; ignored when unrestricted
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $userId,
        public string $name,
        public bool $unrestricted,
        public array $organizationIds,
        public array $permissions,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function reaches(?string $organizationId): bool
    {
        return $organizationId !== null && ($this->unrestricted || in_array($organizationId, $this->organizationIds, true));
    }

    /** The list to filter queries by; null means no organization filter (city-wide account). */
    public function organizationFilter(): ?array
    {
        return $this->unrestricted ? null : $this->organizationIds;
    }
}
