<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Services\Calendar\LocalizedDateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HierarchyVersion */
class HierarchyVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDraft = $this->status === HierarchyVersionStatus::Draft;
        $calendar = app(LocalizedDateService::class);

        return [
            'id' => $this->id,
            'version_name' => $this->version_name,
            'notes' => $this->notes,
            'source_document' => $this->source_document,
            'status' => $this->status?->value ?? (string) $this->status,
            // Raw Gregorian ISO values — used by forms and date pickers
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'approval_date' => $this->approval_date?->format('Y-m-d H:i'),
            'published_at' => $this->approval_date?->format('Y-m-d H:i'),
            // Locale-aware display values — used by read-only display elements
            'effective_from_display' => $calendar->displayDate($this->effective_from),
            'effective_to_display' => $calendar->displayDate($this->effective_to),
            'approval_date_display' => $calendar->displayDateTime($this->approval_date),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'edges_count' => $this->whenCounted('edges'),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'published_by' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'can' => [
                'view' => $request->user()?->can('hierarchy-versions.view') ?? false,
                'update' => ($request->user()?->can('hierarchy-versions.update') ?? false) && $isDraft,
                'archive' => ($request->user()?->can('hierarchy-versions.archive') ?? false) && $isDraft,
                'publish' => ($request->user()?->can('hierarchy-versions.publish') ?? false) && $isDraft,
                'manageTree' => ($request->user()?->can('hierarchy-versions.manageTree') ?? false) && $isDraft,
            ],
        ];
    }
}
