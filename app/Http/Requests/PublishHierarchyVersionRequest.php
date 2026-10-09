<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use Illuminate\Foundation\Http\FormRequest;

class PublishHierarchyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hierarchyVersion = $this->route('hierarchyVersion');

        return $hierarchyVersion instanceof HierarchyVersion
            && ($this->user()?->can('hierarchy-versions.publish') ?? false)
            && $hierarchyVersion->status === HierarchyVersionStatus::Draft;
    }

    public function rules(): array
    {
        return [];
    }
}
