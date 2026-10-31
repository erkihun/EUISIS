<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Api\Integration;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Letter;
use Modules\Court\Application\Services\Euisis\LetterDeliveries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Flow 5: the letters EUISIS delivers to its institutions, after a cursor, and each one's PDF. */
class EuisisLetterController extends Controller
{
    public function updates(Request $request, LetterDeliveries $deliveries): JsonResponse
    {
        $data = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($deliveries->after((int) ($data['since'] ?? 0), (int) ($data['limit'] ?? 100)));
    }

    public function pdf(Letter $letter, LetterDeliveries $deliveries): Response
    {
        $pdf = $deliveries->pdf($letter);
        abort_if($pdf === null, 404);

        $name = 'letter-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($letter->reference_number ?: $letter->id)).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
