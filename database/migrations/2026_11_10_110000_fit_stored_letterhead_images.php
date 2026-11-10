<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Letters\Application\Services\LetterImageFitter;

/**
 * Letters: emblems and stamps uploaded before images were fitted at upload
 * are brought down to the size they print at. A 7,473 × 4,368 logo needs over
 * 150 MB to decode, more than a web request gets on the live server, so the
 * letterhead's edit page and every PDF printed on it answered 500. Migrations
 * run from the command line, where memory is not rationed.
 *
 * No schema change. Best effort: a file that cannot be decoded here is left
 * as it is and named in the log; `php artisan letters:fit-images` repeats it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('letter_letterheads') || ! Schema::hasColumn('letter_letterheads', 'images')) {
            return;
        }
        try {
            $report = app(LetterImageFitter::class)->fitStored();
            if ($report['failed'] !== []) {
                Log::warning('Letters: stored letterhead images that could not be fitted', ['files' => $report['failed']]);
            }
        } catch (Throwable $e) {
            Log::warning('Letters: fitting stored letterhead images failed: '.$e->getMessage());
        }
    }

    public function down(): void
    {
        // The originals are not kept; there is nothing to restore.
    }
};
