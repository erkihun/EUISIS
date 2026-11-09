<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecycleBinRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->resource['record'];
        $definition = $this->resource['definition'];
        $deletedBy = $record->deleted_by ? User::query()->find($record->deleted_by) : null;

        return [
            'type' => $this->resource['type'],
            'type_label_key' => $definition['label_key'],
            'id' => $record->getKey(),
            'display_name' => $this->displayName($record),
            'code' => $this->code($record),
            'deleted_at' => $record->deleted_at?->toIso8601String(),
            'deleted_by' => $deletedBy?->only(['id', 'name']),
            'deletion_reason' => $record->deletion_reason,
            'organization_id' => $record->organization_id ?? null,
            'can' => [
                'restore' => ($request->user()?->can($definition['restore_permission']) ?? false)
                    && ($request->user()?->can('restore', $record) ?? false),
                'forceDelete' => $request->user()?->can($definition['restore_permission']) ?? false,
                'view_details' => $request->user()?->can('recycle-bin.viewDetails') ?? false,
            ],
        ];
    }

    private function displayName(object $record): string
    {
        foreach (['name_en', 'title_en', 'name', 'code', 'job_position_code'] as $field) {
            $value = $record->{$field} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) $record->getKey();
    }

    private function code(object $record): ?string
    {
        foreach (['code', 'job_position_code'] as $field) {
            $value = $record->{$field} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
