<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Services\AuditWriter;
use App\Services\IdCards\CoreIdCardRenderDataFactory;
use App\Services\IdCards\IdCardPngExporter;
use App\Services\IdCards\IdCardSvgRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printed card from the EUISIS renderer (EUISIS IdCardExportController):
 * each face as SVG for the card page's print and export windows, and as PNG
 * where Imagick can rasterise SVG. Every render is audited.
 */
class IdCardExportController extends Controller
{
    public function __construct(
        private readonly CoreIdCardRenderDataFactory $dataFactory,
        private readonly IdCardSvgRenderer $svgRenderer,
        private readonly IdCardPngExporter $pngExporter,
        private readonly AuditWriter $audit,
    ) {}

    public function previewFrontSvg(Request $request, IdCard $card): Response
    {
        return $this->svg($request, $card, 'front');
    }

    public function previewBackSvg(Request $request, IdCard $card): Response
    {
        return $this->svg($request, $card, 'back');
    }

    public function exportFrontPng(Request $request, IdCard $card): Response
    {
        return $this->png($request, $card, 'front');
    }

    public function exportBackPng(Request $request, IdCard $card): Response
    {
        return $this->png($request, $card, 'back');
    }

    public function exportBothPng(Request $request, IdCard $card): Response
    {
        return $this->png($request, $card, 'front', 'both');
    }

    private function svg(Request $request, IdCard $card, string $side): Response
    {
        $this->authorize('previewSvg', $card);
        $this->audit->write('card.previewed_svg', $request->user(), $card, $card->employee?->currentAssignment?->organization_id, null, ['card_number' => $card->card_number, 'side' => $side]);

        // An SVG opened on its own is a document: it runs nothing and loads nothing but its own data: images.
        return response($this->render($request, $card, $side), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function png(Request $request, IdCard $card, string $side, ?string $audited = null): Response
    {
        $this->authorize('exportPng', $card);
        $this->audit->write('card.exported_png', $request->user(), $card, $card->employee?->currentAssignment?->organization_id, null, ['card_number' => $card->card_number, 'side' => $audited ?? $side]);
        if (! $this->pngExporter->isAvailable()) {
            return response('PNG export requires the Imagick PHP extension with SVG support. Please contact the system administrator.', 503, ['Content-Type' => 'text/plain']);
        }
        try {
            $png = $this->pngExporter->svgToPng($this->render($request, $card, $side));
        } catch (\Throwable $e) {
            return response('PNG conversion failed: '.$e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="id-card-'.$card->card_number.'-'.$side.'.png"',
            'Content-Length' => strlen($png),
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function render(Request $request, IdCard $card, string $side): string
    {
        $orientation = $request->validate(['orientation' => ['sometimes', 'in:portrait,landscape']])['orientation'] ?? null;
        $data = $this->dataFactory->make($card, $orientation);

        return $side === 'back' ? $this->svgRenderer->renderBack($data) : $this->svgRenderer->renderFront($data);
    }
}
