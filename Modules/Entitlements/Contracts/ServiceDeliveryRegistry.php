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
}
