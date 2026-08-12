<?php

declare(strict_types=1);

namespace App\Services\IdCards;

/**
 * Validated, clamped layout settings for card rendering.
 * All color fields are safe hex strings; numeric fields are within safe ranges.
 */
final readonly class IdCardLayoutSettings
{
    public function __construct(
        // Front colors
        public string $frontBgFrom,
        public string $frontBgTo,
        public string $frontTextPrimary,
        public string $frontTextSecondary,

        // Back colors
        public string $backBgFrom,
        public string $backBgTo,
        public string $backTextColor,

        // Text labels (localized from system settings)
        public string $cityNameEn,
        public string $cityNameAm,
        public string $bureauNameEn,
        public string $bureauNameAm,
        public string $returnAddressEn,
        public string $returnAddressAm,
        public string $verificationUrl,
        public string $supportContact,

        // Feature flags
        public bool $showOrganizationLogo,
        public bool $showMagneticStripe,
        public bool $showPhoto,
        public bool $showFullNameEn,
        public bool $showFullNameAm,
        public bool $showEmployeeNumber,
        public bool $showCardNumber,
        public bool $showOrganization,
        public bool $showOrganizationUnit,
        public bool $showPosition,
        public bool $showJobGrade,
        public bool $showEmploymentStatus,
        public bool $showIssueDate,
        public bool $showExpiryDate,
        public bool $showSignature,
        public bool $showQr,
        public bool $showReturnNotice,
        public bool $showEmergencyContact,

        // Sizing
        public int $qrSize,        // pixels, 64–200
        public string $padding,    // 'compact' | 'normal' | 'spacious'

        // Font size labels
        public string $nameFontSize,   // 'xs' | 'sm' | 'base' | 'lg'
        public string $labelFontSize,  // 'xs' | 'sm'
    ) {}
}
