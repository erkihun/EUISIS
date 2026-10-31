<?php

declare(strict_types=1);

namespace Modules\Court\Application\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The part of simple-qrcode's fluent API the court's letters use
 * (`QrCode::format('svg')->encoding()->size()->errorCorrection()->generate()`),
 * on bacon/bacon-qr-code, which EUISIS already ships. SVG only.
 */
final class QrCode
{
    private int $size = 100;

    private int $margin = 0;

    private string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING;

    private string $level = 'M';

    public static function format(string $format): self
    {
        if (strtolower($format) !== 'svg') {
            throw new \InvalidArgumentException('Only SVG QR codes are supported.');
        }

        return new self;
    }

    public function size(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function encoding(string $encoding): self
    {
        $this->encoding = $encoding;

        return $this;
    }

    public function margin(int $margin): self
    {
        $this->margin = $margin;

        return $this;
    }

    public function errorCorrection(string $level): self
    {
        $this->level = strtoupper($level);

        return $this;
    }

    public function generate(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($this->size, $this->margin), new SvgImageBackEnd));

        return $writer->writeString($text, $this->encoding, ErrorCorrectionLevel::forBits(match ($this->level) {
            'L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2, default => 0,
        }));
    }
}
