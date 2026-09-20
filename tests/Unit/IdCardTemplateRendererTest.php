<?php

declare(strict_types=1);

use App\Enums\IdCardTemplate;
use App\Services\IdCards\IdCardLayoutSettings;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\IdCards\IdCardRenderData;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\QrCodeVersionResolver;
use App\Services\IdCards\QrPayloadSecurityValidator;

it('renders distinct SVG treatments for every ID card template', function (
    IdCardTemplate $template,
    string $frontMarker,
    string $backMarker,
): void {
    $renderer = new IdCardSvgRenderer(new IdCardQrCodeRenderer(new QrCodeVersionResolver(new QrPayloadSecurityValidator)));
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
        backNoticeAm: 'ካርዱን ካገኙ ወደ ሰጪው ቢሮ ይመልሱ።',
        backNoticeEn: 'If found, please return to the issuing bureau.',
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
        signatureDataUri: null,
        qrVerificationUrl: '',
        layout: $layout,
    );
}

it('maximizes the official seal on every ID card back render path', function (): void {
    $renderer = new IdCardSvgRenderer(new IdCardQrCodeRenderer(new QrCodeVersionResolver(new QrPayloadSecurityValidator)));
    $back = $renderer->renderBack(templateRenderData(templateLayout(IdCardTemplate::Classic)));

    expect($back)
        ->toContain('id="officialSeal"')
        ->toContain('width="192" height="192"')
        // Both faces fill the template box so seal size and position remain
        // customizable in either orientation.
        ->and(file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardBack.tsx'))
        ->toContain('h-full w-full object-contain')
        ->and(file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardPortraitBack.tsx'))
        ->toContain('h-full w-full object-contain');
});

it('wraps a long back notice across lines instead of cutting it off', function (): void {
    $renderer = new IdCardSvgRenderer(new IdCardQrCodeRenderer(new QrCodeVersionResolver(new QrPayloadSecurityValidator)));
    $long = 'If this identification card is found, please return it to the issuing bureau of the Addis Ababa City Administration without delay.';
    $layout = templateLayout(IdCardTemplate::Classic);
    $layout = new IdCardLayoutSettings(...[...(array) $layout, 'backNoticeEn' => $long]);

    $back = $renderer->renderBack(templateRenderData($layout));

    // Every word of the notice reaches the card, spread over several lines.
    $printed = implode(' ', array_map(
        fn (string $chunk): string => strip_tags($chunk),
        preg_split('/(?=<text)/', $back) ?: [],
    ));
    foreach (['identification', 'issuing', 'Administration', 'without', 'delay'] as $word) {
        expect($printed)->toContain($word);
    }

    // No ellipsis: truncation is what this replaced.
    expect($back)->not->toContain('…');

    $document = new DOMDocument;
    expect($document->loadXML($back))->toBeTrue();
});

it('keeps portrait sections independently manageable and omits removed back content', function (): void {
    $front = file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardPortraitFront.tsx');
    $back = file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardPortraitBack.tsx');
    $editor = file_get_contents(__DIR__.'/../../resources/js/Pages/SystemSettings/IdCardTemplates.tsx');
    $layoutContext = file_get_contents(__DIR__.'/../../resources/js/Components/IdCards/IdCardTemplateContext.tsx');
    $svgRenderer = file_get_contents(__DIR__.'/../../app/Services/IdCards/IdCardSvgRenderer.php');
    $dataFactory = file_get_contents(__DIR__.'/../../app/Services/IdCards/IdCardRenderDataFactory.php');

    expect($front)
        ->toContain("layoutStyle(cardTemplate, 'header')")
        ->toContain("layoutStyle(cardTemplate, 'employee_name')")
        ->toContain("layoutStyle(cardTemplate, 'employee_position')")
        ->toContain('whitespace-normal break-words')
        ->toContain('lang="am"')
        ->toContain('lang="en"')
        ->toContain('organizationLogoUrl ?? cardTemplate?.logo_primary_url')
        ->not->toContain('w-full truncate font-bold')
        ->not->toContain("layoutStyle(cardTemplate, 'fields')")
        ->not->toContain("layoutStyle(cardTemplate, 'dates')")
        ->not->toContain("layoutStyle(cardTemplate, 'portrait_fields')")
        ->and($back)
        ->not->toContain("layoutStyle(cardTemplate, 'emergency', 'back')")
        ->not->toContain("layoutStyle(cardTemplate, 'notes', 'back')")
        ->not->toContain("layoutStyle(cardTemplate, 'card_number', 'back')")
        ->not->toContain("layoutStyle(cardTemplate, 'signature', 'back')")
        ->not->toContain("layoutStyle(cardTemplate, 'signature_label', 'back')")
        ->and(strpos($front, 'lang="am"'))->toBeLessThan(strpos($front, 'lang="en"'))
        ->and($layoutContext)
        ->toContain("PORTRAIT_FRONT_LAYOUT_ELEMENTS = ['header', 'logo_primary', 'logo_secondary', 'photo', 'employee_name', 'employee_position', 'emphasis']")
        ->toContain("PORTRAIT_BACK_LAYOUT_ELEMENTS = ['qr', 'seal', 'photo']")
        ->and($svgRenderer)
        ->toContain('$data->organizationLogoDataUri ?? $data->logoDataUri')
        ->and(strpos($svgRenderer, '$lines = $this->wrapText((string) $data->organizationNameAm'))
        ->toBeLessThan(strpos($svgRenderer, 'if ($data->organizationNameEn)'))
        ->and($dataFactory)
        ->toContain('$orientation === \'portrait\' ? null : $organizationLogo')
        ->and($editor)
        ->toContain('elements={portrait ? PORTRAIT_FRONT_LAYOUT_ELEMENTS : LANDSCAPE_FRONT_LAYOUT_ELEMENTS}')
        ->toContain('elements={portrait ? PORTRAIT_BACK_LAYOUT_ELEMENTS : BACK_LAYOUT_ELEMENTS}');
});
