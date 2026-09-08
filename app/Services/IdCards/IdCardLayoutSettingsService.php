<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Enums\IdCardTemplate;
use App\Services\SystemSettings\SystemSettingsService;

/**
 * Reads id_cards system settings, validates and clamps every value,
 * and returns a safe IdCardLayoutSettings instance.
 */
final readonly class IdCardLayoutSettingsService
{
    /** Neutral card surface used when a template supplies no artwork. */
    private const SURFACE_FROM = '#FFFFFF';

    private const SURFACE_TO = '#F1F5F9';

    /** Readable ink on that surface, for templates that set no colours. */
    private const INK = '#0F172A';

    private const INK_MUTED = '#475569';

    public function __construct(private SystemSettingsService $settings) {}

    public function get(bool $frontPng = false, bool $backPng = false): IdCardLayoutSettings
    {
        return new IdCardLayoutSettings(
            template: IdCardTemplate::tryFrom(
                (string) $this->settings->get('id_cards', 'template', IdCardTemplate::Classic->value),
            ) ?? IdCardTemplate::Classic,

            // Card colour belongs to the template, not to these legacy
            // per-install settings. A card without background artwork gets a
            // clean light surface so the template's own text colours read.
            frontBgFrom: self::SURFACE_FROM,
            frontBgTo: self::SURFACE_TO,
            frontTextPrimary: self::INK,
            frontTextSecondary: self::INK_MUTED,

            backBgFrom: self::SURFACE_FROM,
            backBgTo: self::SURFACE_TO,
            backTextColor: self::INK_MUTED,

            cityNameEn: $this->str('id_cards', 'city_name_en', 'Addis Ababa City Administration'),
            cityNameAm: $this->str('id_cards', 'city_name_am', 'አዲስ አበባ ከተማ አስተዳደር'),
            bureauNameEn: $this->str('id_cards', 'bureau_name_en', 'Public Service & HRD Bureau'),
            bureauNameAm: $this->str('id_cards', 'bureau_name_am', 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ'),
            returnAddressEn: $this->str('id_cards', 'return_address_en', 'Addis Ababa City Administration, Public Service & HRD Bureau'),
            returnAddressAm: $this->str('id_cards', 'return_address_am', 'አዲስ አበባ ከተማ አስተዳደር፣ የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ'),
            verificationUrl: $this->str('id_cards', 'verification_url', ''),
            supportContact: $this->str('id_cards', 'support_contact', ''),

            showOrganizationLogo: $this->bool('id_cards', 'show_organization_logo', true),
            showMagneticStripe: $this->bool('id_cards', 'show_magnetic_stripe', true),
            showPhoto: $this->bool('id_cards', 'show_photo', true),
            showFullNameEn: $this->bool('id_cards', 'show_full_name_en', true),
            showFullNameAm: $this->bool('id_cards', 'show_full_name_am', true),
            showEmployeeNumber: $this->bool('id_cards', 'show_employee_number', true),
            showCardNumber: $this->bool('id_cards', 'show_card_number', true),
            showOrganization: $this->bool('id_cards', 'show_organization', true),
            showOrganizationUnit: $this->bool('id_cards', 'show_organization_unit', true),
            showPosition: $this->bool('id_cards', 'show_position', true),
            showJobGrade: $this->bool('id_cards', 'show_job_grade', true),
            showEmploymentStatus: $this->bool('id_cards', 'show_employment_status', true),
            showIssueDate: $this->bool('id_cards', 'show_issue_date', true),
            showExpiryDate: $this->bool('id_cards', 'show_expiry_date', true),
            showSignature: $this->bool('id_cards', 'show_signature', false),
            showQr: $this->bool('id_cards', 'show_qr', true),
            showReturnNotice: $this->bool('id_cards', 'show_return_notice', true),
            showEmergencyContact: $this->bool('id_cards', 'show_emergency_contact', true),

            qrSize: $this->clampInt('id_cards', 'qr_size', 96, 64, 200),
            padding: $this->oneOf('id_cards', 'card_padding', 'normal', ['compact', 'normal', 'spacious']),
            nameFontSize: $this->oneOf('id_cards', 'front_name_font_size', 'sm', ['xs', 'sm', 'base', 'lg']),
            labelFontSize: $this->oneOf('id_cards', 'front_label_font_size', 'xs', ['xs', 'sm']),
        );
    }

    private function hex(string $group, string $key, string $default): string
    {
        $val = (string) $this->settings->get($group, $key, $default);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) ? $val : $default;
    }

    private function str(string $group, string $key, string $default): string
    {
        $val = $this->settings->get($group, $key, $default);

        return is_string($val) ? $val : $default;
    }

    private function bool(string $group, string $key, bool $default): bool
    {
        $val = $this->settings->get($group, $key, $default);

        return is_bool($val) ? $val : (bool) $val;
    }

    private function clampInt(string $group, string $key, int $default, int $min, int $max): int
    {
        $raw = $this->settings->get($group, $key, $default);
        $val = is_numeric($raw) ? (int) $raw : $default;

        return max($min, min($max, $val));
    }

    /** @param string[] $allowed */
    private function oneOf(string $group, string $key, string $default, array $allowed): string
    {
        $val = (string) $this->settings->get($group, $key, $default);

        return in_array($val, $allowed, true) ? $val : $default;
    }
}
