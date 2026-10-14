<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for pages and files opened through a scanned QR token.
 *
 * The token in the URL works like a key, and the employee page shows a
 * person's name and photo, so:
 *
 *  - search engines may not index, follow or archive it (the robots meta tag
 *    in FeedbackAppLayout reaches only crawlers that run the page's script);
 *  - no Referer header carries the token to another site;
 *  - no shared cache keeps a copy. A response that sets its own private
 *    max-age (the photo) keeps it; everything else is no-store.
 */
class PrivateQrPageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if (! $response->headers->hasCacheControlDirective('max-age')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
