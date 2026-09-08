<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Enums\IdCardTemplate;

/**
 * Produces SVG strings for the front and back of an ID card.
 *
 * Card dimensions:
 *   Physical: 85.6 × 54 mm (ISO/IEC 7810 ID-1)
 *   SVG viewBox / pixel canvas: 856 × 540 (10 px/mm)
 *
 * All text is XML-escaped. No raw HTML is injected.
 * No remote CSS or font links are referenced.
 * All images are embedded as base64 data URIs.
 */
final class IdCardSvgRenderer
{
    // Card canvas size
    private const W = 856;

    private const H = 540;

    // CSS font-family value — single-quoted names (spaces) inside a double-quoted XML attribute.
    // Used as: font-family="%s" in sprintf so no outer quotes needed here.
    private const FONT = "'Abyssinica SIL','Noto Sans Ethiopic','Noto Serif Ethiopic','DejaVu Sans',Arial,sans-serif";

    /** Shown when a card field has no value. */
    private const DASH = '-';

    /** The SVG canvas is twice the card's millimetre size. */
    private const SCALE = 2;

    // Status watermark map (matches React WATERMARK_STATUSES)
    private const WATERMARKS = [
        'expired' => 'EXPIRED',
        'revoked' => 'REVOKED',
        'lost' => 'LOST',
        'suspended' => 'SUSPENDED',
        'replaced' => 'REPLACED',
        'damaged' => 'DAMAGED',
    ];

    public function __construct(private readonly IdCardQrCodeRenderer $qrRenderer) {}

    // ── Public entry points ────────────────────────────────────────────

    public function renderFront(IdCardRenderData $data): string
    {
        if ($data->orientation === 'portrait') {
            return $this->renderPortrait($data, 'front');
        }
        $l = $data->layout;

        [$padH] = $this->padValues($l->padding);

        // Element positions come from the template, falling back to the
        // built-in arrangement when it stores none.
        $photoBox = $data->box('front', 'photo');
        $fieldsBox = $data->box('front', 'fields');
        $photoX = $photoBox?->xIn(self::W) ?? $padH;
        $photoY = $photoBox?->yIn(self::H) ?? 52;
        $photoW = $photoBox?->wIn(self::W) ?? 72;
        $photoH = $photoBox?->hIn(self::H) ?? ($l->template === IdCardTemplate::Modern ? 72 : 96);
        $textX = $fieldsBox?->xIn(self::W) ?? ($photoX + $photoW + 12);
        $textW = $fieldsBox?->wIn(self::W) ?? (self::W - $padH - $textX);

        $nameFontSize = $this->nameFontPx($l->nameFontSize);
        $labelFontSize = $l->labelFontSize === 'sm' ? 12 : 9;

        $watermark = self::WATERMARKS[strtolower($data->status)] ?? null;

        $svg = $this->openSvg($l->template);
        $svg .= $this->defs($l->frontBgFrom, $l->frontBgTo, 'frontBg', $l->template);
        $svg .= $this->cardClip();

        $svg .= '<g clip-path="url(#cardClip)">';

        // Background
        $svg .= sprintf(
            '<rect width="%d" height="%d" fill="url(#frontBg)"/>',
            self::W,
            self::H,
        );

        // Security dot pattern
        $svg .= $this->backgroundImage($data->frontBackgroundDataUri, 'front');
        if ($data->frontBackgroundDataUri === null && $l->template !== IdCardTemplate::Minimal) {
            $svg .= $l->template === IdCardTemplate::Modern
                ? $this->linePattern('frontLines', 'rgba(15,23,42,0.05)')
                : $this->dotPattern('frontDots', 'rgba(15,23,42,0.06)');
            $svg .= sprintf(
                '<rect width="%d" height="%d" fill="url(#%s)"/>',
                self::W,
                self::H,
                $l->template === IdCardTemplate::Modern ? 'frontLines' : 'frontDots',
            );
        }

        // "EMPLOYEE ID" ghost watermark
        if ($data->frontBackgroundDataUri === null && $l->template !== IdCardTemplate::Minimal) {
            $svg .= sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="80"'
                .' font-weight="900" fill="rgba(15,23,42,0.04)"'
                .' transform="rotate(-20,%d,%d)" letter-spacing="12">%s</text>',
                self::W / 2, self::H / 2,
                self::FONT,
                self::W / 2, self::H / 2,
                $l->template === IdCardTemplate::Modern ? 'CITY ID' : 'EMPLOYEE ID',
            );
        }

