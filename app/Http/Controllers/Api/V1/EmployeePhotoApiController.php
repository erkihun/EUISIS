<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Integration\EmployeePhotoLinkService;
use App\Support\Api\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/employees/{employee}/photo?principal=…&expires=…&signature=…
 *
 * Serves an ID photo to the holder of a signed link issued by the card
 * verification endpoint (F17). The `signed` middleware has already rejected a
 * forged or expired link; this controller re-checks the issuing principal's
 * standing and organization bound, then streams the file from the private
 * disk with no-store and nosniff. A stale or out-of-bounds link is a 404, not
 * a hint that the employee exists.
 */
class EmployeePhotoApiController extends Controller
{
    public function __construct(private readonly EmployeePhotoLinkService $links) {}

    public function __invoke(Request $request, Employee $employee): Response
    {
        $principal = $this->links->decode($request->query('principal'));

        if ($principal === null || ! $this->links->mayServe($principal, $employee)) {
            return ApiError::response($request, 404, 'not_found', 'Not Found.');
        }

        $path = (string) $employee->photo_path;

        if ($path === '' || ! preg_match('#^[A-Za-z0-9_./-]+\.(jpg|jpeg|png|webp)$#D', $path) || str_contains($path, '..')) {
            return ApiError::response($request, 404, 'not_found', 'Not Found.');
        }

        // New photos live on the private disk; legacy ones were written to the public disk.
        $disk = str_starts_with($path, 'employee-photos/') ? 'local' : 'public';

        if (! Storage::disk($disk)->exists($path)) {
            return ApiError::response($request, 404, 'not_found', 'Not Found.');
        }

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
