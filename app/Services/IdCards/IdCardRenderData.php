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
         * Front-face rows as [amharic label, amharic value, english label,
         * english value, key]. The key identifies the row for layout decisions;
         * labels are translatable display text and must never be matched on.
         *
         * @var array<int, array{0: string, 1: ?string, 2: string, 3: ?string, 4: string}>
         */
        public array $bilingualFields = [],

        // Emergency contact, printed on the back face
        public ?string $emergencyContactName = null,
        public ?string $emergencyContactPhone = null,
        /**
         * Emergency contact rows as [amharic label, amharic value, english
         * label, english value], matching the front face's stacked shape.
         *
         * @var array<int, array{0: string, 1: ?string, 2: string, 3: ?string}>
         */
        public array $emergencyContactFields = [],

        /** Bilingual captions for the card number and signature sections. */
        public string $cardNumberLabel = '',
        public string $signatureLabel = '',

        // Issue / expiry shown in both calendars, like the front-face dates.
        public string $issueDateLabel = '',
        public string $expiryDateLabel = '',
        public ?string $issueDateFormattedAm = null,
        public ?string $expiryDateFormattedAm = null,

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

        /**
         * Front-header content for this template, already resolved against the
         * global settings and the organization logo.
         */
        public ?IdCardHeaderContent $header = null,

        /** How the back face draws the employee photo, if at all. */
        public ?IdCardBackPhoto $backPhoto = null,
    ) {}
}
