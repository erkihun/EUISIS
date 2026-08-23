<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Immutable DTO that holds every piece of data the SVG renderer needs.
 * Assembled by IdCardRenderDataFactory; consumed by IdCardSvgRenderer.
 */
final readonly class IdCardRenderData
{
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
    ) {}
}
