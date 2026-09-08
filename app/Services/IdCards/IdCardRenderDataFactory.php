<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\Employee;
use App\Models\IdCard;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\SystemSettings\SystemSettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Assembles an IdCardRenderData DTO from a fully-loaded IdCard model.
 *
 * Security contract:
 * - The QR payload is the stable public verification URL — no raw token/hash.
 * - Photo and logo paths are resolved to safe base64 data URIs.
 * - National ID and other PII fields are never included.
 * - QR URL never changes when services are added to the card.
 */
final readonly class IdCardRenderDataFactory
{
    public function __construct(
        private IdCardLayoutSettingsService $layoutService,
        private IdCardAssetResolver $assetResolver,
        private CardQrPayloadService $qrPayloadService,
        private SystemSettingsService $systemSettings,
        private IdCardTemplateService $templates,
        private EthiopianCalendarService $ethiopianCalendar,
    ) {}

    public function make(IdCard $card, ?string $orientation = null): IdCardRenderData
    {
        $employee = $card->employee;
        $assignment = $employee?->currentAssignment;
        $org = $assignment?->organization;
        $unit = $assignment?->organizationUnit;
        $position = $assignment?->position;
        $sealPath = $this->systemSettings->get('general', 'seal');
        $template = $this->templates->active();
        $frontBackground = $this->templates->dataUri($template?->front_background_path);
        $backBackground = $this->templates->dataUri($template?->back_background_path);
        $layout = $this->layoutService->get(frontPng: $frontBackground !== null, backPng: $backBackground !== null);
        $presentation = $template ? $this->templates->presentation($template) : null;
        $orientation ??= $template?->orientation ?? 'landscape';
        $long = max($presentation['width_mm'] ?? 85.6, $presentation['height_mm'] ?? 54);
        $short = min($presentation['width_mm'] ?? 85.6, $presentation['height_mm'] ?? 54);

        return new IdCardRenderData(
            cardId: $card->id,
            cardNumber: $card->card_number,
            status: $card->status->value,

            employeeNumber: $employee?->employee_number,
            fullNameEn: $employee?->name_en ?: ($employee?->metadata['name_en'] ?? $employee?->full_name),
            fullNameAm: $employee?->metadata['name_am'] ?? ($employee?->full_name ?: $employee?->name_en),
            gender: $employee?->gender,
            employmentStatus: $employee?->employment_type?->label(),
            dateOfBirthFormatted: $this->formatCardDate($employee?->date_of_birth),
            nationality: $employee?->nationality,
            phone: $employee?->phone,
            emergencyContactName: $employee?->emergency_contact_name,
            emergencyContactPhone: $employee?->emergency_contact_phone,
            emergencyContactLabel: (string) $this->line(app()->getLocale(), 'emergency_contact', 'Emergency Contact'),

            organizationNameEn: $org?->name_en,
            organizationNameAm: $org?->name_am,
            organizationUnitNameEn: $unit?->name_en,
            organizationUnitNameAm: $unit?->name_am,
            positionTitleEn: $position?->title_en ?? null,
            positionTitleAm: $position?->title_am ?? null,
            positionCode: $position?->job_position_code ?? $position?->code,
            jobGrade: $position?->grade_level,

            issueDateFormatted: $this->formatCardDate($card->issued_at),
            expiryDateFormatted: $this->formatCardDate($card->expires_at),

            // Resolve files to base64 data URIs — never expose raw paths
            photoDataUri: $this->assetResolver->resolvePhotoPath($employee?->photo_path),
            logoDataUri: $this->assetResolver->resolveLogoPath($org?->logo_path),
            sealDataUri: $this->assetResolver->resolveStoragePath(
                is_string($sealPath) ? $sealPath : null,
            ),

            // Stable service-gateway QR URL — printed once, never changes on service updates.
            qrVerificationUrl: $this->qrPayloadService->buildStableQrUrl($card),

            layout: $layout,
            frontBackgroundDataUri: $frontBackground,
            backBackgroundDataUri: $backBackground,
            orientation: $orientation,
            widthMm: $orientation === 'portrait' ? $short : $long,
            heightMm: $orientation === 'portrait' ? $long : $short,
            textStyles: $this->templates->styleObjects($template, $layout),
            boxes: $this->templates->layoutBoxes($template),
            bilingualFields: $this->bilingualFields($card, $employee),
            frontFooterText: trim($layout->cityNameEn.' '.(string) $this->line(app()->getLocale(), 'authorized_footer', '')),
        );
    }

    /**
     * Front-face rows, Amharic first then English. Each side falls back to the
     * other language when a translation is missing, so a row never renders blank.
     *
     * @return array<int, array{0: string, 1: ?string, 2: string, 3: ?string}>
     */
    private function bilingualFields(IdCard $card, ?Employee $employee): array
    {
        $am = fn (string $key, ?string $fallback = null): ?string => $this->line('am', $key, $fallback);
        $en = fn (string $key, ?string $fallback = null): ?string => $this->line('en', $key, $fallback);

        $nameEn = $employee?->name_en ?: ($employee?->metadata['name_en'] ?? $employee?->full_name);
        $nameAm = $employee?->metadata['name_am'] ?? ($employee?->full_name ?: $nameEn);
        $gender = $employee?->gender;
        $employmentType = $employee?->employment_type;
        $nationality = $employee?->nationality;
        $nationalityKey = 'nationality_values.'.Str::snake((string) $nationality);
        $phone = $employee?->phone;

        return [
            [$am('name'), $nameAm, $en('name'), $nameEn],
            [$am('sex'), $gender ? $am('gender.'.$gender, $gender) : null, $en('sex'), $gender ? $en('gender.'.$gender, $gender) : null],
            [$am('date_of_birth'), $this->formatCardDate($employee?->date_of_birth, 'am'), $en('date_of_birth'), $this->formatCardDate($employee?->date_of_birth, 'en')],
            [$am('nationality'), $nationality ? $am($nationalityKey, $nationality) : null, $en('nationality'), $nationality ? $en($nationalityKey, $nationality) : null],
            [$am('employment_status'), $employmentType?->label('am'), $en('employment_status'), $employmentType?->label('en')],
            [$am('phone_number'), $phone, $en('phone_number'), $phone],
            [$am('id_number'), $card->card_number, $en('id_number'), $card->card_number],
        ];
    }

    /** One translated card string, falling back when the key is untranslated. */
    private function line(string $locale, string $key, ?string $fallback = null): ?string
    {
        $translated = __('id-card-fields.'.$key, [], $locale);

        return is_string($translated) && $translated !== 'id-card-fields.'.$key ? $translated : $fallback;
    }

    /**
     * Cards are printed for a single audience, so the date follows the active
     * locale: Ethiopian for Amharic, Gregorian otherwise. Never a raw ISO date.
     */
    private function formatCardDate(?CarbonInterface $date, ?string $locale = null): ?string
    {
        if ($date === null) {
            return null;
        }

        return ($locale ?? app()->getLocale()) === 'am'
            ? $this->ethiopianCalendar->formatGregorianDateAsEthiopian(Carbon::instance($date), 'am')
            : $date->format('d M Y');
    }
}
