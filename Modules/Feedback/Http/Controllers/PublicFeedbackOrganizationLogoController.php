<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Application\Services\PublicFeedbackSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The institution's logo on the public organization feedback page.
 *
 * The scanned token in the path decides whose logo this is, and it is
 * checked again on every request, so the page never carries the
 * organization's internal id or its storage path (both are in the logo's
 * /storage URL). An unknown, disabled or revoked token, an inactive
 * institution and a missing logo all answer the same 404, and the page shows
 * its neutral building icon.
 */
class PublicFeedbackOrganizationLogoController extends Controller
{
    private const MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    private const BROWSER_CACHE_SECONDS = 300;

    public function __construct(
        private readonly OrganizationFeedbackQrService $codes,
        private readonly PublicFeedbackSettings $settings,
    ) {}

    public function __invoke(string $token): StreamedResponse
    {
        $path = $this->settings->enabled() ? $this->codes->resolveForPublic($token)?->organization?->logo_path : null;
        $disk = Storage::disk('public');

        abort_if(! is_string($path) || $path === '' || ! $disk->exists($path), 404);

        $mime = (string) $disk->mimeType($path);
        abort_unless(array_key_exists($mime, self::MIME_TYPES), 404);

        return $disk->response($path, 'logo.'.self::MIME_TYPES[$mime], [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age='.self::BROWSER_CACHE_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
            // An SVG logo is shown as an image only, never run as a document.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
