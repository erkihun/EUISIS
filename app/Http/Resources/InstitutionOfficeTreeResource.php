<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionOfficeTreeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_code' => $this->office_code,
            'name_en' => $this->name_en,
            'name_am' => $this->name_am,
            'office_level' => $this->office_level instanceof InstitutionOfficeLevel
                ? $this->office_level->value
                : $this->office_level,
            'status' => $this->status instanceof InstitutionOfficeStatus
                ? $this->status->value
                : $this->status,
            'is_head_office' => $this->is_head_office,
            'parent_office_id' => $this->parent_office_id,
            'children' => self::collection($this->whenLoaded('childOffices')),
        ];
    }
}
