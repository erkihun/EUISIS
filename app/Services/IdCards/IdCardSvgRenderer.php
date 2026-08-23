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
        $l = $data->layout;

        [$padH, $padBottom] = $this->padValues($l->padding);
        $photoX = $padH;
        $photoY = 52;
        $photoW = 72;
        $photoH = $l->template === IdCardTemplate::Modern ? 72 : 96;
        $textX = $photoX + $photoW + 12;
        $textW = self::W - $padH - $textX;

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
        if ($l->template !== IdCardTemplate::Minimal) {
            $svg .= $l->template === IdCardTemplate::Modern
                ? $this->linePattern('frontLines', 'rgba(255,255,255,0.05)')
                : $this->dotPattern('frontDots', 'rgba(255,255,255,0.06)');
            $svg .= sprintf(
                '<rect width="%d" height="%d" fill="url(#%s)"/>',
                self::W,
                self::H,
                $l->template === IdCardTemplate::Modern ? 'frontLines' : 'frontDots',
            );
        }

        // "EMPLOYEE ID" ghost watermark
        if ($l->template !== IdCardTemplate::Minimal) {
            $svg .= sprintf(
                '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="80"'
                .' font-weight="900" fill="rgba(255,255,255,0.04)"'
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
            : 'rgba(255,255,255,0.15)';
        $svg .= sprintf('<rect x="0" y="0" width="856" height="40" fill="%s"/>', $headerFill);
        if ($l->template === IdCardTemplate::Modern) {
            $svg .= '<line x1="0" y1="40" x2="856" y2="40" stroke="rgba(255,255,255,0.20)" stroke-width="1"/>';
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
                '<circle cx="26" cy="20" r="14" fill="rgba(255,255,255,0.2)"/>'
                .'<text x="26" y="25" text-anchor="middle" font-family="%s" font-size="9"'
                .' font-weight="700" fill="%s">AA</text>',
                self::FONT,
                $this->e($l->frontTextPrimary),
            );
        }

        // City name & bureau name
        $svg .= sprintf(
            '<text x="48" y="21" font-family="%s" font-size="10" font-weight="700"'
            .' fill="%s">%s</text>',
            self::FONT,
            $this->e($l->frontTextPrimary),
            $this->e($this->trunc($l->cityNameEn, 52)),
        );
        $svg .= sprintf(
            '<text x="48" y="33" font-family="%s" font-size="8" fill="%s">%s</text>',
            self::FONT,
            $this->e($l->frontTextSecondary),
            $this->e($this->trunc($l->bureauNameEn, 60)),
        );

        // "OFFICIAL ID" badge (right side)
        $svg .= sprintf(
            '<rect x="714" y="13" width="130" height="20" rx="3" ry="3"'
            .' fill="rgba(255,255,255,0.2)" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>'
            .'<text x="779" y="27" text-anchor="middle" font-family="%s" font-size="8"'
            .' font-weight="700" letter-spacing="1" fill="%s">OFFICIAL ID</text>',
            self::FONT,
            $this->e($l->frontTextPrimary),
        );

        // ── Photo ────────────────────────────────────────────────────────
        if ($l->showPhoto && $data->photoDataUri !== null) {
            $svg .= sprintf(
                '<clipPath id="photoClip">'
                .'<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>'
                .'</clipPath>'
                .'<image x="%d" y="%d" width="%d" height="%d" href="%s"'
                .' clip-path="url(#photoClip)" preserveAspectRatio="xMidYMid slice"'
                .' style="outline:1px solid rgba(255,255,255,0.2)"/>',
                $photoX, $photoY, $photoW, $photoH,
                $l->template === IdCardTemplate::Modern ? 36 : 6,
                $l->template === IdCardTemplate::Modern ? 36 : 6,
                $photoX, $photoY, $photoW, $photoH,
                $data->photoDataUri,
            );
        } elseif ($l->showPhoto) {
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"'
                .' fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>'
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

        // ── Labels below photo ───────────────────────────────────────────
        $belowPhotoY = $photoY + $photoH + 10;

        if ($l->showEmployeeNumber) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d"'
                .' letter-spacing="1" fill="%s">ID</text>',
                $photoX, $belowPhotoY,
                self::FONT, $labelFontSize,
                $this->e($l->frontTextSecondary),
            );
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d"'
                .' font-weight="600" fill="%s">%s</text>',
                $photoX, $belowPhotoY + 13,
                self::FONT, $labelFontSize,
                $this->e($l->frontTextPrimary),
                $this->e($this->trunc($data->employeeNumber ?? '—', 14)),
            );
        }
        if ($l->showCardNumber) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d"'
                .' letter-spacing="1" fill="%s">CARD</text>',
                $photoX, $belowPhotoY + 28,
                self::FONT, $labelFontSize,
                $this->e($l->frontTextSecondary),
            );
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d"'
                .' fill="%s">%s</text>',
                $photoX, $belowPhotoY + 41,
                self::FONT, $labelFontSize,
                $this->e($l->frontTextPrimary),
                $this->e($this->trunc($data->cardNumber, 16)),
            );
        }

        // ── Text column ───────────────────────────────────────────────────
        // Name
        $displayName = $l->showFullNameEn
            ? ($data->fullNameEn ?? ($l->showFullNameAm ? $data->fullNameAm : null))
            : ($l->showFullNameAm ? $data->fullNameAm : null);
        if ($displayName !== null) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d"'
                .' font-weight="700" fill="%s">%s</text>',
                $textX, $photoY + 18,
                self::FONT, $nameFontSize,
                $this->e($l->frontTextPrimary),
                $this->e($this->trunc($displayName, 28)),
            );
        }

        // Amharic name (if different from primary display)
        $amName = $l->showFullNameAm ? $data->fullNameAm : null;
        if ($amName !== null && $amName !== $displayName) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="11"'
                .' fill="%s" opacity="0.8">%s</text>',
                $textX, $photoY + 33,
                self::FONT,
                $this->e($l->frontTextSecondary),
                $this->e($this->trunc($amName, 30)),
            );
            $offsetY = 20;
        } else {
            $offsetY = 4;
        }

        // Position
        if ($l->showPosition && $data->positionTitleEn !== null) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="10"'
                .' fill="%s">%s</text>',
                $textX, $photoY + 33 + $offsetY,
                self::FONT,
                $this->e($l->frontTextSecondary),
                $this->e($this->trunc($data->positionTitleEn.($data->positionCode ? ' ('.$data->positionCode.')' : ''), 40)),
            );
        }

        // Organization
        if ($l->showOrganization && $data->organizationNameEn !== null) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="9"'
                .' fill="%s" opacity="0.8">%s</text>',
                $textX, $photoY + 48 + $offsetY,
                self::FONT,
                $this->e($l->frontTextSecondary),
                $this->e($this->trunc($data->organizationNameEn, 48)),
            );
        }

        // ── Date pills ───────────────────────────────────────────────────
        if ($l->showOrganizationUnit && $data->organizationUnitNameEn !== null) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="9" fill="%s" opacity="0.8">%s</text>',
                $textX, $photoY + 63 + $offsetY, self::FONT,
                $this->e($l->frontTextSecondary),
                $this->e($this->trunc($data->organizationUnitNameEn, 48)),
            );
        }

        if (($l->showJobGrade && $data->jobGrade !== null) || ($l->showEmploymentStatus && $data->employmentStatus !== null)) {
            $meta = implode(' • ', array_filter([
                $l->showJobGrade ? $data->jobGrade : null,
                $l->showEmploymentStatus ? $data->employmentStatus : null,
            ]));
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="8" fill="%s" opacity="0.75">%s</text>',
                $textX, $photoY + 78 + $offsetY, self::FONT,
                $this->e($l->frontTextSecondary),
                $this->e($this->trunc($meta, 48)),
            );
        }

        $pillY = 478;
        $pillH = 32;
        $pillW = (int) (($textW - 8) / 2);
        $pillX2 = $textX + $pillW + 8;

        if ($l->showIssueDate && $data->issueDateFormatted !== null) {
            $svg .= $this->datePill($textX, $pillY, $pillW, $pillH, 'ISSUE DATE', $data->issueDateFormatted, $l, $labelFontSize);
        }
        if ($l->showExpiryDate && $data->expiryDateFormatted !== null) {
            $svg .= $this->datePill($pillX2, $pillY, $pillW, $pillH, 'EXP', $data->expiryDateFormatted, $l, $labelFontSize);
        }

        // ── Bottom accent bar ─────────────────────────────────────────────
        $svg .= '<defs><linearGradient id="footerGrad" x1="0%" y1="0%" x2="100%" y2="0%">'
            .'<stop offset="0%" stop-color="rgba(255,255,255,0.12)"/>'
            .'<stop offset="100%" stop-color="rgba(255,255,255,0.06)"/>'
            .'</linearGradient></defs>';
        $svg .= sprintf(
            '<rect x="0" y="520" width="856" height="20" fill="url(#footerGrad)"/>'
            .'<line x1="0" y1="520" x2="856" y2="520" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>',
        );
        $svg .= sprintf(
            '<text x="%d" y="534" font-family="%s" font-size="7" letter-spacing="2"'
            .' fill="%s" opacity="0.7">AUTHORIZED OFFICIAL ID</text>',
            $padH,
            self::FONT,
            $this->e($l->frontTextSecondary),
        );

        // Right-edge decorative accent
        if ($l->template === IdCardTemplate::Classic) {
            $svg .= '<polygon points="812,0 856,0 856,540 824,540" fill="rgba(255,255,255,0.05)"/>';
        } elseif ($l->template === IdCardTemplate::Modern) {
            $svg .= '<circle cx="842" cy="14" r="78" fill="none" stroke="rgba(255,255,255,0.10)" stroke-width="14"/>';
        }

        $svg .= '</g>';
        $svg .= '</svg>';

        return $svg;
    }

    public function renderBack(IdCardRenderData $data): string
    {
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

        $qrLabelY = $qrBlockTop + $labelH;
        $qrBoxY = $qrBlockTop + $labelH + $gap;
        $qrNoteY = $qrBoxY + $qrBoxH + $gap + $noteH;
        $qrBoxX = $padH;
        $qrCenterX = $qrBoxX + (int) ($qrBoxW / 2);

        $textX = $qrBoxX + $qrBoxW + 12;
        $textW = self::W - $padH - $textX;

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
        if ($l->template !== IdCardTemplate::Minimal) {
            $svg .= $l->template === IdCardTemplate::Modern
                ? $this->linePattern('backLines', 'rgba(255,255,255,0.04)')
                : $this->dotPattern('backDots', 'rgba(255,255,255,0.04)');
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
        // "SCAN TO VERIFY" label
        $svg .= sprintf(
            '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="7"'
            .' font-weight="600" letter-spacing="2" fill="%s">SCAN TO VERIFY</text>',
            $qrCenterX, $qrLabelY,
            self::FONT,
            $this->e($l->backTextColor),
        );

        // White QR background box
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" rx="4" ry="4" fill="white"/>',
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

        // "QR — no personal info" note
        $svg .= sprintf(
            '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="6"'
            .' fill="%s" opacity="0.55">QR contains no personal info</text>',
            $qrCenterX, $qrNoteY,
            self::FONT,
            $this->e($l->backTextColor),
        );

        // ── Text column ───────────────────────────────────────────────────
        $topTextY = $contentTop + 18;

        // "OFFICIAL IDENTIFICATION CARD"
        $svg .= sprintf(
            '<text x="%d" y="%d" font-family="%s" font-size="9"'
            .' font-weight="700" letter-spacing="1" fill="%s">OFFICIAL IDENTIFICATION CARD</text>',
            $textX, $topTextY,
            self::FONT,
            $this->e($l->backTextColor),
        );

        // Property notice
        if ($l->showReturnNotice) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="7" fill="%s" opacity="0.65">%s</text>',
                $textX, $topTextY + 14,
                self::FONT,
                $this->e($l->backTextColor),
                $this->e($this->trunc('If found, please return to the issuing bureau.', 55)),
            );
        }

        // Verification URL
        if ($l->verificationUrl !== '') {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="7" fill="%s" opacity="0.5">%s</text>',
                $textX, $topTextY + 28,
                self::FONT,
                $this->e($l->backTextColor),
                $this->e($this->trunc($l->verificationUrl, 50)),
            );
        }

        // Official seal — maximized within the open center of the text column.
        if ($data->sealDataUri !== null) {
            $sealSize = 192;
            $sealX = $textX + (int) (($textW - $sealSize) / 2);
            $sealY = $contentTop + (int) (($contentH - $sealSize) / 2);
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
        $bottomY = self::H - 8 - 38; // leave room for 3 lines

        if ($l->showCardNumber) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="8"'
                .' font-weight="600" letter-spacing="1" fill="%s">%s</text>',
                $textX, $bottomY,
                self::FONT,
                $this->e($l->backTextColor),
                $this->e($data->cardNumber),
            );
        }

        if ($l->showEmergencyContact && $l->supportContact !== '') {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="7" fill="%s" opacity="0.55">%s</text>',
                $textX, $bottomY + 13,
                self::FONT,
                $this->e($l->backTextColor),
                $this->e($this->trunc($l->supportContact, 50)),
            );
        }

        $returnAddr = $l->returnAddressEn;
        if ($l->showReturnNotice) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="7" fill="%s" opacity="0.45">%s</text>',
                $textX, $bottomY + ($l->supportContact !== '' ? 26 : 13),
                self::FONT,
                $this->e($l->backTextColor),
                $this->e($this->trunc($returnAddr, 58)),
            );
        }

        // Bottom thin accent
        $svg .= '<defs><linearGradient id="backFooter" x1="0%" y1="0%" x2="100%" y2="0%">'
            .'<stop offset="0%" stop-color="rgba(255,255,255,0.08)"/>'
            .'<stop offset="100%" stop-color="rgba(255,255,255,0.03)"/>'
            .'</linearGradient></defs>';
        $svg .= '<rect x="0" y="536" width="856" height="4" fill="url(#backFooter)"/>';

        $svg .= '</g>';
        $svg .= '</svg>';

        return $svg;
    }

    // ── Private helpers ────────────────────────────────────────────────

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

    private function datePill(
        int $x, int $y, int $w, int $h,
        string $label, string $value,
        IdCardLayoutSettings $l,
        int $labelFontSize,
    ): string {
        return sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" rx="4" ry="4"'
            .' fill="rgba(255,255,255,0.1)" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>'
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
