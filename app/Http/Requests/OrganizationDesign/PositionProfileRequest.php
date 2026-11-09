<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Position;
use App\Models\PositionProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A profile draft for a position, a draft edit, or a new version of a published profile. */
class PositionProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('profile');
        $user = $this->user();
        if ($profile instanceof PositionProfile) {
            return $user?->can('update', $profile) ?? false;
        }
        $position = $this->route('position');

        return $position instanceof Position && ($user?->can('designPosition', [PositionProfile::class, $position]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profile = $this->route('profile');
        $position = $profile instanceof PositionProfile ? $profile->position : $this->route('position');
        $isVersion = str_ends_with((string) $this->route()?->getName(), 'versions.store');
        $partial = $profile instanceof PositionProfile && ! $isVersion;

        return [
            'purpose_en' => ['nullable', 'string', 'max:5000'],
            'purpose_am' => ['nullable', 'string', 'max:5000'],
            'reports_to_position_id' => ['nullable', 'uuid', Rule::exists('positions', 'id')->where('organization_id', $position->organization_id)],
            'working_conditions_en' => ['nullable', 'string', 'max:5000'],
            'working_conditions_am' => ['nullable', 'string', 'max:5000'],
            'authorities_en' => ['nullable', 'string', 'max:5000'],
            'authorities_am' => ['nullable', 'string', 'max:5000'],
            'relationships_en' => ['nullable', 'string', 'max:5000'],
            'relationships_am' => ['nullable', 'string', 'max:5000'],
            'effective_from' => [$partial ? 'sometimes' : 'required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reports_to_position_id.exists' => __('organization-design.profile_reports_to_invalid')];
    }
}
