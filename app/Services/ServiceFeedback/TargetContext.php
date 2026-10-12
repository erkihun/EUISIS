<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use InvalidArgumentException;

/**
 * What a scanned QR resolved to, and everything a public page may know
 * about it.
 *
 * Built only by PublicFeedbackTargetResolver from a server-side token lookup.
 * The browser never constructs or amends one (brief §14, 17). `names` holds
 * bilingual pairs so the page can switch language without another lookup;
 * `publicDisplay()` is the only shape that leaves the server.
 */
final readonly class TargetContext
{
    /**
     * @param  array<string, array{en: string|null, am: string|null}>  $names
     * @param  array<string, string|null>  $extras  non-identifying public facts (logo URL, short name)
     */
    public function __construct(
        public PublicFeedbackTargetType $targetType,
        public PublicFeedbackSourceType $sourceType,
        public ?string $employeeId,
        public ?string $organizationTargetId,
        public ?string $employeeAssignmentId,
        public ?string $contextOrganizationId,
        public ?string $contextUnitId,
        public ?string $contextPositionId,
        public string $sourceId,
        public ?string $targetNameSnapshot,
        public ?string $targetCodeSnapshot,
        public array $names = [],
        public array $extras = [],
    ) {
        $this->assertConsistent();
    }

    /** Exactly one primary target, and a source that produces that target type. */
    public function assertConsistent(): void
    {
        $hasEmployee = $this->employeeId !== null;
        $hasOrganization = $this->organizationTargetId !== null;

        $valid = match ($this->targetType) {
            PublicFeedbackTargetType::Employee => $hasEmployee && ! $hasOrganization,
            PublicFeedbackTargetType::Organization => $hasOrganization && ! $hasEmployee,
        };

        if (! $valid) {
            throw new InvalidArgumentException('A public feedback target names exactly one employee or one organization.');
        }

        if ($this->sourceType->targetType() !== $this->targetType) {
            throw new InvalidArgumentException('The QR source does not produce this target type.');
        }
    }

    /**
     * The safe public confirmation of the target (brief §18, 20): office, unit
     * and masked role for an employee; name, short name and logo for an
     * organization. Never a person's name, number or contact.
     *
     * @return array<string, string|null>
     */
    public function publicDisplay(string $locale = 'en'): array
    {
        $organization = $this->localized('organization', $locale);
        $unit = $this->localized('organization_unit', $locale);
        $position = $this->localized('position', $locale);

        if ($this->targetType === PublicFeedbackTargetType::Organization) {
            return [
                'target_type' => $this->targetType->value,
                'display_name' => $organization ?? '',
                'organization' => $organization,
                'organization_unit' => null,
                'position' => null,
                'short_name' => $this->extras['short_name'] ?? null,
                'logo_url' => $this->extras['logo_url'] ?? null,
            ];
        }

        return [
            'target_type' => $this->targetType->value,
            'display_name' => $position ?? $unit ?? ($locale === 'am' ? 'የአገልግሎት ሰጪ' : 'Service Officer'),
            'organization' => $organization,
            'organization_unit' => $unit,
            'position' => $position,
        ];
    }

    private function localized(string $key, string $locale): ?string
    {
        $pair = $this->names[$key] ?? null;

        if ($pair === null) {
            return null;
        }

        foreach ($locale === 'am' ? ['am', 'en'] : ['en', 'am'] as $language) {
            $value = trim((string) ($pair[$language] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
