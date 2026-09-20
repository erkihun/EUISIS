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

    /** Most lines the back notice may occupy before it stops printing. */
    private const MAX_NOTICE_LINES = 6;

    /** The SVG canvas is twice the card's millimetre size. */
    private const SCALE = 2;

    /**
     * Fields whose two language labels print on one line, above a single value.
     * These carry an abbreviated label ("መ.ቁ / ID.No") for a value that reads
     * the same in either language, so a second row would only repeat it.
     *
     * Mirrored in IdCardBilingualField.tsx.
     */
    private const JOINED_LABEL_FIELDS = ['idNumber', 'phone'];

    /**
     * Fields that always print their value on the line below the label, however
     * much room the column has. The ID number reads as a heading with the
     * number beneath it rather than as one long line.
     *
     * Mirrored in IdCardBilingualField.tsx.
     */
    private const STACKED_VALUE_FIELDS = ['idNumber'];

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

        // Logo / placeholder. The template supplies both whether a logo shows
        // and which image it is; the organization's is the fallback.
        $header = $data->header;
        $showLogo = $l->showOrganizationLogo && ($header?->showLogo ?? true);
        // Each mark has its own positionable box, so one can be moved or resized
        // without disturbing the other or the band they sit in.
        $showSecondary = $showLogo && ($header?->showSecondaryLogo ?? true);
        $primaryBox = $data->box('front', 'logo_primary');
        $secondaryBox = $data->box('front', 'logo_secondary');
        $primaryR = (int) round(min($primaryBox?->wIn(self::W) ?? 56, $primaryBox?->hIn(self::H) ?? 36) / 2);
        $secondaryR = (int) round(min($secondaryBox?->wIn(self::W) ?? 56, $secondaryBox?->hIn(self::H) ?? 36) / 2);
        $primaryCx = ($primaryBox?->xIn(self::W) ?? $headerX + 12) + $primaryR;
        $primaryCy = ($primaryBox?->yIn(self::H) ?? $headerY + 6) + $primaryR;
        $secondaryCx = ($secondaryBox?->xIn(self::W) ?? $headerX + $headerW - 12 - $secondaryR * 2) + $secondaryR;
        $secondaryCy = ($secondaryBox?->yIn(self::H) ?? $headerY + 6) + $secondaryR;
        $svg .= $this->headerLogo(
            'logoClip', $primaryCx, $primaryCy, $primaryR,
            $showLogo, $data->logoDataUri, $l->frontTextPrimary,
        );
        $svg .= $this->headerLogo(
            'logoClipSecondary', $secondaryCx, $secondaryCy, $secondaryR,
            $showSecondary, $header?->secondaryLogoDataUri, $l->frontTextPrimary,
        );

        // City name, from the template's header content.
        $headerStyle = $data->textStyle('front', 'header');
        // The text starts after the primary mark and stops before the secondary
        // one, so moving either logo reflows the text rather than colliding.
        $headerTextX = $showLogo ? max($headerX + 12, $primaryCx + $primaryR + 6) : $headerX + 12;
        $textRight = $showSecondary
            ? min($headerX + $headerW - 12, $secondaryCx - $secondaryR - 6)
            : $headerX + $headerW - 12;
        $headerTextW = max(40, $textRight - $headerTextX);
        // The budget has to use the size actually rendered, which the template
        // may override — not the fallback passed to styleAttrs().
        $cityPx = $headerStyle ? $headerStyle->fontSizePx() * self::SCALE : 10;
        // The header carries the city name only, in both languages. Bureau
        // names belong to the card body, not the header band.
        $headerLines = [
            [$header?->cityNameAm ?? $l->cityNameAm, $cityPx, 10, '700', $l->frontTextPrimary],
            [$header?->cityNameEn ?? $l->cityNameEn, $cityPx, 10, '700', $l->frontTextPrimary],
        ];
        // Duplicate lines are dropped: an install whose English setting holds
        // Amharic text would otherwise print the same name twice.
        $seen = [];
        $headerLines = array_values(array_filter(
            $headerLines,
            static function (array $row) use (&$seen): bool {
                $line = trim((string) $row[0]);
                if ($line === '' || isset($seen[$line])) {
                    return false;
                }
                $seen[$line] = true;

                return true;
            },
        ));

        // The lines share the band's height rather than running past it.
        $count = max(1, count($headerLines));
        $step = (int) max(9, min(14, ($headerH - 8) / $count));
        $lineY = $headerY + $step;
        foreach ($headerLines as [$line, $px, $fallbackPx, $weight, $color]) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s>%s</text>',
                $headerTextX, $lineY,
                self::FONT,
                $this->styleAttrs($headerStyle, min($px, $step - 1), $weight, $color),
                $this->e($this->trunc($line, $this->charBudget($headerTextW, min($px, $step - 1)))),
            );
            $lineY += $step;
        }

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

        // Name leads the body and phone sits with the other identity fields;
        // only the ID number is emphasised on its own row near the footer.
        // Keyed, not label-matched — the labels are translatable display text.
        $emphasised = ['idNumber'];
        $identity = [];
        $bottom = [];
        foreach ($data->bilingualFields as $field) {
            if (in_array($field[4] ?? '', $emphasised, true)) {
                $bottom[$field[4]] = $field;
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

        // Lower band: the emphasised ID number.
        $emphasisBox = $data->box('front', 'emphasis');
        $bottomX = $emphasisBox?->xIn(self::W) ?? $padH;
        $bottomW = $emphasisBox?->wIn(self::W) ?? (self::W - 2 * $padH);
        $bottomY = $emphasisBox?->yIn(self::H) ?? 430;
        // The ID number is the only field in this band, so it gets the whole
        // width; halving it here truncated the value.
        if ($idField = $bottom['idNumber'] ?? null) {
            $svg .= $this->bilingualField(
                $bottomX, $bottomY, $idField, $bottomW, $l, $labelStyle, $valueStyle,
            );
        }

        // Issue / expiry dates — their own movable block on the front, so a
        // card's validity reads without turning it over.
        $datesBox = $data->box('front', 'dates');
        $datesX = $datesBox?->xIn(self::W) ?? $padH;
        $datesY = $datesBox?->yIn(self::H) ?? 470;
        $datesW = $datesBox?->wIn(self::W) ?? (self::W - 2 * $padH);
        $dateStyle = $this->styleAttrs($data->textStyle('front', 'value'), 8, '600', $l->frontTextPrimary);
        $dateLabelStyle = $this->styleAttrs($data->textStyle('front', 'label'), 7, '400', $l->frontTextSecondary);
        foreach (array_values(array_filter([
            $l->showIssueDate ? [$data->issueDateLabel, $data->issueDateFormattedAm, $data->issueDateFormatted] : null,
            $l->showExpiryDate ? [$data->expiryDateLabel, $data->expiryDateFormattedAm, $data->expiryDateFormatted] : null,
        ], fn (?array $row): bool => $row !== null && (filled($row[1]) || filled($row[2])))) as $index => [$caption, $amValue, $enValue]) {
            $x = (int) ($datesX + $index * ($datesW / 2));
            // Caption, then the Ethiopian date above the Gregorian one.
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.7">%s</text>',
                $x, $datesY, self::FONT, $dateLabelStyle, $this->e($caption),
            );
            foreach (array_values(array_filter([$amValue, $enValue], 'filled')) as $line => $value) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-family="%s" %s>%s</text>',
                    $x, $datesY + 13 + $line * 13, self::FONT, $dateStyle,
                    $this->e($this->trunc((string) $value, 24)),
                );
            }
        }

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
            $qrDataUri = $this->qrRenderer->idCardSvgDataUri($data->qrVerificationUrl, $qrSize);
            if ($qrDataUri !== '') {
                $svg .= sprintf(
                    '<image x="%d" y="%d" width="%d" height="%d" href="%s"/>',
                    $qrBoxX + 4, $qrBoxY + 4, $qrSize, $qrSize,
                    $qrDataUri,
                );
            }
        }

        // ── Text column ───────────────────────────────────────────────────
        // The notice follows its own box on both axes. Taking only x from the
        // box and y from the top of the card printed a footer notice across
        // the QR block.
        $noticeSize = $data->textStyle('back', 'footer')?->fontSizePx() !== null
            ? $data->textStyle('back', 'footer')->fontSizePx() * self::SCALE
            : 7.0;
        $topTextY = $notesBox !== null
            ? $notesBox->yIn(self::H) + (int) ceil($noticeSize)
            : $contentTop + 18;

        // Property notice leads the notes block: Amharic first, then English,
        // both from ID card settings so the printed card matches the preview.
        $noticeLines = 0;
        if ($l->showReturnNotice) {
            $noticeStyle = $data->textStyle('back', 'footer');
            $noticeSize = $noticeStyle?->fontSizePx() !== null ? $noticeStyle->fontSizePx() * self::SCALE : 7.0;
            foreach ([$l->backNoticeAm, $l->backNoticeEn] as $notice) {
                if ($notice === '') {
                    continue;
                }
                // Wrapped, not truncated: an administrator's wording prints in
                // full, however long, rather than being cut mid-sentence.
                foreach ($this->wrapNotice($notice, (float) $textW, $noticeSize) as $line) {
                    if ($noticeLines >= self::MAX_NOTICE_LINES) {
                        break 2;
                    }
                    $svg .= sprintf(
                        '<text x="%d" y="%d" font-family="%s" %s opacity="0.65">%s</text>',
                        $textX, $topTextY + $noticeLines * 12,
                        self::FONT,
                        $this->styleAttrs($noticeStyle, 7, '400', $l->backTextColor),
                        $this->e($line),
                    );
                    $noticeLines++;
                }
            }
        }

        // Verification URL, below however many notice lines printed.
        if ($l->verificationUrl !== '') {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.5">%s</text>',
                $textX, $topTextY + max(1, $noticeLines) * 12 + 2,
                self::FONT,
                $this->styleAttrs($data->textStyle('back', 'label'), 7, '400', $l->backTextColor),
                $this->e($this->trunc($l->verificationUrl, 50)),
            );
        }

        // Employee photo as a security watermark. Drawn before the seal and the
        // signature so it sits behind them rather than over them.
        $svg .= $this->backPhoto($data);

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

        // Card number — its own movable section.
        if ($l->showCardNumber) {
            $numberBox = $data->box('back', 'card_number');
            $numberX = $numberBox?->xIn(self::W) ?? $textX;
            $numberY = $numberBox?->yIn(self::H) ?? (self::H - 72);
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="%s" %s opacity="0.7">%s</text>'
                .'<text x="%d" y="%d" font-family="%s" %s letter-spacing="1">%s</text>',
                $numberX, $numberY, self::FONT,
                $this->styleAttrs($data->textStyle('back', 'label'), 7, '400', $l->backTextColor),
                $this->e($data->cardNumberLabel !== '' ? $data->cardNumberLabel : 'Card No'),
                $numberX, $numberY + 15, self::FONT,
                $this->styleAttrs($data->textStyle('back', 'value'), 8, '600', $l->backTextColor),
                $this->e($data->cardNumber),
            );
        }

        // Signature — its own movable section.
        $signatureBox = $data->box('back', 'signature');
        if ($signatureBox !== null) {
            $sigX = $signatureBox->xIn(self::W);
            $sigY = $signatureBox->yIn(self::H);
            $sigW = $signatureBox->wIn(self::W);
            // The block owns its whole box: the space signed in, then the
            // rule, then the caption naming it — a signature block as it reads
            // on paper, so a long bilingual caption never squeezes the space.
            $sigH = max(20, $signatureBox->hIn(self::H));
            $ruleY = $sigY + $sigH - 2;
            $svg .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="rgba(15,23,42,0.35)" stroke-width="1"/>',
                $sigX, $ruleY, $sigX + $sigW, $ruleY,
            );
            // The template's signature image, sitting on the rule it signs.
            if ($data->signatureDataUri !== null) {
                $imageTop = $sigY + 2;
                $svg .= sprintf(
                    '<image id="templateSignature" x="%d" y="%d" width="%d" height="%d" href="%s"'
                    .' preserveAspectRatio="xMidYMax meet"/>',
                    $sigX, $imageTop, $sigW, max(8, $ruleY - $imageTop), $this->e($data->signatureDataUri),
                );
            }
        }

        // The signature caption — its own movable block, one row per language.
        $captionBox = $data->box('back', 'signature_label');
        if ($captionBox !== null) {
            $captionRows = array_values(array_filter([
                $data->signatureLabelAm,
                $data->signatureLabelEn !== '' ? $data->signatureLabelEn : 'Authorized Signature',
            ], fn (string $row): bool => $row !== ''));
            $captionX = $captionBox->xIn(self::W);
            $captionTop = $captionBox->yIn(self::H) + 8;
            foreach ($captionRows as $row => $caption) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-family="%s" %s opacity="0.7">%s</text>',
                    $captionX, $captionTop + $row * 10, self::FONT,
                    $this->styleAttrs($data->textStyle('back', 'label'), 7, '400', $l->backTextColor),
                    $this->e($caption),
                );
            }
        }

        // Emergency contact — who to call if the holder needs help.
        $emergencyBox = $data->box('back', 'emergency');
        if ($data->emergencyContactFields !== []) {
            $emergencyX = $emergencyBox?->xIn(self::W) ?? $padH;
            $emergencyY = $emergencyBox?->yIn(self::H) ?? (self::H - 60);
            $emergencyW = $emergencyBox?->wIn(self::W) ?? 200;
            $emergencyH = $emergencyBox?->hIn(self::H) ?? 97;
            // Same stacked amharic-then-english rows as the front face. The
            // fields share the box's height so the block cannot run off the
            // card, however many contacts there are.
            $rows = max(1, count($data->emergencyContactFields));
            $step = (int) max(40, min(92, $emergencyH / $rows));
            // SVG text hangs from its baseline, so the first row starts a line
            // below the box's top edge; otherwise a block placed near the top
            // of the card loses its first line off the edge.
            $emergencyLabelSize = $data->textStyle('back', 'label')?->fontSizePx() !== null
                ? $data->textStyle('back', 'label')->fontSizePx() * self::SCALE
                : 9.0;
            $emergencyTop = $emergencyY + (int) ceil($emergencyLabelSize);
            foreach ($data->emergencyContactFields as $index => $field) {
                $svg .= $this->bilingualField(
                    $emergencyX, $emergencyTop + $index * $step, $field, $emergencyW, $l,
                    $data->textStyle('back', 'label'), $data->textStyle('back', 'value'),
                );
            }
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
        // Physical units, not pixels: a bare pixel width leaves the printed
        // size to whatever DPI the viewer assumes, so an 85.6 mm card came out
        // around 226 mm wide. The viewBox keeps the drawing coordinates and
        // the QR modules square.
        $mm = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'mm';

        return preg_replace(
            '/(<svg\b[^>]*\b)width="\d+" height="\d+"/',
            '${1}width="'.$mm($data->widthMm).'" height="'.$mm($data->heightMm).'"',
            $svg, 1,
        ) ?? $svg;
    }

    private function renderPortrait(IdCardRenderData $data, string $side): string
    {
        $front = $side === 'front';
        $l = $data->layout;
        $color = $front ? $l->frontTextPrimary : $l->backTextColor;
        $from = $front ? $l->frontBgFrom : $l->backBgFrom;
        $to = $front ? $l->frontBgTo : $l->backBgTo;
        $background = $front ? $data->frontBackgroundDataUri : $data->backBackgroundDataUri;
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 856" width="540" height="856" data-card-template="'.$l->template->value.'">';
        $svg .= $this->defs($from, $to, 'portraitBg', $l->template)
            .'<rect width="540" height="856" rx="24" fill="url(#portraitBg)"/>';
        if ($background !== null) {
            $svg .= '<image id="'.$side.'Background" width="540" height="856" href="'.$this->e($background).'" preserveAspectRatio="xMidYMid slice"/>';
        }

        $box = static fn (IdCardRenderData $data, string $face, string $element): array => (function ($value): array {
            return [$value->xIn(540), $value->yIn(856), $value->wIn(540), $value->hIn(856)];
        })($data->box($face, $element));
        $centerText = fn (string $value, int $x, int $y, int $size, string $fill = null, string $weight = '600'): string => sprintf(
            '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="%d" font-weight="%s" fill="%s">%s</text>',
            $x, $y, self::FONT, $size, $weight, $this->e($fill ?? $color), $this->e($this->trunc($value, 52)),
        );

        if ($front) {
            [$hx, $hy, $hw, $hh] = $box($data, 'front', 'header');
            $header = $data->header;
            // The portrait header names only the employee's actual employer;
            // global city/bureau settings must not repeat above it.
            $lineBudget = min(52, $this->charBudget(max(1, $hw - 16), 16));
            $lines = $this->wrapText((string) $data->organizationNameAm, $lineBudget);
            if ($data->organizationNameEn) {
                array_push($lines, ...$this->wrapText($data->organizationNameEn, $lineBudget));
            }
            $lineHeight = max(10, min(22, (int) floor(max(1, $hh - 8) / max(1, count($lines)))));
            $fontSize = max(8, min(16, $lineHeight - 2));
            $contentHeight = count($lines) * $lineHeight;
            $firstBaseline = $hy + max($fontSize, intdiv(max(0, $hh - $contentHeight), 2) + $fontSize);
            foreach ($lines as $index => $line) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="%d" font-weight="700" fill="%s">%s</text>',
                    $hx + intdiv($hw, 2), $firstBaseline + $index * $lineHeight,
                    self::FONT, $fontSize, $this->e($color), $this->e((string) $line),
                );
            }

            foreach ([['logo_primary', $data->organizationLogoDataUri ?? $data->logoDataUri, $header?->showLogo ?? true], ['logo_secondary', $header?->secondaryLogoDataUri, $header?->showSecondaryLogo ?? true]] as [$element, $uri, $visible]) {
                if ($l->showOrganizationLogo && $visible && $uri) {
                    [$x, $y, $w, $h] = $box($data, 'front', $element);
                    $svg .= '<image x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" href="'.$this->e($uri).'" preserveAspectRatio="xMidYMid meet"/>';
                }
            }
            if ($l->showPhoto && $data->photoDataUri) {
                [$x, $y, $w, $h] = $box($data, 'front', 'photo');
                $svg .= '<image x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" href="'.$this->e($data->photoDataUri).'" preserveAspectRatio="xMidYMid slice"/>';
            }
            [$x, $y, $w] = $box($data, 'front', 'employee_name');
            $nameStyle = $data->textStyle('front', 'employee_name');
            foreach (array_values(array_unique(array_filter([$data->fullNameAm, $data->fullNameEn]))) as $index => $line) {
                $svg .= $centerText(
                    (string) $line, $x + intdiv($w, 2), $y + 22 + $index * 24,
                    (int) (($nameStyle?->fontSizePx() ?? 12) * self::SCALE),
                    $nameStyle?->color, $nameStyle?->fontWeight ?? '700',
                );
            }
            [$x, $y, $w] = $box($data, 'front', 'employee_position');
            $positionStyle = $data->textStyle('front', 'employee_position');
            $positionLines = array_values(array_filter([$data->positionTitleAm, $data->positionTitleEn]));
            foreach ($positionLines as $index => $line) {
                $svg .= $centerText(
                    (string) $line, $x + intdiv($w, 2), $y + 18 + $index * 20,
                    (int) (($positionStyle?->fontSizePx() ?? 9) * self::SCALE),
                    $positionStyle?->color, $positionStyle?->fontWeight ?? '600',
                );
            }
            [$x, $y, $w] = $box($data, 'front', 'emphasis');
            $svg .= $centerText($data->employeeNumber ?: $data->cardNumber, $x + intdiv($w, 2), $y + 24, 18);
        } else {
            if ($l->showQr && $data->feedbackQrUrl) {
                [$x, $y, $w, $h] = $box($data, 'back', 'qr');
                $size = max(1, min($w, $h - 28));
                $qr = $this->qrRenderer->idCardSvgDataUri($data->feedbackQrUrl, $size);
                $svg .= $centerText('Feedback and Suggestion QR', $x + intdiv($w, 2), $y + 18, 14)
                    .'<rect x="'.($x + intdiv($w - $size, 2)).'" y="'.($y + 26).'" width="'.$size.'" height="'.$size.'" fill="#FFFFFF"/>'
                    .'<image x="'.($x + intdiv($w - $size, 2)).'" y="'.($y + 26).'" width="'.$size.'" height="'.$size.'" href="'.$this->e($qr).'"/>';
            }
            if ($data->sealDataUri) {
                [$x, $y, $w, $h] = $box($data, 'back', 'seal');
                $svg .= '<image id="officialSeal" x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" href="'.$this->e($data->sealDataUri).'" preserveAspectRatio="xMidYMid meet"/>';
            }
        }
        $watermark = self::WATERMARKS[strtolower($data->status)] ?? null;
        if ($watermark !== null) {
            $svg .= '<text x="270" y="440" text-anchor="middle" font-size="60" fill="rgba(255,0,0,0.3)" transform="rotate(-30,270,440)">'.$watermark.'</text>';
        }

        return $this->sizeSvg($svg.'</svg>', $data);
    }

    /** Kept as a reference for templates saved before portrait positioning. */
    private function renderLegacyPortrait(IdCardRenderData $data, string $side): string
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
            // Header content is per template, falling back to the global setting.
            $header = $data->header;
            $svg .= $text($header?->cityNameAm ?? $l->cityNameAm, 32)
                .$text($header?->cityNameEn ?? $l->cityNameEn, 58);
            // Primary mark on the left, secondary mirrored on the right.
            if ($l->showOrganizationLogo && ($header?->showLogo ?? true) && $data->logoDataUri) {
                $svg .= '<image x="24" y="20" width="44" height="44" href="'.$this->e($data->logoDataUri).'"/>';
            }
            if ($l->showOrganizationLogo && ($header?->showSecondaryLogo ?? true) && $header?->secondaryLogoDataUri) {
                $svg .= '<image x="472" y="20" width="44" height="44" href="'.$this->e($header->secondaryLogoDataUri).'"/>';
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
            if ($data->sealDataUri) {
                $svg .= '<image id="officialSeal" x="174" y="130" width="192" height="192" href="'.$this->e($data->sealDataUri).'" preserveAspectRatio="xMidYMid meet"/>';
            }
            if ($l->showQr && $data->qrVerificationUrl !== '') {
                $qr = $this->qrRenderer->idCardSvgDataUri($data->qrVerificationUrl, 224);
                $svg .= '<rect x="146" y="348" width="248" height="248" fill="#FFFFFF"/>';
                $svg .= '<image x="158" y="360" width="224" height="224" href="'.$this->e($qr).'"/>';
            }
            if ($l->showReturnNotice) {
                // Amharic first, then English, matching the card face.
                $svg .= $text($l->returnAddressAm, 628, 13).$text($l->returnAddressEn, 654, 13);
            }
            if ($l->showCardNumber) {
                $svg .= $text($data->cardNumber, 696);
            }
            // Issue and expiry print on the front, so the back does not repeat
            // the issue date — matching the landscape face.
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
    /**
     * The employee photo on the back face, as a security watermark.
     *
     * Off unless the template turns it on, so an existing card is unchanged.
     * Opacity and contrast are clamped on the way in by IdCardBackPhoto, which
     * matters because both are inlined into the SVG.
     */
    private function backPhoto(IdCardRenderData $data): string
    {
        $photo = $data->backPhoto;
        if ($photo === null || ! $photo->show || $data->photoDataUri === null) {
            return '';
        }

        $box = $data->box('back', 'photo');
        if ($box === null) {
            return '';
        }

        [$x, $y, $w, $h] = [$box->xIn(self::W), $box->yIn(self::H), $box->wIn(self::W), $box->hIn(self::H)];
        $svg = '';

        // A backing colour shows through a transparent PNG, and fills the gaps
        // a "contain" fit leaves at the edges.
        if ($photo->backgroundColor !== null) {
            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" fill="%s" opacity="%s"/>',
                $x, $y, $w, $h, $this->e($photo->backgroundColor), $this->num($photo->opacity / 100),
            );
        }

        return $svg.sprintf(
            '<image id="backPhoto" x="%d" y="%d" width="%d" height="%d" href="%s"'
            .' preserveAspectRatio="%s" opacity="%s" style="filter:contrast(%s%%)"/>',
            $x, $y, $w, $h,
            $data->photoDataUri,
            $photo->preserveAspectRatio(),
            $this->num($photo->opacity / 100),
            $photo->contrast,
        );
    }

    /**
     * One circular header logo, or the initials placeholder when the slot is
     * shown but has no image. A hidden slot draws nothing at all, so the header
     * text can use the space.
     *
     * The clip path id must be unique per slot, or the second logo reuses the
     * first one's circle and lands in the wrong place.
     */
    private function headerLogo(
        string $clipId,
        int $cx,
        int $cy,
        int $r,
        bool $show,
        ?string $dataUri,
        string $placeholderColor,
    ): string {
        if (! $show) {
            return '';
        }

        if ($dataUri !== null) {
            return sprintf(
                '<clipPath id="%s"><circle cx="%d" cy="%d" r="%d"/></clipPath>'
                .'<image x="%d" y="%d" width="%d" height="%d" href="%s"'
                .' clip-path="url(#%s)" preserveAspectRatio="xMidYMid slice"/>',
                $clipId, $cx, $cy, $r,
                $cx - $r, $cy - $r, $r * 2, $r * 2,
                $dataUri, $clipId,
            );
        }

        return sprintf(
            '<circle cx="%d" cy="%d" r="%d" fill="rgba(15,23,42,0.2)"/>'
            .'<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="9"'
            .' font-weight="700" fill="%s">%s</text>',
            $cx, $cy, $r,
            $cx, $cy + 5,
            self::FONT,
            $this->e($placeholderColor),
            $this->e(IdCardHeaderContent::FALLBACK_INITIALS),
        );
    }

    private function bilingualField(int $x, int $y, array $field, int $width, IdCardLayoutSettings $l, ?IdCardTextStyle $labelStyle, ?IdCardTextStyle $valueStyle): string
    {
        [$labelAm, $valueAm, $labelEn, $valueEn] = $field;

        // The ID and phone labels are abbreviations of a value that reads the
        // same in either language, so they share one line rather than printing
        // an identical value twice. Keyed, not inferred from the values, so a
        // name that happens to match in both languages still gets two rows.
        $joins = in_array($field[4] ?? '', self::JOINED_LABEL_FIELDS, true);
        if ($joins) {
            $labelAm = $labelAm.' / '.$labelEn;
        }

        $labelSize = $labelStyle ? $labelStyle->fontSizePx() * self::SCALE : 9;
        $valueSize = $valueStyle ? $valueStyle->fontSizePx() * self::SCALE : 14;
        $labelAttrs = $this->styleAttrs($labelStyle, $labelSize, '400', $l->frontTextSecondary);
        $valueAttrs = $this->styleAttrs($valueStyle, $valueSize, '600', $l->frontTextPrimary);

        // Values sit beside the label when the column is wide enough for the
        // longest one; narrow columns put the value on its own line instead, so
        // a label is never clipped.
        // A joined label occupies one row on its own, so it only sits inline
        // when it still leaves the value room; otherwise the value drops to the
        // next line rather than being truncated to fit beside it.
        // The ID number always stacks, so the emphasised band reads as a label
        // with the number beneath it rather than a long single line.
        $longest = $joins ? mb_strlen($labelAm) : max(mb_strlen($labelAm), mb_strlen($labelEn));
        $labelW = (int) ($longest * $labelSize * 0.55 + 6);
        $inline = match (true) {
            in_array($field[4] ?? '', self::STACKED_VALUE_FIELDS, true) => false,
            $joins => $labelW + (int) (mb_strlen((string) $valueAm) * $valueSize * 0.55) <= $width,
            default => $labelW <= $width * 0.55,
        };
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
        $rows = $joins ? [[$labelAm, $valueAm]] : [[$labelAm, $valueAm], [$labelEn, $valueEn]];
        foreach ($rows as $row => [$rowLabel, $rowValue]) {
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

    /**
     * Splits text into lines of at most $perLine characters, breaking on words
     * so an address wraps instead of being clipped mid-word.
     *
     * @return array<int, string>
     */
    private function wrapText(string $text, int $perLine): array
    {
        $text = trim($text);
        if ($text === '' || $perLine < 1) {
            return [];
        }

        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $perLine) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            while (mb_strlen($word) > $perLine) {
                $lines[] = mb_substr($word, 0, $perLine);
                $word = mb_substr($word, $perLine);
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
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
    /**
     * Break text into lines that fit a column, using the same glyph-width
     * heuristic as the browser-side BackTextBlock so the preview and the
     * printed card wrap at the same words. Ethiopic glyphs are full width,
     * Latin about two-thirds, whitespace a third.
     *
     * @return list<string>
     */
    private function wrapNotice(string $text, float $width, float $size): array
    {
        $measure = function (string $value) use ($size): float {
            $units = 0.0;
            foreach (mb_str_split($value) as $char) {
                $units += preg_match('/\s/u', $char) ? 0.33 : (mb_ord($char) >= 0x1100 ? 1 : 0.65);
            }

            return $units * $size;
        };

        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $measure($candidate) > $width) {
                $lines[] = $line;
                $line = $word;

                continue;
            }
            $line = $candidate;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

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
