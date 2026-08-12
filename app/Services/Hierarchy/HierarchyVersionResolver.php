<?php

declare(strict_types=1);

namespace App\Services\Hierarchy;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use Illuminate\Http\Request;

class HierarchyVersionResolver
{
    /**
     * Resolve the hierarchy version to use for a given HTTP request.
     *
     * Priority:
     * 1. Explicitly requested hierarchy_version_id from request
     * 2. Currently published/active version (latest by effective_from)
     * 3. Latest draft version (most recently updated)
     * 4. null — caller should fall back to flat list
     */
    public function resolveForRequest(Request $request): ?HierarchyVersion
    {
        if ($request->filled('hierarchy_version_id')) {
            $version = HierarchyVersion::query()
                ->find($request->get('hierarchy_version_id'));

            if ($version !== null) {
                return $version;
            }
        }

        return $this->resolveDefault();
    }

    /**
     * Resolve the best available hierarchy version without request context.
     *
     * Priority:
     * 1. Published version (latest by effective_from, then created_at)
     * 2. Latest draft version (most recently updated)
     * 3. null — no version exists at all
     */
    public function resolveDefault(): ?HierarchyVersion
    {
        // 1. Try published
        $published = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published)
            ->latest('effective_from')
            ->latest('created_at')
            ->first();

        if ($published !== null) {
            return $published;
        }

        // 2. Fall back to latest draft
        return HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Draft)
            ->latest('updated_at')
            ->first();
    }
}
