<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services;

use Modules\Court\Domain\Models\FileSecurityRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class SecureUploadService
{
    /** @param array{related_type?: string, related_id?: int, user_id?: int, applicant_id?: int} $context */
    public function store(
        UploadedFile $file,
        string $directory,
        string $disk = 'court_private',
        array $context = [],
    ): string
    {
        $scan = $this->scan($file);

        if ($scan['status'] === 'infected') {
            throw ValidationException::withMessages([
                'file' => __('The uploaded file failed the security scan.'),
            ]);
        }

        if (config('court.compliance.uploads.require_clean_scan', false) && $scan['status'] !== 'clean') {
            throw ValidationException::withMessages([
                'file' => __('The file scanner is unavailable. Please try again later.'),
            ]);
        }

        $path = $file->store($directory, $disk);
        if ($path === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        try {
            FileSecurityRecord::query()->create([
                'public_id' => (string) Str::uuid7(),
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'detected_mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()) ?: null,
                'scan_status' => $scan['status'],
                'scanner' => $scan['scanner'],
                'scan_message' => $scan['message'],
                'related_type' => $context['related_type'] ?? null,
                'related_id' => $context['related_id'] ?? null,
                'uploaded_by_user_id' => $context['user_id'] ?? null,
                'uploaded_by_applicant_id' => $context['applicant_id'] ?? null,
                'scanned_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return $path;
    }

    /** @return array{status: string, scanner: ?string, message: ?string} */
    public function scan(UploadedFile $file): array
    {
        if (config('court.compliance.uploads.scanner', 'none') !== 'clamav') {
            return ['status' => 'not_configured', 'scanner' => null, 'message' => null];
        }

        $process = new Process([
            (string) config('court.compliance.uploads.scanner_binary', 'clamscan'),
            '--no-summary',
            $file->getRealPath(),
        ]);
        $process->setTimeout((float) config('court.compliance.uploads.scanner_timeout_seconds', 30));

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return ['status' => 'scanner_error', 'scanner' => 'clamav', 'message' => $exception->getMessage()];
        }

        return match ($process->getExitCode()) {
            0 => ['status' => 'clean', 'scanner' => 'clamav', 'message' => trim($process->getOutput())],
            1 => ['status' => 'infected', 'scanner' => 'clamav', 'message' => trim($process->getOutput())],
            default => ['status' => 'scanner_error', 'scanner' => 'clamav', 'message' => trim($process->getErrorOutput())],
        };
    }
}
