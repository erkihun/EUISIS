<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use Modules\Letters\Domain\Models\Letter;

/**
 * The printed letter's verification: a random code assigned at dispatch,
 * printed with a QR code that leads to the public check page, which confirms
 * the issuing institution, number, date and status without showing the text
 * (docs/letters-research.md: GouvCheck, India's "Authenticate notice", SFIO).
 */
class LetterVerification
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function issueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Letter::query()->where('verification_code', $code)->exists());

        return $code;
    }

    public function url(Letter $letter): ?string
    {
        return $letter->verification_code !== null ? route('letters.verify', ['code' => $letter->verification_code]) : null;
    }

    /** An SVG QR code of the check page, as a data URI dompdf can embed. */
    public function qrDataUri(Letter $letter, int $size = 110): ?string
    {
        $url = $this->url($letter);
        if ($url === null) {
            return null;
        }
        $renderer = new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim(Str::of($code)->replace([' ', '-'], '')->toString()));
    }
}
