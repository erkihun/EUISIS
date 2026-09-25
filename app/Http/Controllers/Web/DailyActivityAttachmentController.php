<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DailyActivityAttachment;
use App\Services\DailyActivity\DailyActivityService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence download for owner, reviewer and scoped oversight alike.
 *
 * Files live on the private disk and are only ever streamed through here,
 * after the same `view` check that guards the log itself. Always served as a
 * download with nosniff, so an uploaded file can never render inline as a
 * page on the application's origin.
 */
class DailyActivityAttachmentController extends Controller
{
    public function download(DailyActivityAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment->log);

        $disk = Storage::disk(DailyActivityService::DISK);
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
