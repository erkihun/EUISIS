<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\Employee;
use App\Models\IdCard;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\SystemSettings\SystemSettingsService;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
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
        private EmployeeFeedbackTokenService $feedbackTokens,
    ) {}

    public function make(IdCard $card, ?string $orientation = null): IdCardRenderData
    {
        $employee = $card->employee;
        $assignment = $employee?->currentAssignment;
        $org = $assignment?->organization;
        $unit = $assignment?->organizationUnit;
        $position = $assignment?->position;
        $sealPath = $this->systemSettings->get('general', 'seal');
        // A requested orientation picks the template built for it; without
        // one, the default template decides the orientation as before.
        $template = $this->templates->active($orientation);
        $frontBackground = $this->templates->dataUri($template?->front_background_path);
        $backBackground = $this->templates->dataUri($template?->back_background_path);
        $layout = $this->layoutService->get(frontPng: $frontBackground !== null, backPng: $backBackground !== null);
        $presentation = $template ? $this->templates->presentation($template) : null;
        $orientation ??= $template?->orientation ?? 'landscape';
        $long = max($presentation['width_mm'] ?? 85.6, $presentation['height_mm'] ?? 54);
        $short = min($presentation['width_mm'] ?? 85.6, $presentation['height_mm'] ?? 54);
        $organizationLogo = $this->assetResolver->resolveLogoPath($org?->logo_path);
        // Landscape retains its existing secondary organization-logo fallback.
        // Portrait uses the employer logo as its primary mark, so do not also
        // repeat it in the secondary slot.
        $header = $this->templates->header(
            $template, $layout, $orientation === 'portrait' ? null : $organizationLogo,
        );

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
            emergencyContactFields: array_values(array_filter([
                [
                    (string) $this->line('am', 'emergency_contact_name', 'Emergency Contact Name'),
                    $employee?->emergency_contact_name,
                    (string) $this->line('en', 'emergency_contact_name', 'Emergency Contact Name'),
                    $employee?->emergency_contact_name,
                ],
                [
                    (string) $this->line('am', 'emergency_contact_phone', 'Emergency Contact Phone'),
                    $employee?->emergency_contact_phone,
                    (string) $this->line('en', 'emergency_contact_phone', 'Emergency Contact Phone'),
                    $employee?->emergency_contact_phone,
                ],
            ], fn (array $row): bool => filled($row[1]))),
            cardNumberLabel: $this->bilingualCaption('card_no'),
            signatureLabel: $this->bilingualCaption('signature'),
            signatureLabelAm: (string) $this->line('am', 'signature', ''),
            signatureLabelEn: (string) $this->line('en', 'signature', 'Authorized Signature'),
            issueDateLabel: $this->bilingualCaption('issue_date'),
            expiryDateLabel: $this->bilingualCaption('expiry_date'),
            issueDateFormattedAm: $this->formatCardDate($card->issued_at, 'am'),
            expiryDateFormattedAm: $this->formatCardDate($card->expires_at, 'am'),

            organizationNameEn: $org?->name_en,
            organizationNameAm: $org?->name_am,
            organizationUnitNameEn: $unit?->name_en,
            organizationUnitNameAm: $unit?->name_am,
            positionTitleEn: $position?->title_en ?? null,
            positionTitleAm: $position?->title_am ?? null,
            positionCode: $position?->job_position_code ?? $position?->code,
            jobGrade: $position?->grade_level,

            issueDateFormatted: $this->formatCardDate($card->issued_at, 'en'),
            expiryDateFormatted: $this->formatCardDate($card->expires_at, 'en'),

            // Resolve files to base64 data URIs — never expose raw paths
            photoDataUri: $this->assetResolver->resolvePhotoPath($employee?->photo_path),
            logoDataUri: $header->logoDataUri,
            // The template's own seal takes precedence; the global
            // `general.seal` setting remains the fallback for templates that
            // have not uploaded one.
            sealDataUri: $this->templates->dataUri($template?->seal_path)
                ?? $this->assetResolver->resolveStoragePath(
                    is_string($sealPath) ? $sealPath : null,
                ),
            signatureDataUri: $this->templates->dataUri($template?->signature_path),

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
            header: $header,
            backPhoto: $this->templates->backPhoto($template),
            organizationLogoDataUri: $organizationLogo,
            feedbackQrUrl: $employee ? $this->feedbackTokens->activeToken($employee)?->publicUrl() : null,
        );
    }

    /**
     * Front-face rows, Amharic first then English. Each side falls back to the
     * other language when a translation is missing, so a row never renders blank.
     *
     * The trailing key lets the renderer pick a row out without matching on the
     * label, which is translatable display text and free to change.
     *
     * @return array<int, array{0: string, 1: ?string, 2: string, 3: ?string, 4: string}>
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
        $nationalityKey = 'nationality_values.'.$this->nationalityKey($nationality);
        $phone = $employee?->phone;

        return [
            [$am('name'), $nameAm, $en('name'), $nameEn, 'name'],
            [$am('sex'), $gender ? $am('gender.'.$gender, $gender) : null, $en('sex'), $gender ? $en('gender.'.$gender, $gender) : null, 'sex'],
            [$am('date_of_birth'), $this->formatCardDate($employee?->date_of_birth, 'am'), $en('date_of_birth'), $this->formatCardDate($employee?->date_of_birth, 'en'), 'dob'],
            [$am('nationality'), $nationality ? $am($nationalityKey, $nationality) : null, $en('nationality'), $nationality ? $en($nationalityKey, $nationality) : null, 'nationality'],
            [$am('employment_status'), $employmentType?->label('am'), $en('employment_status'), $employmentType?->label('en'), 'employment'],
            [$am('phone_number'), $phone, $en('phone_number'), $phone, 'phone'],
            // The card number identifies the plastic; the employee number
            // identifies the person, which is what the card face shows.
            [$am('id_number'), $employee?->employee_number, $en('id_number'), $employee?->employee_number, 'idNumber'],
        ];
    }

    /**
     * Canonical key for a nationality, whichever language it was typed in.
     *
     * An employee record may hold "Ethiopian" or "ኢትዮጵያዊ"; both must resolve to
     * the same key so each row of the card prints in its own language.
     */
    private function nationalityKey(?string $nationality): string
    {
        $value = trim((string) $nationality);
        if ($value === '') {
            return '';
        }

        foreach (['en', 'am'] as $locale) {
            $values = __('id-card-fields.nationality_values', [], $locale);
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $key => $translated) {
                if (mb_strtolower((string) $translated) === mb_strtolower($value)) {
                    return (string) $key;
                }
            }
        }

        return Str::snake($value);
    }

    /** A caption showing both languages at once, as the card face does. */
    private function bilingualCaption(string $key): string
    {
        $am = (string) $this->line('am', $key, '');
        $en = (string) $this->line('en', $key, '');

        return trim($am !== '' && $en !== '' ? $am.'/'.$en : $am.$en);
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
