<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

/**
 * Rewrites a PDF that keeps its objects in compressed object streams (PDF 1.5
 * and later, as Word and most scanners save) into a plain PDF with a classic
 * cross-reference table, which the free FPDI parser reads. Nothing is
 * re-encoded: every object is copied as it is, the object streams are
 * unpacked and the cross-reference streams dropped. A later definition of an
 * object (an incremental update) replaces an earlier one.
 *
 * Gives up (null) on an encrypted PDF or one it cannot follow.
 */
final class PdfNormalizer
{
    public static function normalize(string $pdf): ?string
    {
        if (! str_starts_with($pdf, '%PDF') || preg_match('~/Encrypt\s+\d+\s+\d+\s+R~', $pdf) === 1) {
            return null;
        }

        $objects = [];
        $length = strlen($pdf);
        $offset = 0;
        while (preg_match('~(\d+)\s+(\d+)\s+obj\b~', $pdf, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $number = (int) $match[1][0];
            $bodyStart = $match[0][1] + strlen($match[0][0]);
            $end = self::objectEnd($pdf, $bodyStart, $length);
            if ($end === null) {
                return null;
            }
            $body = trim(substr($pdf, $bodyStart, $end - $bodyStart));
            $offset = $end + strlen('endobj');

            if (preg_match('~/Type\s*/XRef\b~', self::dictionary($body)) === 1) {
                continue;
            }
            if (preg_match('~/Type\s*/ObjStm\b~', self::dictionary($body)) === 1) {
                $inner = self::unpack($body);
                if ($inner === null) {
                    return null;
                }
                foreach ($inner as $innerNumber => $innerBody) {
                    $objects[$innerNumber] = $innerBody;
                }

                continue;
            }
            $objects[$number] = [(int) $match[2][0], $body];
        }

        if ($objects === [] || preg_match_all('~/Root\s+(\d+)\s+(\d+)\s+R~', $pdf, $roots) < 1) {
            return null;
        }
        $root = end($roots[1]).' '.end($roots[2]).' R';
        $info = preg_match_all('~/Info\s+(\d+)\s+(\d+)\s+R~', $pdf, $infos) > 0 ? end($infos[1]).' '.end($infos[2]).' R' : null;

        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            [$generation, $body] = is_array($object) ? $object : [0, $object];
            $offsets[$number] = [strlen($out), $generation];
            $out .= "{$number} {$generation} obj\n{$body}\nendobj\n";
        }

        $size = max(array_keys($objects)) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; $number++) {
            $out .= isset($offsets[$number])
                ? sprintf("%010d %05d n \n", $offsets[$number][0], $offsets[$number][1])
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size {$size} /Root {$root}".($info !== null ? " /Info {$info}" : '')." >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }

    /** Where the object starting at $from ends (the offset of its `endobj`), stepping over a stream's bytes. */
    private static function objectEnd(string $pdf, int $from, int $length): ?int
    {
        $endobj = strpos($pdf, 'endobj', $from);
        $stream = strpos($pdf, 'stream', $from);
        if ($endobj === false) {
            return null;
        }
        if ($stream === false || $stream > $endobj) {
            return $endobj;
        }

        // The stream's data starts after the keyword's end of line; its /Length says how long it is when direct.
        $data = $stream + strlen('stream');
        if (($pdf[$data] ?? '') === "\r") {
            $data++;
        }
        if (($pdf[$data] ?? '') === "\n") {
            $data++;
        }
        $dictionary = substr($pdf, $from, $stream - $from);
        if (preg_match('~/Length\s+(\d+)(?!\s+\d+\s+R)~', $dictionary, $declared) === 1) {
            $after = $data + (int) $declared[1];
            $endstream = strpos($pdf, 'endstream', $after);
            if ($endstream !== false && $endstream - $after < 4) {
                $found = strpos($pdf, 'endobj', $endstream);

                return $found === false ? null : $found;
            }
        }
        $endstream = strpos($pdf, 'endstream', $data);
        if ($endstream === false || $endstream >= $length) {
            return null;
        }
        $found = strpos($pdf, 'endobj', $endstream);

        return $found === false ? null : $found;
    }

    /** An object's dictionary: what comes before its stream, if it has one. */
    private static function dictionary(string $body): string
    {
        $stream = strpos($body, 'stream');

        return $stream === false ? $body : substr($body, 0, $stream);
    }

    /**
     * The objects an object stream holds, by number.
     *
     * @return array<int, string>|null
     */
    private static function unpack(string $body): ?array
    {
        $dictionary = self::dictionary($body);
        if (preg_match('~/N\s+(\d+)~', $dictionary, $count) !== 1 || preg_match('~/First\s+(\d+)~', $dictionary, $first) !== 1) {
            return null;
        }
        $data = self::streamData($body);
        if ($data === null) {
            return null;
        }
        if (preg_match('~/Filter\s*/FlateDecode~', $dictionary) === 1) {
            $data = @gzuncompress($data);
            if ($data === false) {
                return null;
            }
        } elseif (preg_match('~/Filter~', $dictionary) === 1) {
            return null;
        }

        $pairs = preg_split('~\s+~', trim(substr($data, 0, (int) $first[1])));
        if ($pairs === false || count($pairs) < 2 * (int) $count[1]) {
            return null;
        }
        $objects = [];
        for ($i = 0; $i < (int) $count[1]; $i++) {
            $number = (int) $pairs[2 * $i];
            $start = (int) $first[1] + (int) $pairs[2 * $i + 1];
            $next = $i + 1 < (int) $count[1] ? (int) $first[1] + (int) $pairs[2 * $i + 3] : strlen($data);
            $objects[$number] = trim(substr($data, $start, $next - $start));
        }

        return $objects;
    }

    /** The raw bytes between `stream` and `endstream`: the declared /Length of them when it is direct. */
    private static function streamData(string $body): ?string
    {
        if (preg_match('~stream\r?\n~', $body, $keyword, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $keyword[0][1] + strlen($keyword[0][0]);
        $end = strrpos($body, 'endstream');
        if ($end === false || $end < $start) {
            return null;
        }
        if (preg_match('~/Length\s+(\d+)(?!\s+\d+\s+R)~', substr($body, 0, $keyword[0][1]), $declared) === 1 && $start + (int) $declared[1] <= $end) {
            return substr($body, $start, (int) $declared[1]);
        }

        return rtrim(substr($body, $start, $end - $start), "\r\n");
    }
}
