<?php

declare(strict_types=1);

namespace App\Http\Resources\Transfers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name_en' => $this->organization->name_en,
                'name_am' => $this->organization->name_am,
            ]),
            'position' => $this->whenLoaded('position', fn () => [
                'id' => $this->position->id,
                'title_en' => $this->position->title_en,
                'title_am' => $this->position->title_am,
            ]),
            'grade_level' => $this->grade_level,
            'salary_min' => $this->salary_min,
            'salary_max' => $this->salary_max,
            'number_of_vacancies' => $this->number_of_vacancies,
            'eligibility_rules' => $this->eligibility_rules ?? [],
            'required_documents' => $this->required_documents ?? [],
            'opening_date' => $this->opening_date?->toDateString(),
            'closing_date' => $this->closing_date?->toDateString(),
            'status' => $this->status?->value,
            'published_at' => $this->published_at?->toISOString(),
            'published_by' => $this->whenLoaded('publishedBy', fn () => $this->publishedBy ? [
                'id' => $this->publishedBy->id,
                'name' => $this->publishedBy->name,
            ] : null),
            'applications_count' => $this->when(
                isset($this->applications_count),
                fn () => $this->applications_count,
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
