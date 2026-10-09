<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VacancyAnnouncement;
use Illuminate\Foundation\Http\FormRequest;

class StoreVacancyAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', VacancyAnnouncement::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title_en' => ['required', 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'application_opens_at' => ['nullable', 'date'],
            'application_closes_at' => ['nullable', 'date', 'after_or_equal:application_opens_at'],
            'eligibility_rules' => ['nullable', 'array'],
            'positions' => ['required', 'array', 'min:1'],
            'positions.*.position_establishment_id' => ['required', 'uuid', 'distinct', 'exists:position_establishments,id'],
            'positions.*.vacancy_slots' => ['required', 'integer', 'min:1'],
        ];
    }
}
