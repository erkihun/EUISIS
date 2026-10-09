<?php

declare(strict_types=1);

namespace App\Actions\Entitlements;

use App\Enums\EntitlementStatus;
use App\Models\Entitlement;

class RecalculateEntitlementsAction
{
    public function execute(Entitlement $entitlement): Entitlement
    {
        if ($entitlement->quota_limit !== null && $entitlement->quota_used >= $entitlement->quota_limit) {
            $entitlement->update(['status' => EntitlementStatus::Exhausted]);
        }

        return $entitlement->fresh();
    }
}
