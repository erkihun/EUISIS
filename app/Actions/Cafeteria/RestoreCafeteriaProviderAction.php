<?php

declare(strict_types=1);

namespace App\Actions\Cafeteria;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\CafeteriaProvider;
use App\Models\User;
use Illuminate\Http\Request;

readonly class RestoreCafeteriaProviderAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaProvider $provider, User $actor, ?Request $request = null): CafeteriaProvider
    {
        $provider->restore();

        $provider->forceFill([
            'is_active'       => true,
            'deleted_by'      => null,
            'deletion_reason' => null,
        ])->save();

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaProviderRestored,
            $actor,
            $provider,
            $provider->organization_id,
            newValues: ['restored_at' => now()->toISOString()],
            request: $request,
        );

        return $provider;
    }
}
