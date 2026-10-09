<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderTransportDashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'today_scans' => $this['today_scans'] ?? 0,
            'accepted_scans' => $this['accepted_scans'] ?? 0,
            'rejected_scans' => $this['rejected_scans'] ?? 0,
            'active_routes' => $this['active_routes'] ?? 0,
            'active_vehicles' => $this['active_vehicles'] ?? 0,
            'active_drivers' => $this['active_drivers'] ?? 0,
            'scheduled_trips' => $this['scheduled_trips'] ?? 0,
            'completed_trips' => $this['completed_trips'] ?? 0,
            'active_passes' => $this['active_passes'] ?? 0,
        ];
    }
}
