<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Documents;

use Illuminate\Support\Facades\Log;
use Modules\Reporting\Application\Ports\UploadInspector;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\Support\UploadRejected;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Inspects an upload before it is kept.
 *
 * A Word report must be a real .docx: a ZIP whose parts are those of a
 * WordprocessingML document. The archive is read entry by entry without being
 * extracted to disk, with limits on the number of entries, the total
 * uncompressed size and the compression ratio (archive bombs). Refused:
 * macro-enabled content (vbaProject, macroEnabled content types), embedded
 * objects and ActiveX, external relationships other than plain hyperlinks
 * (remote templates, linked images and frames would make Word fetch from
 * outside), and XML that declares a DOCTYPE or entities. The XML is never
 * parsed with entity loading; it is searched as text.
 *
 * The file's extension and declared type are not trusted: content decides.
 * This is structural validation, not a guarantee that a document is harmless;
 * a malware scanner should be configured in production (see the README).
 */
final readonly class UploadedFileInspector implements UploadInspector
{
    private const ANNEX_EXTENSIONS = ['docx', 'xlsx', 'pdf', 'png', 'jpg', 'jpeg'];

    private const MAX_ENTRIES = 3000;

    private const MAX_UNCOMPRESSED_BYTES = 300 * 1024 * 1024;

    private const MAX_RATIO = 200;

    private const MAX_XML_PART_BYTES = 60 * 1024 * 1024;

    public function __construct(private ReportingSettings $settings) {}

    public function inspect(string $path, string $originalName, string $kind): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new UploadRejected('unreadable');
        }
        $size = (int) filesize($path);
        if ($size === 0 || $size > (int) $this->settings->get('max_upload_kb') * 1024) {
            throw new UploadRejected('too_large');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, $kind === 'report_document' ? ['docx'] : self::ANNEX_EXTENSIONS, true)) {
            throw new UploadRejected($kind === 'report_document' ? 'not_docx' : 'type_not_allowed');
        }

        match ($extension) {
            'docx' => $this->openXml($path, 'word/document.xml', 'not_docx'),
            'xlsx' => $this->openXml($path, 'xl/workbook.xml', 'type_not_allowed'),
            'pdf' => $this->startsWith($path, '%PDF-') ?: throw new UploadRejected('type_not_allowed'),
            default => @getimagesize($path) !== false ?: throw new UploadRejected('type_not_allowed'),
        };

        return $this->scan($path);
    }

    private function openXml(string $path, string $mainPart, string $wrongType): void
    {
        if (! $this->startsWith($path, "PK\x03\x04")) {
            throw new UploadRejected($wrongType);
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UploadRejected($wrongType);
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new UploadRejected('archive_too_large');
            }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new UploadRejected('unreadable');
                }
                $name = str_replace('\\', '/', $stat['name']);
                $lower = strtolower($name);
                $total += $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED_BYTES || ($stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > self::MAX_RATIO && $stat['size'] > 1024 * 1024)) {
                    throw new UploadRejected('archive_too_large');
                }
                if (str_contains($name, '../') || str_starts_with($name, '/')) {
                    throw new UploadRejected('unsafe_content');
                }
                if (str_contains($lower, 'vbaproject') || str_contains($lower, 'vbadata') || str_ends_with($lower, '.bin') && str_contains($lower, 'macro')) {
                    throw new UploadRejected('macros_not_allowed');
                }
                if (preg_match('#/(embeddings|activex)/#', '/'.$lower) === 1 || str_ends_with($lower, '.exe') || str_ends_with($lower, '.dll') || str_ends_with($lower, '.js') || str_ends_with($lower, '.vbs')) {
                    throw new UploadRejected('unsafe_content');
                }
                $names[$name] = $stat['size'];
            }
            if (! isset($names['[Content_Types].xml'], $names[$mainPart])) {
                throw new UploadRejected($wrongType);
            }

            foreach ($names as $name => $bytes) {
                $lower = strtolower($name);
                if (! str_ends_with($lower, '.xml') && ! str_ends_with($lower, '.rels')) {
                    continue;
                }
                if ($bytes > self::MAX_XML_PART_BYTES) {
                    throw new UploadRejected('archive_too_large');
                }
                $xml = (string) $zip->getFromName($name);
                if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                    throw new UploadRejected('unsafe_content');
                }
                if ($name === '[Content_Types].xml' && stripos($xml, 'macroEnabled') !== false) {
                    throw new UploadRejected('macros_not_allowed');
                }
                if (str_ends_with($lower, '.rels') && preg_match_all('/<Relationship\b[^>]*>/i', $xml, $relationships) > 0) {
                    foreach ($relationships[0] as $relationship) {
                        if (stripos($relationship, 'TargetMode="External"') !== false && stripos($relationship, '/relationships/hyperlink"') === false) {
                            throw new UploadRejected('external_links');
                        }
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** Runs the configured scanner, if any. Exit code 0 is clean, 1 is infected (the ClamAV convention). */
    private function scan(string $path): string
    {
        $command = config('reporting.scanner.command');
        if (! is_array($command) || $command === []) {
            return 'not_scanned';
        }
        try {
            $process = new Process([...$command, $path]);
            $process->setTimeout((float) config('reporting.scanner.timeout', 60));
            $code = $process->run();
        } catch (Throwable $e) {
            $code = -1;
            Log::warning('reporting: malware scanner could not run', ['error' => $e->getMessage()]);
        }
        if ($code === 0) {
            return 'clean';
        }
        if ($code === 1) {
            throw new UploadRejected('malware_detected');
        }
        // The scanner itself failed: refuse when scanning is mandatory, otherwise keep the file marked unscanned.
        if (config('reporting.scanner.required', false)) {
            throw new UploadRejected('unreadable');
        }

        return 'not_scanned';
    }

    private function startsWith(string $path, string $signature): bool
    {
        return file_get_contents($path, false, null, 0, strlen($signature)) === $signature;
    }
}
