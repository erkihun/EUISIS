<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;

readonly class ArchiveCafeteriaProviderAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(CafeteriaProvider $provider, User $actor, ?string $reason = null, ?Request $request = null): CafeteriaProvider
    {
        $provider->forceFill([
            'is_active' => false,
            'deleted_by' => $actor->id,
            'deletion_reason' => $reason,
        ])->save();

        $provider->delete();

        // Mark the linked ServiceProvider registry entry as inactive
        $provider->serviceProvider?->update(['status' => 'inactive']);

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaProviderArchived,
            $actor,
            $provider,
            $provider->organization_id,
            newValues: ['deleted_at' => now()->toISOString()],
            reason: $reason,
            request: $request,
        );

        return $provider;
    }
}
