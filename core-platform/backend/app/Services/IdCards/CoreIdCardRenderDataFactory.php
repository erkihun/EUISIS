<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Actions\ManageDigitalCredentials;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\IdCard;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\SystemSettings\SystemSettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the copied EUISIS card renderer (IdCardSvgRenderer) draws, built from
 * the Core's records: the same fields, rows and labels as the EUISIS factory,
 * read from the Core employee, placement, verified photo document and the
 * card's credential (its QR address). The Core has no feedback links.
 */
final readonly class CoreIdCardRenderDataFactory
{
    public function __construct(
        private IdCardLayoutSettingsService $layoutService,
        private IdCardAssetResolver $assetResolver,
        private SystemSettingsService $systemSettings,
        private IdCardTemplateService $templates,
        private EthiopianCalendarService $ethiopianCalendar,
        private ManageDigitalCredentials $credentials,
    ) {}

    public function make(IdCard $card, ?string $orientation = null): IdCardRenderData
    {
        $card->loadMissing('employee.currentAssignment.organization', 'employee.currentAssignment.unit', 'employee.currentAssignment.position');
        $employee = $card->employee;
        $assignment = $employee?->currentAssignment;
        $org = $assignment?->organization;
        $unit = $assignment?->unit;
        $position = $assignment?->position;
        $token = $this->credentials->tokenFor($card);

        return $this->build(
            $orientation,
            cardId: (string) $card->id,
            cardNumber: (string) $card->card_number,
            status: (string) $card->status,
            employee: $employee,
            organizationNameEn: $org?->name_en, organizationNameAm: $org?->name_am, organizationLogoPath: $org?->logo_path,
            unitNameEn: $unit?->name_en, unitNameAm: $unit?->name_am,
            positionTitleEn: $position?->title_en, positionTitleAm: $position?->title_am,
            positionCode: $position?->job_position_code, jobGrade: $position?->grade_level,
            issuedAt: $card->issued_at, expiresAt: $card->expires_at,
            photoDataUri: $employee === null ? null : $this->document((string) $employee->id, EmployeeDocument::PHOTO),
            qrVerificationUrl: $token !== null ? route('id-checker.show', $token)
                : ($card->public_card_uuid ? route('id-checker.show', $card->public_card_uuid) : route('id-checker.index')),
        );
    }

    /** A sample card for a template preview; $headerOverrides are the print record's header texts. */
    public function sample(?string $orientation = null, array $headerOverrides = []): IdCardRenderData
    {
        $employee = new Employee([
            'employee_number' => 'EMP-0001', 'first_name' => 'Sample', 'last_name' => 'Employee', 'full_name' => 'ምሳሌ ሠራተኛ', 'name_en' => 'Sample Employee',
            'gender' => 'male', 'date_of_birth' => '1990-01-01', 'nationality' => 'Ethiopian', 'employment_type' => 'permanent', 'phone' => '+251 911 000 000',
            'emergency_contact_name' => 'Sample Contact', 'emergency_contact_phone' => '+251 911 000 000',
        ]);

        return $this->build(
            $orientation,
            cardId: 'sample', cardNumber: 'CARD-0001', status: 'active', employee: $employee,
            organizationNameEn: 'Sample Bureau', organizationNameAm: 'ምሳሌ ቢሮ', organizationLogoPath: null,
            unitNameEn: 'Sample Directorate', unitNameAm: 'ምሳሌ ዳይሬክቶሬት',
            positionTitleEn: 'Senior Officer', positionTitleAm: 'ከፍተኛ ባለሙያ', positionCode: 'POS-000001', jobGrade: 'X',
            issuedAt: now(), expiresAt: now()->addYears(2), photoDataUri: null,
            qrVerificationUrl: route('id-checker.index'),
            headerOverrides: $headerOverrides,
        );
    }

    /** @param  array<string, string>  $headerOverrides */
    private function build(
        ?string $orientation, string $cardId, string $cardNumber, string $status, ?Employee $employee,
        ?string $organizationNameEn, ?string $organizationNameAm, ?string $organizationLogoPath, ?string $unitNameEn, ?string $unitNameAm,
        ?string $positionTitleEn, ?string $positionTitleAm, ?string $positionCode, ?string $jobGrade,
        ?CarbonInterface $issuedAt, ?CarbonInterface $expiresAt, ?string $photoDataUri, string $qrVerificationUrl, array $headerOverrides = [],
    ): IdCardRenderData {
        $sealPath = $this->systemSettings->get('general', 'seal');
        $template = $this->templates->active($orientation);
        $frontBackground = $this->templates->dataUri($template?->front_background_path);
        $backBackground = $this->templates->dataUri($template?->back_background_path);
        $layout = $this->layoutService->get(frontPng: $frontBackground !== null, backPng: $backBackground !== null);
        $presentation = $template ? $this->templates->presentation($template) : null;
        $orientation ??= $template?->orientation ?? 'landscape';
        $long = max($presentation['width_mm'] ?? 85.6, $presentation['height_mm'] ?? 54);
        $short = min($presentation['width_mm'] ?? 85.6, $presentation['height_mm'] ?? 54);
        $organizationLogo = $this->assetResolver->resolveLogoPath($organizationLogoPath);
        $header = $headerOverrides === []
            ? $this->templates->header($template, $layout, $orientation === 'portrait' ? null : $organizationLogo)
            : IdCardHeaderContent::fromArray(
                array_filter($headerOverrides, fn ($v): bool => is_string($v) && trim($v) !== '') + ($template?->header_config ?? []),
                ['city_name_en' => $layout->cityNameEn, 'city_name_am' => $layout->cityNameAm, 'bureau_name_en' => $layout->bureauNameEn, 'bureau_name_am' => $layout->bureauNameAm],
                $layout->showOrganizationLogo,
                $this->templates->dataUri($template?->logo_primary_path),
                $this->templates->dataUri($template?->logo_secondary_path) ?? ($orientation === 'portrait' ? null : $organizationLogo),
            );
        $fields = $template?->employee_fields;
        $shows = fn (string $field): bool => $fields === null || in_array($field, $fields, true);

        return new IdCardRenderData(
            cardId: $cardId,
            cardNumber: $cardNumber,
            status: $status,
            employeeNumber: $employee?->employee_number,
            fullNameEn: $employee?->fullNameEn(),
            fullNameAm: $employee?->fullNameAm(),
            gender: $employee?->gender,
            employmentStatus: $this->employmentLabel($employee?->employment_type),
            dateOfBirthFormatted: $this->formatCardDate($employee?->date_of_birth),
            nationality: $employee?->nationality,
            phone: $employee?->phone,
            emergencyContactName: $shows('emergency_contact_name') ? $employee?->emergency_contact_name : null,
            emergencyContactPhone: $shows('emergency_contact_phone') ? $employee?->emergency_contact_phone : null,
            emergencyContactFields: array_values(array_filter([
                [(string) $this->line('am', 'emergency_contact_name', 'Emergency Contact Name'), $employee?->emergency_contact_name, (string) $this->line('en', 'emergency_contact_name', 'Emergency Contact Name'), $employee?->emergency_contact_name, 'emergency_contact_name'],
                [(string) $this->line('am', 'emergency_contact_phone', 'Emergency Contact Phone'), $employee?->emergency_contact_phone, (string) $this->line('en', 'emergency_contact_phone', 'Emergency Contact Phone'), $employee?->emergency_contact_phone, 'emergency_contact_phone'],
            ], fn (array $row): bool => filled($row[1]) && $shows($row[4]))),
            cardNumberLabel: $this->bilingualCaption('card_no'),
            signatureLabel: $this->bilingualCaption('signature'),
            signatureLabelAm: (string) $this->line('am', 'signature', ''),
            signatureLabelEn: (string) $this->line('en', 'signature', 'Authorized Signature'),
            issueDateLabel: $this->bilingualCaption('issue_date'),
            expiryDateLabel: $this->bilingualCaption('expiry_date'),
            issueDateFormattedAm: $this->formatCardDate($issuedAt, 'am'),
            expiryDateFormattedAm: $this->formatCardDate($expiresAt, 'am'),
            organizationNameEn: $organizationNameEn,
            organizationNameAm: $organizationNameAm,
            organizationUnitNameEn: $unitNameEn,
            organizationUnitNameAm: $unitNameAm,
            positionTitleEn: $positionTitleEn,
            positionTitleAm: $positionTitleAm,
            positionCode: $positionCode,
            jobGrade: $jobGrade,
            issueDateFormatted: $this->formatCardDate($issuedAt, 'en'),
            expiryDateFormatted: $this->formatCardDate($expiresAt, 'en'),
            photoDataUri: $photoDataUri,
            logoDataUri: $header->logoDataUri,
            sealDataUri: $this->templates->dataUri($template?->seal_path) ?? $this->assetResolver->resolveStoragePath(is_string($sealPath) ? $sealPath : null),
            signatureDataUri: $this->templates->dataUri($template?->signature_path),
            qrVerificationUrl: $qrVerificationUrl,
            layout: $layout,
            frontBackgroundDataUri: $frontBackground,
            backBackgroundDataUri: $backBackground,
            orientation: $orientation,
            widthMm: $orientation === 'portrait' ? $short : $long,
            heightMm: $orientation === 'portrait' ? $long : $short,
            textStyles: $this->templates->styleObjects($template, $layout),
            boxes: $this->templates->layoutBoxes($template),
            bilingualFields: $this->bilingualFields($employee, $fields),
            header: $header,
            backPhoto: $this->templates->backPhoto($template),
            organizationLogoDataUri: $organizationLogo,
            feedbackQrUrl: null,
        );
    }

    /** The current verified photo or signature document as a data URI (ADR 0009 evidence). */
    private function document(string $employeeId, string $kind): ?string
    {
        $document = EmployeeDocument::query()->where('employee_id', $employeeId)->where('kind', $kind)->where('is_current', true)
            ->where('status', EmployeeDocument::VERIFIED)->first(['storage_disk', 'storage_path', 'mime_type']);
        if ($document === null || ! in_array($document->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        try {
            $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
        } catch (\Throwable) {
            return null;
        }

        return $bytes === null ? null : 'data:'.$document->mime_type.';base64,'.base64_encode($bytes);
    }

    /** The rows of the landscape front, as EUISIS lays them out (label am, value am, label en, value en, key). */
    private function bilingualFields(?Employee $employee, ?array $configured = null): array
    {
        $am = fn (string $key, ?string $fallback = null): ?string => $this->line('am', $key, $fallback);
        $en = fn (string $key, ?string $fallback = null): ?string => $this->line('en', $key, $fallback);
        $nameEn = $employee?->fullNameEn();
        $nameAm = $employee?->fullNameAm();
        $gender = $employee?->gender;
        $nationality = $employee?->nationality;
        $nationalityKey = 'nationality_values.'.$this->nationalityKey($nationality);
        $phone = $employee?->phone;
        $rows = [
            [$am('name'), $nameAm, $en('name'), $nameEn, 'name'],
            [$am('sex'), $gender ? $am('gender.'.$gender, $gender) : null, $en('sex'), $gender ? $en('gender.'.$gender, $gender) : null, 'sex'],
            [$am('date_of_birth'), $this->formatCardDate($employee?->date_of_birth, 'am'), $en('date_of_birth'), $this->formatCardDate($employee?->date_of_birth, 'en'), 'dob'],
            [$am('nationality'), $nationality ? $am($nationalityKey, $nationality) : null, $en('nationality'), $nationality ? $en($nationalityKey, $nationality) : null, 'nationality'],
            [$am('employment_status'), $this->employmentLabel($employee?->employment_type, 'am'), $en('employment_status'), $this->employmentLabel($employee?->employment_type, 'en'), 'employment'],
            [$am('phone_number'), $phone, $en('phone_number'), $phone, 'phone'],
            [$am('id_number'), $employee?->employee_number, $en('id_number'), $employee?->employee_number, 'idNumber'],
        ];
        if ($configured !== null) {
            foreach (['email', 'address'] as $field) {
                if (in_array($field, $configured, true)) {
                    $rows[] = [__('employee-portal.fields.'.$field, [], 'am'), $employee?->{$field}, __('employee-portal.fields.'.$field, [], 'en'), $employee?->{$field}, $field];
                }
            }
            $rows = array_values(array_filter($rows, fn ($row) => in_array($row[4], $configured, true)));
        }

        return $rows;
    }

    private function employmentLabel(?string $type, ?string $locale = null): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $label = __('employment_types.'.$type, [], $locale);

        return is_string($label) && $label !== 'employment_types.'.$type ? $label : $type;
    }

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

    private function bilingualCaption(string $key): string
    {
        $am = (string) $this->line('am', $key, '');
        $en = (string) $this->line('en', $key, '');

        return trim($am !== '' && $en !== '' ? $am.'/'.$en : $am.$en);
    }

    private function line(string $locale, string $key, ?string $fallback = null): ?string
    {
        $translated = __('id-card-fields.'.$key, [], $locale);

        return is_string($translated) && $translated !== 'id-card-fields.'.$key ? $translated : $fallback;
    }

    private function formatCardDate(?CarbonInterface $date, ?string $locale = null): ?string
    {
        if ($date === null) {
            return null;
        }

        return ($locale ?? app()->getLocale()) === 'am'
            ? $this->ethiopianCalendar->formatGregorianDateAsEthiopian(Carbon::instance($date), 'am', true)
            : $date->format('d M Y');
    }
}
