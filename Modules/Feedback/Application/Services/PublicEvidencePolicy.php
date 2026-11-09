<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use Illuminate\Http\UploadedFile;

/**
 * Which files a citizen may attach to a Tip-off or a Complaint, and how big.
 *
 * A file is judged by its CONTENT: the MIME type is the one PHP's finfo reads
 * from the bytes, never the type the browser claimed. The extension must also
 * be on the list, the name must be safe (SafeUploadName), and the size limit
 * is the one for the kind the content turned out to be. HTML, SVG, scripts
 * and executables are on no list, whatever they are renamed to.
 *
 * Limits fit the production PHP upload settings documented in
 * docs/public-evidence-security.md; the server's own limits must be at least
 * as large or PHP drops the file before this policy sees it.
 *
 * Evidence is what the citizen submitted, not proof of what it shows.
 */
final class PublicEvidencePolicy
{
    public const MAX_FILES = 5;

    /** Every file of one submission together. */
    public const MAX_TOTAL_KB = 75 * 1024;

    /** @var array<string, array{extensions: list<string>, mimes: list<string>, max_kb: int}> */
    public const KINDS = [
        'photo' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
            'max_kb' => 5 * 1024,
        ],
        'video' => [
            'extensions' => ['mp4', 'mov', 'webm', '3gp', 'm4v'],
            'mimes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp', 'video/x-m4v'],
            'max_kb' => 50 * 1024,
        ],
        'audio' => [
            'extensions' => ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'amr'],
            'mimes' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/x-hx-aac-adts', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'audio/ogg', 'audio/opus', 'audio/amr'],
            'max_kb' => 20 * 1024,
        ],
        // The complaint form's approved document type, kept for both.
        'document' => [
            'extensions' => ['pdf'],
            'mimes' => ['application/pdf'],
            'max_kb' => 5 * 1024,
        ],
    ];

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_values(array_merge(...array_column(self::KINDS, 'extensions')));
    }

    /** @return list<string> */
    public static function mimes(): array
    {
        return array_values(array_merge(...array_column(self::KINDS, 'mimes')));
    }

    /** The kind the file's content is, or null when the content is on no list. */
    public static function kindOf(UploadedFile $file): ?string
    {
        return self::kindForMime((string) $file->getMimeType());
    }

    public static function kindForMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));

        foreach (self::KINDS as $kind => $rules) {
            if (in_array($mime, $rules['mimes'], true)) {
                return $kind;
            }
        }

        return null;
    }

    /** The `accept` hint for the page; the server check above is what counts. */
    public static function acceptHint(): string
    {
        return 'image/*,video/*,audio/*,application/pdf,'.implode(',', array_map(fn (string $ext): string => '.'.$ext, self::extensions()));
    }

    /** @return array<string, mixed> */
    public static function toPublicArray(): array
    {
        return [
            'max_files' => self::MAX_FILES,
            'max_total_mb' => intdiv(self::MAX_TOTAL_KB, 1024),
            'max_mb' => array_map(fn (array $rules): int => intdiv($rules['max_kb'], 1024), self::KINDS),
            'accept' => self::acceptHint(),
        ];
    }
}
