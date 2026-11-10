<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Letters\Application\Services\LetterPdf;
use Modules\Letters\Domain\Models\Letter;

/** The letter on the institution's letterhead as PDF: a preview while drafting, the dispatched copy afterwards. */
final class LetterPdfController extends Controller
{
    public function __invoke(Request $request, Letter $letter, LetterPdf $pdf): Response
    {
        $this->authorize('pdf', $letter);
        $bytes = $pdf->render($letter);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$pdf->fileName($letter).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
