<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeePhotoStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The holder's photo behind a scan result, reached only through the signed,
 * short-lived link EmployeeScanPhoto puts on that result. The signature binds
 * the employee id, so the link cannot be pointed at someone else. A small
 * derived copy is served, never the original upload, and only as an image.
 */
class ScanPhotoController extends Controller
{
    private const MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __invoke(Employee $employee): StreamedResponse
    {
        $photo = EmployeePhotoStorage::thumbnail($employee->photo_path);
        abort_if($photo === null, 404);

        $disk = Storage::disk($photo['disk']);
        $mime = (string) $disk->mimeType($photo['path']);
        abort_unless(array_key_exists($mime, self::MIME_TYPES), 404);

        return $disk->response($photo['path'], 'photo.'.self::MIME_TYPES[$mime], [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
