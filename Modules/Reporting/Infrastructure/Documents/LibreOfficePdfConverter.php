<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Documents;

use Modules\Reporting\Application\Ports\PdfConverter;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Optional PDF preview through a headless LibreOffice, when its binary is
 * configured (REPORTING_SOFFICE_PATH). Only documents the module generated
 * itself are converted, never an uploaded file; the process runs with a
 * throwaway profile in a temporary directory and a time limit. Without the
 * binary the converter reports itself unavailable and nothing else changes.
 */
final readonly class LibreOfficePdfConverter implements PdfConverter
{
    public function __construct(private ?string $binary, private int $timeoutSeconds = 120) {}

    public function isAvailable(): bool
    {
        return $this->binary !== null && $this->binary !== '' && is_file($this->binary);
    }

    public function convert(string $wordPath, string $pdfPath): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('PDF conversion is not configured.');
        }
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reporting-pdf-'.bin2hex(random_bytes(8));
        mkdir($work, 0700, true);
        try {
            $process = new Process([
                (string) $this->binary, '--headless', '--norestore', '--nolockcheck', '--nodefault',
                '-env:UserInstallation=file:///'.ltrim(str_replace('\\', '/', $work), '/').'/profile',
                '--convert-to', 'pdf', '--outdir', $work, $wordPath,
            ]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();
            $produced = $work.DIRECTORY_SEPARATOR.pathinfo($wordPath, PATHINFO_FILENAME).'.pdf';
            if (! $process->isSuccessful() || ! is_file($produced)) {
                throw new RuntimeException('PDF conversion failed: '.mb_substr($process->getErrorOutput(), 0, 500));
            }
            rename($produced, $pdfPath);
        } finally {
            $this->remove($work);
        }
    }

    private function remove(string $directory): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