        // Status watermark
        if ($watermark !== null) {
            $svg .= sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="72"'
                .' font-weight="900" fill="rgba(255,0,0,0.18)"'
                .' transform="rotate(-30,%d,%d)" letter-spacing="10">%s</text>',
                self::W / 2, self::H / 2,
                self::FONT,
                self::W / 2, self::H / 2,
                $this->e($watermark),
            );
        }

        // ── Header band ─────────────────────────────────────────────────
        $headerFill = $l->template === IdCardTemplate::Modern
            ? 'rgba(0,0,0,0.10)'
            : 'rgba(15,23,42,0.15)';
        $headerBox = $data->box('front', 'header');
        $headerX = $headerBox?->xIn(self::W) ?? 0;
        $headerY = $headerBox?->yIn(self::H) ?? 0;
        $headerW = $headerBox?->wIn(self::W) ?? self::W;
        $headerH = $headerBox?->hIn(self::H) ?? 40;
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
            $headerX, $headerY, $headerW, $headerH, $headerFill,
        );
        if ($l->template === IdCardTemplate::Modern) {
            $svg .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="rgba(15,23,42,0.20)" stroke-width="1"/>',
                $headerX, $headerY + $headerH, $headerX + $headerW, $headerY + $headerH,
            );
        }

        // Logo / placeholder
        if ($l->showOrganizationLogo && $data->logoDataUri !== null) {
            $svg .= sprintf(
                '<clipPath id="logoClip"><circle cx="26" cy="20" r="14"/></clipPath>'
                .'<image x="12" y="6" width="28" height="28" href="%s"'
                .' clip-path="url(#logoClip)" preserveAspectRatio="xMidYMid slice"/>',
                $data->logoDataUri,
            );
        } else {
            $svg .= sprintf(
                '<circle cx="26" cy="20" r="14" fill="rgba(15,23,42,0.2)"/>'
                .'<text x="26" y="25" text-anchor="middle" font-family="%s" font-size="9"'
                .' font-weight="700" fill="%s">AA</text>',
                self::FONT,
                $this->e($l->frontTextPrimary),
            );
        }

        // City name & bureau name
        $headerStyle = $data->textStyle('front', 'header');
        // Text sits inside the header band, so it travels with it.
        $headerTextX = $headerX + 48;
        $svg .= sprintf(
            '<text x="%d" y="%d" font-family="%s" %s>%s</text>',
            $headerTextX, $headerY + 21,
            self::FONT,
            $this->styleAttrs($headerStyle, 10, '700', $l->frontTextPrimary),
            $this->e($this->trunc($l->cityNameEn, 52)),
        );
        $svg .= sprintf(
            '<text x="%d" y="%d" font-family="%s" %s>%s</text>',
            $headerTextX, $headerY + 33,
            self::FONT,
            $this->styleAttrs($headerStyle, 8, '400', $l->frontTextSecondary),
            $this->e($this->trunc($l->bureauNameEn, 60)),
        );

        // ── Photo ────────────────────────────────────────────────────────
        if ($l->showPhoto && $data->photoDataUri !== null) {
            $svg .= sprintf(
                '<clipPath id="photoClip">'
                .'<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>'
                .'</clipPath>'
                .'<image x="%d" y="%d" width="%d" height="%d" href="%s"'
                .' clip-path="url(#photoClip)" preserveAspectRatio="xMidYMid slice"'
                .' style="outline:1px solid rgba(15,23,42,0.2)"/>',
                $photoX, $photoY, $photoW, $photoH,
                $l->template === IdCardTemplate::Modern ? 36 : 6,
                $l->template === IdCardTemplate::Modern ? 36 : 6,
                $photoX, $photoY, $photoW, $photoH,
                $data->photoDataUri,
            );
        } elseif ($l->showPhoto) {
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"'
                .' fill="rgba(15,23,42,0.15)" stroke="rgba(15,23,42,0.15)" stroke-width="1"/>'
                .'<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="7"'
                .' fill="%s">Photo</text>',
                $photoX, $photoY, $photoW, $photoH,
                $l->template === IdCardTemplate::Modern ? 36 : 6,
                $l->template === IdCardTemplate::Modern ? 36 : 6,
                $photoX + $photoW / 2, $photoY + $photoH / 2,
                self::FONT,
                $this->e($l->frontTextSecondary),
            );
        }

        // ── Text column ───────────────────────────────────────────────────
        // Identity fields only — organization and position live on the back.
        // Each field stacks an Amharic row above an English row; the name spans
        // the full width and the remaining six fill two columns.
        $labelStyle = $data->textStyle('front', 'label');
        $valueStyle = $data->textStyle('front', 'value');
        $colW = (int) (($textW - 12) / 2);

        // Name leads the body; ID number and phone number are emphasised on
        // their own row near the footer, matching the card artwork.
        $emphasised = ['ID Number', 'Phone Number'];
        $identity = [];
        $bottom = [];
        foreach ($data->bilingualFields as $field) {
            if (in_array($field[2], $emphasised, true)) {
                $bottom[$field[2]] = $field;
            } else {
                $identity[] = $field;
            }
        }

        $rowY = ($fieldsBox?->yIn(self::H) ?? $photoY) + 16;
        foreach (array_values($identity) as $index => $field) {
            $x = $textX + ($index % 2) * ($colW + 12);
            $y = $rowY + intdiv($index, 2) * 46;
            $svg .= $this->bilingualField($x, $y, $field, $colW, $l, $labelStyle, $valueStyle);
        }

        // Lower band: ID number on the left, phone number to its right.
        $emphasisBox = $data->box('front', 'emphasis');
        $bottomX = $emphasisBox?->xIn(self::W) ?? $padH;
        $bottomW = $emphasisBox?->wIn(self::W) ?? (self::W - 2 * $padH);
        $bottomY = $emphasisBox?->yIn(self::H) ?? 430;
        foreach (array_values(array_filter([$bottom['ID Number'] ?? null, $bottom['Phone Number'] ?? null])) as $index => $field) {
            $svg .= $this->bilingualField(
                (int) ($bottomX + $index * ($bottomW / 2 + 6)), $bottomY, $field,
                (int) ($bottomW / 2 - 6), $l, $labelStyle, $valueStyle,
            );
        }

        // ── Bottom accent bar ─────────────────────────────────────────────
        $svg .= '<defs><linearGradient id="footerGrad" x1="0%" y1="0%" x2="100%" y2="0%">'
            .'<stop offset="0%" stop-color="rgba(15,23,42,0.12)"/>'
            .'<stop offset="100%" stop-color="rgba(15,23,42,0.06)"/>'
            .'</linearGradient></defs>';
        $footerBox = $data->box('front', 'footer');
        $footerX = $footerBox?->xIn(self::W) ?? 0;
        $footerY = $footerBox?->yIn(self::H) ?? 520;
        $footerW = $footerBox?->wIn(self::W) ?? self::W;
        $footerH = $footerBox?->hIn(self::H) ?? 20;
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="url(#footerGrad)"/>'
            .'<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="rgba(15,23,42,0.1)" stroke-width="1"/>',
            $footerX, $footerY, $footerW, $footerH,
            $footerX, $footerY, $footerX + $footerW, $footerY,
        );
        $svg .= sprintf(
            '<text x="%d" y="%d" text-anchor="middle" font-family="%s" %s letter-spacing="1">%s</text>',
            (int) ($footerX + $footerW / 2), $footerY + 14,
            self::FONT,
            $this->styleAttrs($data->textStyle('front', 'footer'), 7, '400', $l->frontTextSecondary),
            $this->e($this->trunc($data->frontFooterText !== '' ? $data->frontFooterText : $l->cityNameEn, 90)),
        );

        // Right-edge decorative accent
        if ($data->frontBackgroundDataUri === null && $l->template === IdCardTemplate::Classic) {
            $svg .= '<polygon points="812,0 856,0 856,540 824,540" fill="rgba(15,23,42,0.05)"/>';
        } elseif ($data->frontBackgroundDataUri === null && $l->template === IdCardTemplate::Modern) {
            $svg .= '<circle cx="842" cy="14" r="78" fill="none" stroke="rgba(15,23,42,0.10)" stroke-width="14"/>';
        }

        $svg .= '</g>';
        $svg .= '</svg>';

        return $this->sizeSvg($svg, $data);
    }

    public function renderBack(IdCardRenderData $data): string
    {
        if ($data->orientation === 'portrait') {
            return $this->renderPortrait($data, 'back');
        }
        $l = $data->layout;
        [$padH] = $this->padValues($l->padding);

        $watermark = self::WATERMARKS[strtolower($data->status)] ?? null;

        $qrSize = $l->qrSize;
        $qrBoxW = $qrSize + 8; // 4px padding each side
        $qrBoxH = $qrBoxW;

        // Content top (below mag stripe or from top)
        $contentTop = $l->showMagneticStripe ? 44 : 14;
        $contentH = self::H - $contentTop - 8; // pb-2

        // Vertically center the QR block within content
        $labelH = 10;
        $noteH = 9;
        $gap = 4;
        $qrBlockH = $labelH + $gap + $qrBoxH + $gap + $noteH;
        $qrBlockTop = $contentTop + (int) (($contentH - $qrBlockH) / 2);

        // The QR block and the text column follow the template's layout.
        $qrBox = $data->box('back', 'qr');
        $notesBox = $data->box('back', 'notes');
        if ($qrBox !== null) {
            $qrBlockTop = $qrBox->yIn(self::H);
        }
        $qrBoxY = $qrBlockTop + $labelH + $gap;
        $qrBoxX = $qrBox?->xIn(self::W) ?? $padH;
        $qrCenterX = $qrBoxX + (int) ($qrBoxW / 2);

        $textX = $notesBox?->xIn(self::W) ?? ($qrBoxX + $qrBoxW + 12);
        $textW = $notesBox?->wIn(self::W) ?? (self::W - $padH - $textX);

        $svg = $this->openSvg($l->template);
        $svg .= $this->defs($l->backBgFrom, $l->backBgTo, 'backBg', $l->template);
        $svg .= $this->cardClip();

        $svg .= '<g clip-path="url(#cardClip)">';

        // Background
        $svg .= sprintf(
            '<rect width="%d" height="%d" fill="url(#backBg)"/>',
            self::W,
            self::H,
        );

        // Dot security pattern
        $svg .= $this->backgroundImage($data->backBackgroundDataUri, 'back');
        if ($data->backBackgroundDataUri === null && $l->template !== IdCardTemplate::Minimal) {
            $svg .= $l->template === IdCardTemplate::Modern
                ? $this->linePattern('backLines', 'rgba(15,23,42,0.04)')
                : $this->dotPattern('backDots', 'rgba(15,23,42,0.04)');
            $svg .= sprintf(
                '<rect width="%d" height="%d" fill="url(#%s)"/>',
                self::W,
                self::H,
                $l->template === IdCardTemplate::Modern ? 'backLines' : 'backDots',
            );
        }

        // Status watermark
        if ($watermark !== null) {
            $svg .= sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="72"'
                .' font-weight="900" fill="rgba(255,0,0,0.18)"'
                .' transform="rotate(-30,%d,%d)" letter-spacing="10">%s</text>',
                self::W / 2, self::H / 2,
                self::FONT,
                self::W / 2, self::H / 2,
                $this->e($watermark),
            );
        }

        // Magnetic stripe
        if ($l->showMagneticStripe) {
            $svg .= match ($l->template) {
                IdCardTemplate::Modern => '<rect x="290" y="16" width="566" height="16" rx="8" fill="rgba(0,0,0,0.5)"/>',
                IdCardTemplate::Minimal => '<rect x="0" y="12" width="856" height="8" fill="rgba(0,0,0,0.5)"/>',
                default => '<rect x="0" y="12" width="856" height="24" fill="rgba(0,0,0,0.5)"/>',
            };
        }

        // ── QR block ─────────────────────────────────────────────────────
        // White QR background box
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" rx="4" ry="4" fill="#FFFFFF"/>',
            $qrBoxX, $qrBoxY, $qrBoxW, $qrBoxH,
        );

        // QR code image
        if ($l->showQr && $data->qrVerificationUrl !== '') {
            $qrDataUri = $this->qrRenderer->asSvgDataUri($data->qrVerificationUrl, $qrSize);
            if ($qrDataUri !== '') {
                $svg .= sprintf(
                    '<image x="%d" y="%d" width="%d" height="%d" href="%s"/>',
                    $qrBoxX + 4, $qrBoxY + 4, $qrSize, $qrSize,
                    $qrDataUri,
                );
            }
        }

        // ── Text column ───────────────────────────────────────────────────
        $topTextY = $contentTop + 18;

        // "OFFICIAL IDENTIFICATION CARD"
        $svg .= sprintf(
            '<text x="%d" y="%d" font-family="%s" %s'
            .' letter-spacing="1">OFFICIAL IDENTIFICATION CARD</text>',
            $textX, $topTextY,
            self::FONT,
            $this->styleAttrs($data->textStyle('back', 'header'), 9, '700', $l->backTextColor),
        );

        // Property notice
        if ($l->showReturnNotice) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.65">%s</text>',
                $textX, $topTextY + 14,
                self::FONT,
                $this->styleAttrs($data->textStyle('back', 'footer'), 7, '400', $l->backTextColor),
                $this->e($this->trunc('If found, please return to the issuing bureau.', 55)),
            );
        }

        // Verification URL
        if ($l->verificationUrl !== '') {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.5">%s</text>',
                $textX, $topTextY + 28,
                self::FONT,
                $this->styleAttrs($data->textStyle('back', 'label'), 7, '400', $l->backTextColor),
                $this->e($this->trunc($l->verificationUrl, 50)),
            );
        }

        // Official seal — placed by the template, or centred in the text column.
        if ($data->sealDataUri !== null) {
            $sealBox = $data->box('back', 'seal');
            $sealSize = $sealBox !== null
                ? min($sealBox->wIn(self::W), $sealBox->hIn(self::H))
                : 192;
            $sealX = $sealBox?->xIn(self::W) ?? ($textX + (int) (($textW - $sealSize) / 2));
            $sealY = $sealBox?->yIn(self::H) ?? ($contentTop + (int) (($contentH - $sealSize) / 2));
            $svg .= sprintf(
                '<image id="officialSeal" x="%d" y="%d" width="%d" height="%d" href="%s" preserveAspectRatio="xMidYMid meet"/>',
                $sealX,
                $sealY,
                $sealSize,
                $sealSize,
                $data->sealDataUri,
            );
        }

        // Bottom text block: card number, support contact, return address
        $detailsBox = $data->box('back', 'details');
        $detailsX = $detailsBox?->xIn(self::W) ?? $textX;
        $bottomY = $detailsBox?->yIn(self::H) ?? (self::H - 8 - 38); // room for 3 lines

        if ($l->showCardNumber) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s letter-spacing="1">%s</text>',
                $detailsX, $bottomY,
                self::FONT,
                $this->styleAttrs($data->textStyle('back', 'value'), 8, '600', $l->backTextColor),
                $this->e($data->cardNumber),
            );
        }

        if ($l->showEmergencyContact && $l->supportContact !== '') {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.55">%s</text>',
                $detailsX, $bottomY + 13,
                self::FONT,
                $this->styleAttrs($data->textStyle('back', 'footer'), 7, '400', $l->backTextColor),
                $this->e($this->trunc($l->supportContact, 50)),
            );
        }

        $returnAddr = $l->returnAddressEn;
        if ($l->showReturnNotice) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.45">%s</text>',
                $detailsX, $bottomY + ($l->supportContact !== '' ? 26 : 13),
                self::FONT,
                $this->styleAttrs($data->textStyle('back', 'footer'), 7, '400', $l->backTextColor),
                $this->e($this->trunc($returnAddr, 58)),
            );
        }

        // Emergency contact — who to call if the holder needs help.
        $emergencyBox = $data->box('back', 'emergency');
        if (filled($data->emergencyContactName) || filled($data->emergencyContactPhone)) {
            $emergencyX = $emergencyBox?->xIn(self::W) ?? $padH;
            $emergencyY = $emergencyBox?->yIn(self::H) ?? (self::H - 60);
            $emergencyW = $emergencyBox?->wIn(self::W) ?? 200;
            $line = 0;
            foreach (array_filter([
                $data->emergencyContactLabel,
                $data->emergencyContactName,
                $data->emergencyContactPhone,
            ], 'filled') as $text) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-family="%s" %s>%s</text>',
                    $emergencyX, $emergencyY + $line * 13, self::FONT,
                    $line === 0
                        ? $this->styleAttrs($data->textStyle('back', 'label'), 7, '600', $l->backTextColor)
                        : $this->styleAttrs($data->textStyle('back', 'value'), 8, '400', $l->backTextColor),
                    $this->e($this->trunc((string) $text, $this->charBudget($emergencyW, 16))),
                );
                $line++;
            }
        }

        // Issue / expiry dates — their own movable block, matching the preview.
        $datesBox = $data->box('back', 'dates');
        $datesX = $datesBox?->xIn(self::W) ?? $detailsX;
        $datesY = $datesBox?->yIn(self::H) ?? (self::H - 24);
        $datesW = $datesBox?->wIn(self::W) ?? ($textW ?? 300);
        $dateStyle = $this->styleAttrs($data->textStyle('back', 'value'), 8, '600', $l->backTextColor);
        $dateLabelStyle = $this->styleAttrs($data->textStyle('back', 'label'), 7, '400', $l->backTextColor);
        foreach (array_values(array_filter([
            $l->showIssueDate ? ['ISSUE DATE', $data->issueDateFormatted] : null,
            $l->showExpiryDate ? ['EXP', $data->expiryDateFormatted] : null,
        ], fn (?array $pair): bool => $pair !== null && filled($pair[1]))) as $index => [$caption, $value]) {
            $x = (int) ($datesX + $index * ($datesW / 2));
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.7">%s</text>'
                .'<text x="%d" y="%d" font-family="%s" %s>%s</text>',
                $x, $datesY, self::FONT, $dateLabelStyle, $this->e($caption),
                $x, $datesY + 13, self::FONT, $dateStyle, $this->e($this->trunc((string) $value, 24)),
            );
        }

        // Bottom thin accent
        $svg .= '<defs><linearGradient id="backFooter" x1="0%" y1="0%" x2="100%" y2="0%">'
            .'<stop offset="0%" stop-color="rgba(15,23,42,0.08)"/>'
            .'<stop offset="100%" stop-color="rgba(15,23,42,0.03)"/>'
            .'</linearGradient></defs>';
        $svg .= '<rect x="0" y="536" width="856" height="4" fill="url(#backFooter)"/>';

        $svg .= '</g>';
        $svg .= '</svg>';

        return $this->sizeSvg($svg, $data);
    }

    // ── Private helpers ────────────────────────────────────────────────

    private function sizeSvg(string $svg, IdCardRenderData $data): string
    {
        // Preserve the viewBox and square QR modules while setting the physical output size.
        return preg_replace(
            '/(<svg\b[^>]*\b)width="\d+" height="\d+"/',
            '${1}width="'.round($data->widthMm * 10).'" height="'.round($data->heightMm * 10).'"',
            $svg, 1,
        ) ?? $svg;
    }

    private function renderPortrait(IdCardRenderData $data, string $side): string
    {
        $l = $data->layout;
        $front = $side === 'front';
        $color = $front ? $l->frontTextPrimary : $l->backTextColor;
        $from = $front ? $l->frontBgFrom : $l->backBgFrom;
        $to = $front ? $l->frontBgTo : $l->backBgTo;
        $background = $front ? $data->frontBackgroundDataUri : $data->backBackgroundDataUri;
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 856" width="540" height="856" data-card-template="'.$l->template->value.'">';
        $svg .= $this->defs($from, $to, 'portraitBg', $l->template);
        $svg .= '<rect width="540" height="856" rx="24" fill="url(#portraitBg)"/>';
        if ($background !== null) {
            $svg .= '<image id="'.$side.'Background" width="540" height="856" href="'.$this->e($background).'" preserveAspectRatio="xMidYMid slice"/>';
        }
        $text = fn (string $value, int $y, int $size = 18): string => sprintf(
            '<text x="270" y="%d" text-anchor="middle" font-family="%s" font-size="%d" fill="%s">%s</text>',
            $y, self::FONT, $size, $this->e($color), $this->e($this->trunc($value, 55)),
        );
        if ($front) {
            $svg .= $text($l->cityNameAm, 32).$text($l->cityNameEn, 58);
            if ($l->showOrganizationLogo && $data->logoDataUri) {
                $svg .= '<image x="24" y="20" width="44" height="44" href="'.$this->e($data->logoDataUri).'"/>';
            }
            if ($l->showPhoto) {
                $svg .= '<rect x="190" y="128" width="160" height="200" rx="12" fill="rgba(15,23,42,0.15)"/>';
                if ($data->photoDataUri) {
                    $svg .= '<image x="190" y="128" width="160" height="200" href="'.$this->e($data->photoDataUri).'" preserveAspectRatio="xMidYMid slice"/>';
                }
            }
            $y = $l->showPhoto ? 365 : 160;
            // Identity fields only — organization and position belong to the back face.
            // Each field stacks an Amharic row above an English row.
            $labelStyle = $data->textStyle('front', 'label');
            $valueStyle = $data->textStyle('front', 'value');
            $colX = [24, 278];
            $colW = 250;

            [$nameField, $gridFields] = [$data->bilingualFields[0] ?? null, array_slice($data->bilingualFields, 1)];

            if ($nameField !== null) {
                $svg .= $this->bilingualField($colX[0], $y, $nameField, 480, $l, $labelStyle, $valueStyle);
                $y += 56;
            }

            foreach (array_values($gridFields) as $index => $field) {
                $svg .= $this->bilingualField(
                    $colX[$index % 2], $y + intdiv($index, 2) * 52, $field, $colW, $l, $labelStyle, $valueStyle,
                );
            }
            $y += 3 * 52 + 10;

            foreach ([[$l->showIssueDate, $data->issueDateFormatted, 16], [$l->showExpiryDate, $data->expiryDateFormatted, 16]] as [$visible, $value, $size]) {
                if ($visible && filled($value)) {
                    $svg .= $text($value, $y, $size);
                    $y += 30;
                }
            }
        } else {
            if ($l->showMagneticStripe) {
                $svg .= '<rect y="24" width="540" height="48" fill="rgba(0,0,0,0.5)"/>';
            }
            $svg .= $text('OFFICIAL ID / ይፋዊ መታወቂያ', 112);
            if ($data->sealDataUri) {
                $svg .= '<image id="officialSeal" x="174" y="130" width="192" height="192" href="'.$this->e($data->sealDataUri).'" preserveAspectRatio="xMidYMid meet"/>';
            }
            if ($l->showQr && $data->qrVerificationUrl !== '') {
                $qr = $this->qrRenderer->asSvgDataUri($data->qrVerificationUrl, 224);
                $svg .= '<rect x="146" y="348" width="248" height="248" fill="#FFFFFF"/>';
                $svg .= '<image x="158" y="360" width="224" height="224" href="'.$this->e($qr).'"/>';
            }
            if ($l->showReturnNotice) {
                $svg .= $text($l->returnAddressEn, 628, 13).$text($l->returnAddressAm, 654, 13);
            }
            if ($l->showCardNumber) {
                $svg .= $text($data->cardNumber, 696);
            }
            if ($l->showIssueDate && $data->issueDateFormatted) {
                $svg .= $text($data->issueDateFormatted, 724, 14);
            }
            if ($l->showEmergencyContact && $l->supportContact !== '') {
                $svg .= $text($l->supportContact, 756, 14);
            }
            if ($l->showSignature) {
                $svg .= '<line x1="160" y1="792" x2="380" y2="792" stroke="'.$this->e($color).'" stroke-dasharray="3 3"/>';
            }
        }
        $watermark = self::WATERMARKS[strtolower($data->status)] ?? null;
        if ($watermark !== null) {
            $svg .= '<text x="270" y="440" text-anchor="middle" font-size="60" fill="rgba(255,0,0,0.3)" transform="rotate(-30,270,440)">'.$watermark.'</text>';
        }

        return $this->sizeSvg($svg.'</svg>', $data);
    }

    private function backgroundImage(?string $dataUri, string $side): string
    {
        return $dataUri === null ? '' : sprintf(
            '<image id="%sBackground" width="856" height="540" href="%s" preserveAspectRatio="xMidYMid slice"/>',
            $side, $this->e($dataUri),
        );
    }

    private function openSvg(IdCardTemplate $template): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
            .' viewBox="0 0 %d %d" width="%d" height="%d" data-card-template="%s">',
            self::W, self::H, self::W, self::H, $template->value,
        );
    }

    private function defs(string $from, string $to, string $id, IdCardTemplate $template): string
    {
        [$x2, $y2, $firstOffset, $lastOffset] = match ($template) {
            IdCardTemplate::Modern => ['100%', '0%', '62%', '62%'],
            IdCardTemplate::Minimal => ['0%', '100%', '88%', '88%'],
            default => ['100%', '100%', '0%', '100%'],
        };

        return sprintf(
            '<defs>'
            .'<linearGradient id="%s" x1="0%%" y1="0%%" x2="%s" y2="%s">'
            .'<stop offset="%s" stop-color="%s"/>'
            .'<stop offset="%s" stop-color="%s"/>'
            .'</linearGradient>'
            .'</defs>',
            $this->e($id),
            $x2,
            $y2,
            $firstOffset,
            $this->e($from),
            $lastOffset,
            $this->e($to),
        );
    }

    private function cardClip(): string
    {
        return '<defs>'
            .'<clipPath id="cardClip">'
            .'<rect width="856" height="540" rx="16" ry="16"/>'
            .'</clipPath>'
            .'</defs>';
    }

    private function dotPattern(string $id, string $fill): string
    {
        return sprintf(
            '<defs>'
            .'<pattern id="%s" x="0" y="0" width="12" height="12" patternUnits="userSpaceOnUse">'
            .'<circle cx="6" cy="6" r="1" fill="%s"/>'
            .'</pattern>'
            .'</defs>',
            $id,
            $fill,
        );
    }

    private function linePattern(string $id, string $stroke): string
    {
        return sprintf(
            '<defs>'
            .'<pattern id="%s" width="12" height="12" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
            .'<line x1="0" y1="0" x2="0" y2="12" stroke="%s" stroke-width="1"/>'
            .'</pattern>'
            .'</defs>',
            $this->e($id),
            $stroke,
        );
    }

    /**
     * One front-face row: a bilingual "amharic | english" label above its value.
     */
    /**
     * One front-face row: a bilingual "amharic | english" label above its value.
     *
     * Label and value typography come from the active template. The canvas is
     * twice the card's millimetre size, so CSS pixels are doubled to match.
     */
    private function frontField(int $x, int $y, string $label, ?string $value, IdCardLayoutSettings $l, int $valueSize, bool $bold, ?int $columnWidth = null, ?IdCardTextStyle $labelStyle = null, ?IdCardTextStyle $contentStyle = null): string
    {
        if (! filled($value)) {
            return '';
        }
        $labelSize = $labelStyle ? $labelStyle->fontSizePx() * self::SCALE : 9;
        $labelColor = $labelStyle->color ?? $l->frontTextSecondary;
        $labelWeight = $labelStyle->fontWeight ?? '400';
        $contentSize = $contentStyle ? $contentStyle->fontSizePx() * self::SCALE : $valueSize;
        $contentColor = $contentStyle->color ?? $l->frontTextPrimary;
        $contentWeight = $contentStyle->fontWeight ?? ($bold ? '700' : '600');
        $limit = $this->charBudget($columnWidth ?? 700, $contentSize);

        return sprintf(
            '<text x="%d" y="%d" font-family="%s" font-size="%s" font-weight="%s" letter-spacing="0.5" fill="%s" opacity="0.85">%s</text>'
            .'<text x="%d" y="%d" font-family="%s" font-size="%s" font-weight="%s" fill="%s">%s</text>',
            $x, $y, self::FONT, $this->num($labelSize), $this->e($labelWeight), $this->e($labelColor),
            $this->e($this->trunc($label, $this->charBudget($columnWidth ?? 700, $labelSize))),
            $x, $y + 15, self::FONT, $this->num($contentSize), $this->e($contentWeight),
            $this->e($contentColor), $this->e($this->trunc((string) $value, $limit)),
        );
    }

    /**
     * Sex is shown bilingually on the card face, matching the label style.
     */
    private function genderLabel(?string $gender): ?string
    {
        return match ($gender) {
            'male' => 'ወንድ | Male',
            'female' => 'ሴት | Female',
            null, '' => null,
            default => $gender,
        };
    }

    /**
     * SVG font attributes for a styled role, falling back to the supplied
     * defaults when the template leaves that role unset.
     */
    private function styleAttrs(?IdCardTextStyle $style, float $size, string $weight, string $color): string
    {
        return sprintf(
            'font-size="%s" font-weight="%s" fill="%s"',
            $this->num($style ? $style->fontSizePx() * self::SCALE : $size),
            $this->e($style->fontWeight ?? $weight),
            $this->e($style->color ?? $color),
        );
    }

    /**
     * One front-face field as two stacked rows: the Amharic label and value,
     * then the English label and value beneath it. The label sits at a fixed
     * offset so values line up down the column.
     *
     * @param  array{0: string, 1: ?string, 2: string, 3: ?string}  $field
     */
    private function bilingualField(int $x, int $y, array $field, int $width, IdCardLayoutSettings $l, ?IdCardTextStyle $labelStyle, ?IdCardTextStyle $valueStyle): string
    {
        [$labelAm, $valueAm, $labelEn, $valueEn] = $field;

        $labelSize = $labelStyle ? $labelStyle->fontSizePx() * self::SCALE : 9;
        $valueSize = $valueStyle ? $valueStyle->fontSizePx() * self::SCALE : 14;
        $labelAttrs = $this->styleAttrs($labelStyle, $labelSize, '400', $l->frontTextSecondary);
        $valueAttrs = $this->styleAttrs($valueStyle, $valueSize, '600', $l->frontTextPrimary);

        // Values sit beside the label when the column is wide enough for the
        // longest one; narrow columns put the value on its own line instead, so
        // a label is never clipped.
        $longest = max(mb_strlen($labelAm), mb_strlen($labelEn));
        $labelW = (int) ($longest * $labelSize * 0.55 + 6);
        $inline = $labelW <= $width * 0.55;
        $lineGap = (int) max($labelSize + 2, 12);
        $rowGap = $inline ? (int) max($valueSize + 4, 18) : (int) max($valueSize + $lineGap + 4, 30);

        $valueBudget = $this->charBudget($inline ? max(24, $width - $labelW) : $width, $valueSize);
        $render = fn (?string $value): string => $this->e($this->trunc(filled($value) ? (string) $value : self::DASH, $valueBudget));
        $label = fn (string $text, int $rowY): string => sprintf(
            '<text x="%d" y="%d" font-family="%s" %s opacity="0.85">%s</text>',
            $x, $rowY, self::FONT, $labelAttrs, $this->e($text),
        );
        $value = fn (string $text, int $rowY): string => sprintf(
            '<text x="%d" y="%d" font-family="%s" %s>%s</text>',
            $inline ? $x + $labelW : $x, $rowY, self::FONT, $valueAttrs, $text,
        );

        $svg = '';
        foreach ([[$labelAm, $valueAm], [$labelEn, $valueEn]] as $row => [$rowLabel, $rowValue]) {
            $rowYPos = $y + $row * $rowGap;
            $svg .= $label($rowLabel, $rowYPos)
                .$value($render($rowValue), $inline ? $rowYPos : $rowYPos + $lineGap);
        }

        return $svg;
    }

    /**
     * Splits a bilingual label so it reads in full inside a narrow column,
     * breaking at the "|" separator rather than clipping the English half.
     *
     * @return array<int, string>
     */
    private function wrapLabel(string $label, int $budget): array
    {
        if (mb_strlen($label) <= $budget) {
            return [$label];
        }
        $parts = array_map('trim', explode('|', $label, 2));

        return count($parts) === 2 ? [$parts[0].' |', $parts[1]] : [$this->trunc($label, $budget)];
    }

    /**
     * How many characters fit in a column at a given font size. Ethiopic and
     * Latin glyphs average a little over half the font size in width, so the
     * full bilingual label fits rather than being cut at a fixed count.
     */
    private function charBudget(int $width, float $fontSize): int
    {
        return max(12, (int) floor($width / max(1.0, $fontSize * 0.55)));
    }

    /** Trims a float to a compact SVG-safe number. */
    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function datePill(
        int $x, int $y, int $w, int $h,
        string $label, string $value,
        IdCardLayoutSettings $l,
        int $labelFontSize,
    ): string {
        return sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" rx="4" ry="4"'
            .' fill="rgba(15,23,42,0.1)" stroke="rgba(15,23,42,0.1)" stroke-width="1"/>'
            .'<text x="%d" y="%d" font-family="%s" font-size="7" letter-spacing="1"'
            .' fill="%s">%s</text>'
            .'<text x="%d" y="%d" font-family="monospace" font-size="%d" font-weight="600"'
            .' fill="%s">%s</text>',
            $x, $y, $w, $h,
            $x + 6, $y + 10,
            self::FONT,
            $this->e($l->frontTextSecondary),
            $this->e($label),
            $x + 6, $y + 24,
            $labelFontSize,
            $this->e($l->frontTextPrimary),
            $this->e($value),
        );
    }

    /** @return array{0:int,1:int} [horizontal, bottom] padding in px */
    private function padValues(string $padding): array
    {
        return match ($padding) {
            'compact' => [12, 8],
            'spacious' => [20, 20],
            default => [16, 12],
        };
    }

    private function nameFontPx(string $size): int
    {
        return match ($size) {
            'xs' => 12,
            'base' => 16,
            'lg' => 20,
            default => 14,
        };
    }

    /** XML-escape a string for safe SVG text/attribute embedding. */
    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Hard-truncate with ellipsis at $max characters. */
    private function trunc(?string $s, int $max): string
    {
        if ($s === null) {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1, 'UTF-8').'…';
    }
}
