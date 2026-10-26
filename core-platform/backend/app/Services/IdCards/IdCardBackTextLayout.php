<?php

declare(strict_types=1);

namespace App\Services\IdCards;

final class IdCardBackTextLayout
{
    /** @return array<int, array{role: string, text: string, style: IdCardTextStyle, size: float, y: float}> */
    public function lines(array $rows, float $width, float $height): array
    {
        $scale = 1.0;
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $y = 0.0;
            $lines = [];
            foreach ($rows as $row) {
                if (! filled($row['text'])) {
                    continue;
                }
                $size = $row['style']->fontSizePx() * 2 * $scale;
                foreach ($this->wrap($row['text'], max(1, $width - 2), $size) as $text) {
                    $lines[] = [...$row, 'text' => $text, 'size' => $size, 'y' => $y + $size];
                    $y += $size * 1.25;
                }
                $y += 2 * $scale;
            }
            if ($y <= $height || $attempt === 15) {
                return $lines;
            }
            $scale *= min(0.95, $height / $y);
        }

        return [];
    }

    private function width(string $text, float $size): float
    {
        $width = 0.0;
        foreach (mb_str_split($text) as $char) {
            $width += preg_match('/\s/u', $char) ? 0.33 : (mb_ord($char) >= 0x1100 ? 1 : 0.65);
        }

        return $width * $size;
    }

    private function wrap(string $text, float $width, float $size): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if ($line !== '' && $this->width($line.' '.$word, $size) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                foreach (mb_str_split(($line !== '' ? ' ' : '').$word) as $char) {
                    if ($line !== '' && $this->width($line.$char, $size) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $char;
                }
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
