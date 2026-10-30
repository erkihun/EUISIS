<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\User;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;

/**
 * The user's own recent exports for an export panel. Exports are personal
 * (MonitoringExportPolicy), so only the requester's are ever listed; a page
 * about one record passes its scope to list that record's exports only.
 */
final class ExportList
{
    /** @return list<array<string, mixed>> */
    public function recentFor(User $user, ?string $scopeType = null, ?string $scopeId = null): array
    {
        return MonitoringExport::query()->where('requested_by', $user->id)
            ->when($scopeType !== null, fn ($q) => $q->where('scope_type', $scopeType))
            ->when($scopeId !== null, fn ($q) => $q->where('scope_id', $scopeId))
            ->latest()->limit(10)->get()
            ->map(fn (MonitoringExport $e): array => [
                'id' => $e->id, 'scope_type' => $e->scope_type, 'range' => $e->range->value, 'quarter' => $e->quarter, 'format' => $e->format,
                'status' => $e->status->value, 'filename' => $e->filename, 'created_at' => $e->created_at?->toIso8601String(),
                'download_url' => $e->isReady() ? route('service-monitoring.exports.download', $e) : null,
            ])->all();
    }
}
