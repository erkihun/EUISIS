<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use InvalidArgumentException;

/**
 * One institution, one registration: a live organization (draft, active or
 * inactive) keeps its English and Amharic name to itself. Archived, merged
 * and dissolved organizations release the name, so a re-established body can
 * be registered again. Names are compared without case and with runs of
 * spaces collapsed, which is also how the forms store them.
 */
final class OrganizationDuplicateNameFinder
{
    private const COLUMNS = ['name_en', 'name_am'];

    public static function normalize(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $collapsed === '' ? null : $collapsed;
    }

    public function find(string $column, mixed $name, ?string $ignoreOrganizationId = null): ?Organization
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Unsupported name column [{$column}].");
        }

        $normalized = self::normalize($name);

        if ($normalized === null) {
            return null;
        }

        return Organization::query()
            ->whereIn('status', [
                OrganizationStatus::Draft->value,
                OrganizationStatus::Active->value,
                OrganizationStatus::Inactive->value,
            ])
            ->when($ignoreOrganizationId !== null, fn ($query) => $query->whereKeyNot($ignoreOrganizationId))
            ->whereRaw("LOWER(TRIM({$column})) = ?", [mb_strtolower($normalized, 'UTF-8')])
            ->first(['id', 'code', 'name_en', 'name_am']);
    }
}
