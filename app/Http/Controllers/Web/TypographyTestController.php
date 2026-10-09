<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Typography\DocumentFonts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Typography check sheet for the PDF renderer: English and Amharic headings,
 * mixed text, numbers, dates, a table, regular and bold, in either document
 * variant. `?format=html` shows the same sheet as a web page for comparison.
 *
 * Development and testing only. The route is not registered in production,
 * and this refuses to run there even if it were.
 */
final class TypographyTestController extends Controller
{
    public function __invoke(Request $request, DocumentFonts $fonts): Response
    {
        abort_if(app()->isProduction(), 404);

        $input = $request->validate([
            'variant' => ['nullable', Rule::in($fonts->variants())],
            'format' => ['nullable', 'in:pdf,html'],
        ]);

        $variant = $input['variant'] ?? 'report';
        $data = ['variant' => $variant, 'family' => $fonts->family($variant)];

        if (($input['format'] ?? 'pdf') === 'html') {
            return response()->view('pdf.typography-test', $data);
        }

        return Pdf::loadView('pdf.typography-test', $data)->stream("typography-{$variant}.pdf");
    }
}
