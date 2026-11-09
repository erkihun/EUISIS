<?php

declare(strict_types=1);

namespace App\Http\Requests\SituationRoom;

use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationExportService;
use App\Services\SituationRoom\SituationPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An export request. The category must be one the user may open, and the
 * scope comes from the same resolver as the screen — the request can narrow
 * an export, never widen it.
 */
final class ExportSituationRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $category = SituationCategory::tryFrom((string) $this->query('category'));

        return $user !== null && $user->can(SituationPermissions::REPORTS) && ($category === null || $category->visibleTo($user));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_column(SituationCategory::cases(), 'value'))],
            'format' => ['required', Rule::in(SituationExportService::FORMATS)],
            'locale' => ['nullable', Rule::in(['en', 'am'])],
        ];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function category(): SituationCategory
    {
        return SituationCategory::from((string) $this->validated('category'));
    }
}
