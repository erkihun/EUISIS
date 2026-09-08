<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Immutable DTO that holds every piece of data the SVG renderer needs.
 * Assembled by IdCardRenderDataFactory; consumed by IdCardSvgRenderer.
 */
final readonly class IdCardRenderData
{
    /** Typography for one text role, or the built-in default when unset. */
    public function textStyle(string $side, string $role): ?IdCardTextStyle
    {
        return $this->textStyles[$side][$role] ?? null;
    }

    /** Where one element sits, or null when the side is not positionable. */
    public function box(string $side, string $element): ?IdCardLayoutElement
    {
        return $this->boxes[$side][$element] ?? null;
    }

    public function __construct(
        // Card identity
        public string $cardId,
        public string $cardNumber,
        public string $status,

        // Employee
        public ?string $employeeNumber,
        public ?string $fullNameEn,
        public ?string $fullNameAm,
        public ?string $gender,
        public ?string $employmentStatus,

        // Assignment
        public ?string $organizationNameEn,
        public ?string $organizationNameAm,
        public ?string $organizationUnitNameEn,
        public ?string $organizationUnitNameAm,
        public ?string $positionTitleEn,
        public ?string $positionTitleAm,
        public ?string $positionCode,
        public ?string $jobGrade,

        // Dates (pre-formatted strings, e.g. "16 May 2026")
        public ?string $issueDateFormatted,
        public ?string $expiryDateFormatted,

        // Assets – base64 data URIs or null
        public ?string $photoDataUri,
        public ?string $logoDataUri,
        public ?string $sealDataUri,

        // QR payload – the signed verification URL (no raw token/hash)
        public string $qrVerificationUrl,

        // Layout settings (merged system settings + defaults)
        public IdCardLayoutSettings $layout,
        public ?string $frontBackgroundDataUri = null,
        public ?string $backBackgroundDataUri = null,
        public string $orientation = 'landscape',
        public float $widthMm = 85.6,
        public float $heightMm = 54,

        // Front-face identity fields
        public ?string $dateOfBirthFormatted = null,
        public ?string $nationality = null,
        public ?string $phone = null,

        /**
         * Front-face rows as [amharic label, amharic value, english label, english value].
         *
         * @var array<int, array{0: string, 1: ?string, 2: string, 3: ?string}>
         */
        public array $bilingualFields = [],

        /** Authorisation line printed at the foot of the front face. */
        public string $frontFooterText = '',

        // Emergency contact, printed on the back face
        public ?string $emergencyContactName = null,
        public ?string $emergencyContactPhone = null,
        /** Translated caption for the emergency contact block. */
        public string $emergencyContactLabel = '',

        /**
         * Element positions, keyed by side then element.
         *
         * @var array<string, array<string, IdCardLayoutElement>>
         */
        public array $boxes = [],

        /**
         * Per-template typography, keyed by side then role.
         *
         * @var array<string, array<string, IdCardTextStyle>>
         */
        public array $textStyles = [],
    ) {}
}
