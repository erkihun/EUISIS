<?php

declare(strict_types=1);

namespace Modules\Entitlements\Contracts;

use Modules\Entitlements\Contracts\Data\NewServiceProvider;
use Modules\Entitlements\Contracts\Data\NewServiceTransaction;

interface ServiceDeliveryRegistry
{
    public function serviceTypeId(string $code): ?string;

    public function ensureServiceType(string $code, string $nameEn, bool $active = true): string;

    public function createProvider(NewServiceProvider $provider): string;

    public function recordTransaction(NewServiceTransaction $transaction): string;

    /** Grants an employee a service entitlement (at one service point, or any); returns its id. */
    public function grantEntitlement(string $employeeId, string $serviceTypeId, ?string $servicePointId, int $actorId, ?int $quotaLimit = null): string;

    /** Re-evaluates the employee's entitlements after a placement change (an exhausted quota is marked). */
    public function recalculateEntitlementsFor(string $employeeId): void;
}
