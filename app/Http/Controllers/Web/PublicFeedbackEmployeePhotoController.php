<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\ServiceFeedback\PublicFeedbackTargetResolver;
use App\Support\EmployeePhotoStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The photo on the public employee feedback page.
 *
 * Reached only through the signed, short-lived link that
 * PublicEmployeeFeedbackIdentity puts on the page. The scanned token in the
 * path decides whose photo this is — there is no employee id to change — and
 * the token and the employee are checked again on every request, so a revoked
 * token or an employee leaving service ends the photo immediately. An invalid
 * link, an unavailable token and a missing photo all answer the same 404, and
 * the page falls back to its neutral avatar.
 *
 * What is served is a small derived copy (EmployeePhotoStorage::thumbnail),
 * under a fixed file name and only as JPEG, PNG or WebP. The storage path
 * never leaves the server.
 */
class PublicFeedbackEmployeePhotoController extends Controller
{
    private const MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Browser-only reuse across the screens of one visit; shorter than the signed link's life. */
    private const BROWSER_CACHE_SECONDS = 300;

    public function __construct(
        private readonly PublicFeedbackTargetResolver $resolver,
        private readonly PublicFeedbackSettings $settings,
    ) {}

    public function __invoke(string $token): StreamedResponse
    {
        $record = $this->settings->enabled() ? $this->resolver->employeeTokenForPublic($token) : null;

        abort_if($record === null, 404);

        $photo = EmployeePhotoStorage::thumbnail($record->employee->photo_path);

        if ($photo === null) {
            if (filled($record->employee->photo_path)) {
                Log::warning('Public feedback photo is not in storage', ['feedback_token_id' => $record->getKey()]);
            }

            abort(404);
        }

        $disk = Storage::disk($photo['disk']);
        $mime = (string) $disk->mimeType($photo['path']);

        if (! array_key_exists($mime, self::MIME_TYPES)) {
            Log::warning('Public feedback photo is not a JPEG, PNG or WebP image', ['feedback_token_id' => $record->getKey(), 'mime' => $mime]);

            abort(404);
        }

        return $disk->response($photo['path'], 'photo.'.self::MIME_TYPES[$mime], [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age='.self::BROWSER_CACHE_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
