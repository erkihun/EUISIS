<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * What the file-import screens (employees, positions) share on the HTTP side:
 * the upload size they may truthfully promise, and a template file name that
 * tells two organizations apart.
 */
trait ServesImportFiles
{
    /**
     * The largest upload that can actually arrive: the importer's own cap or
     * the server's `upload_max_filesize` / `post_max_size`, whichever is
     * smallest. The page states this size, so it never promises more than the
     * server takes.
     */
    protected function maxUploadKilobytes(int $cap): int
    {
        $limits = [$cap];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = $this->iniBytes((string) ini_get($setting));

            if ($bytes > 0) {
                $limits[] = intdiv($bytes, 1024);
            }
        }

        return max(1, min($limits));
    }

    /** 5120 => "5", 1536 => "1.5". */
    protected function formatMegabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1, '.', ''), '0'), '.');
    }

    /**
     * The Content-Disposition of a template download, named after the
     * organization's code as it is written. A code in Ethiopic letters has no
     * ASCII spelling (ማ-001 and ከአ-001 would both come out as "001"), so the
     * plain-ASCII fallback uses the start of the id instead.
     */
    protected function templateDisposition(string $prefix, Organization $organization, string $locale, string $extension): string
    {
        $code = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', (string) $organization->code), '-');
        $ascii = Str::slug((string) $organization->code);
        $ascii = preg_match('/[a-z]/', $ascii) === 1 ? $ascii : substr((string) $organization->id, 0, 8);

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $prefix.'-'.$code.'-template-'.$locale.'.'.$extension,
            $prefix.'-'.$ascii.'-template-'.$locale.'.'.$extension,
        );
    }

    /** "2M", "512K", "1G" or a plain number of bytes; 0 means no limit. */
    private function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
