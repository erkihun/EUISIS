<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Knowledge;

/**
 * Splits a Markdown or plain-text body into retrieval units (brief §21):
 * first by heading (`#`, `##`, `###`), then each section into windows of
 * about WINDOW characters cut on paragraph boundaries, each window starting
 * with the last OVERLAP characters of the previous one so a sentence that
 * straddles a cut is still found whole. Front matter is dropped. Deterministic:
 * the same body always yields the same chunks, so re-indexing is idempotent.
 */
final class Chunker
{
    public const WINDOW = 1200;

    public const OVERLAP = 150;

    /** @return list<array{position: int, heading: ?string, content: string}> */
    public static function chunk(string $body): array
    {
        $out = [];
        $position = 0;
        foreach (self::sections(self::stripFrontMatter($body)) as [$heading, $text]) {
            foreach (self::windows($text) as $window) {
                $out[] = ['position' => $position++, 'heading' => $heading, 'content' => $window];
            }
        }

        return $out;
    }

    /** @return list<array{0: ?string, 1: string}> heading (null before the first one) and the text under it */
    private static function sections(string $body): array
    {
        $sections = [];
        $heading = null;
        $lines = [];
        $flush = function () use (&$sections, &$heading, &$lines): void {
            $text = trim(implode("\n", $lines));
            if ($text !== '') {
                $sections[] = [$heading, $text];
            }
            $lines = [];
        };

        $inFence = false;
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            // A heading inside a fenced code block is code, not a section.
            if (preg_match('/^\s*(```|~~~)/u', $line)) {
                $inFence = ! $inFence;
            }
            if (! $inFence && preg_match('/^(#{1,3})\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                $flush();
                $heading = mb_substr(trim($m[2]), 0, 190);

                continue;
            }
            $lines[] = $line;
        }
        $flush();

        return $sections;
    }

    /** @return list<string> windows of about WINDOW chars, cut on paragraph boundaries, overlapping by OVERLAP chars */
    private static function windows(string $text): array
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $text) ?: []), fn (string $p): bool => $p !== ''));
        $windows = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            // A paragraph longer than a window is cut on whitespace so no chunk ever exceeds the limit by much.
            foreach (self::pieces($paragraph) as $piece) {
                $candidate = $current === '' ? $piece : $current."\n\n".$piece;
                if ($current !== '' && mb_strlen($candidate) > self::WINDOW) {
                    $windows[] = $current;
                    $current = self::tail($current).$piece;
                    $current = trim($current);

                    continue;
                }
                $current = $candidate;
            }
        }
        if (trim($current) !== '') {
            $windows[] = trim($current);
        }

        return $windows;
    }

    /** @return list<string> */
    private static function pieces(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= self::WINDOW) {
            return [$paragraph];
        }
        $pieces = [];
        $rest = $paragraph;
        while (mb_strlen($rest) > self::WINDOW) {
            $cut = mb_substr($rest, 0, self::WINDOW);
            $space = mb_strrpos($cut, ' ');
            if ($space === false || $space < self::WINDOW / 2) {
                $space = self::WINDOW;
            }
            $pieces[] = trim(mb_substr($rest, 0, $space));
            $rest = self::tail($pieces[count($pieces) - 1]).trim(mb_substr($rest, $space));
        }
        if (trim($rest) !== '') {
            $pieces[] = trim($rest);
        }

        return $pieces;
    }

    /** The last OVERLAP characters of a window, from a word boundary, with a trailing space. */
    private static function tail(string $window): string
    {
        if (mb_strlen($window) <= self::OVERLAP) {
            return $window.' ';
        }
        $tail = mb_substr($window, -self::OVERLAP);
        $space = mb_strpos($tail, ' ');
        if ($space !== false && $space < self::OVERLAP / 2) {
            $tail = mb_substr($tail, $space + 1);
        }

        return trim($tail).' ';
    }

    private static function stripFrontMatter(string $body): string
    {
        $body = ltrim(str_replace("\r\n", "\n", $body), "\xEF\xBB\xBF");

        return (string) preg_replace('/\A---\n.*?\n---\n/su', '', $body, 1);
    }
}
