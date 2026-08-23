<?php

declare(strict_types=1);

use App\Enums\IdCardTemplate;
use App\Services\IdCards\IdCardLayoutSettings;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\IdCards\IdCardRenderData;
use App\Services\IdCards\IdCardSvgRenderer;

it('renders distinct SVG treatments for every ID card template', function (
    IdCardTemplate $template,
    string $frontMarker,
    string $backMarker,
): void {
    $renderer = new IdCardSvgRenderer(new IdCardQrCodeRenderer);
    $data = templateRenderData(templateLayout($template));

    $front = $renderer->renderFront($data);
    $back = $renderer->renderBack($data);
    $frontDocument = new DOMDocument;
    $backDocument = new DOMDocument;

    expect($front)
        ->toContain('data-card-template="'.$template->value.'"')
        ->toContain($frontMarker)
        ->and($back)
        ->toContain('data-card-template="'.$template->value.'"')
        ->toContain($backMarker)
        ->and($frontDocument->loadXML($front))->toBeTrue()
        ->and($backDocument->loadXML($back))->toBeTrue();
})->with([
    'classic' => [IdCardTemplate::Classic, 'frontDots', 'backDots'],
    'modern' => [IdCardTemplate::Modern, 'CITY ID', 'backLines'],
    'minimal' => [IdCardTemplate::Minimal, 'offset="88%"', 'height="8"'],
]);

/**
 * @return IdCardLayoutSettings
 */
function templateLayout(IdCardTemplate $template): IdCardLayoutSettings
{
    return new IdCardLayoutSettings(
        template: $template,
        frontBgFrom: '#1D4ED8',
        frontBgTo: '#1E3A8A',
        frontTextPrimary: '#FFFFFF',
        frontTextSecondary: '#BFDBFE',
        backBgFrom: '#1E293B',
        backBgTo: '#0F172A',
        backTextColor: '#94A3B8',
        cityNameEn: 'Addis Ababa City Administration',
        cityNameAm: 'አዲስ አበባ ከተማ አስተዳደር',
        bureauNameEn: 'Public Service & HRD Bureau',
        bureauNameAm: 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ',
        returnAddressEn: 'Addis Ababa City Administration',
        returnAddressAm: 'አዲስ አበባ ከተማ አስተዳደር',
        verificationUrl: '',
        supportContact: '',
        showOrganizationLogo: true,
        showMagneticStripe: true,
        showPhoto: true,
        showFullNameEn: true,
        showFullNameAm: true,
        showEmployeeNumber: true,
        showCardNumber: true,
        showOrganization: true,
        showOrganizationUnit: true,
        showPosition: true,
        showJobGrade: true,
        showEmploymentStatus: true,
        showIssueDate: true,
        showExpiryDate: true,
        showSignature: false,
        showQr: false,
        showReturnNotice: true,
        showEmergencyContact: true,
        qrSize: 96,
        padding: 'normal',
        nameFontSize: 'sm',
        labelFontSize: 'xs',
    );
}

function templateRenderData(IdCardLayoutSettings $layout): IdCardRenderData
{
    return new IdCardRenderData(
        cardId: 'card-id',
        cardNumber: 'CARD-0001',
        status: 'active',
        employeeNumber: 'EMP-0001',
        fullNameEn: 'Sample Employee',
        fullNameAm: 'ምሳሌ ሰራተኛ',
        gender: 'male',
        employmentStatus: 'active',
        organizationNameEn: 'Sample Organization',
        organizationNameAm: 'የምሳሌ ተቋም',
        organizationUnitNameEn: 'Human Resources',
        organizationUnitNameAm: 'የሰው ኃይል',
        positionTitleEn: 'Officer',
        positionTitleAm: 'ባለሙያ',
        positionCode: 'POS-01',
        jobGrade: '10',
        issueDateFormatted: '22 Aug 2026',
        expiryDateFormatted: '22 Aug 2027',
        photoDataUri: null,
        logoDataUri: null,
        sealDataUri: 'data:image/png;base64,c2VhbA==',
        qrVerificationUrl: '',
        layout: $layout,
    );
}

it('maximizes the official seal on every ID card back render path', function (): void {
    $renderer = new IdCardSvgRenderer(new IdCardQrCodeRenderer);
    $back = $renderer->renderBack(templateRenderData(templateLayout(IdCardTemplate::Classic)));

    expect($back)
        ->toContain('id="officialSeal"')
        ->toContain('width="192" height="192"')
        ->and(file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardBack.tsx'))
        ->toContain('h-24 w-24')
        ->and(file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardPortraitBack.tsx'))
        ->toContain('h-24 w-24');
});
