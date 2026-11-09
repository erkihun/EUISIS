<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes the preview/confirm payload for the import wizard.
 *
 * The service already returns a plain array in exactly this shape; the resource
 * exists to pin that contract down in one place so the React page and the API
 * cannot drift apart.
 *
 * @property array<string, mixed> $resource
 */
class OrganizationStructureImportPreviewResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preview = (array) $this->resource;

        return [
            'file_name' => $preview['file_name'] ?? null,
            'can_import' => (bool) ($preview['can_import'] ?? false),
            'mode' => $preview['mode'] ?? 'create',
            'auto_generate_codes' => (bool) ($preview['auto_generate_codes'] ?? true),
            'organization' => $preview['organization'] ?? null,
            'unit_tree' => $preview['unit_tree'] ?? [],
            // Per-row provided vs generated code, with the rule that produced it.
            'codes' => $preview['codes'] ?? [],
            'counts' => $preview['counts'] ?? ['units' => 0, 'positions' => 0, 'employees' => 0],
            'errors' => $preview['errors'] ?? [],
            'warnings' => $preview['warnings'] ?? [],
            'error_count' => (int) ($preview['error_count'] ?? 0),
            'warning_count' => (int) ($preview['warning_count'] ?? 0),
            // Present only on a successful confirm.
            'result' => $preview['result'] ?? null,
        ];
    }
}
