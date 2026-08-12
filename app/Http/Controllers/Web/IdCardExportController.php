<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Services\IdCards\IdCardPngExporter;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles server-side SVG preview and PNG export of ID cards.
 *
 * Security contract:
 * - All routes are behind auth + verified middleware.
 * - Each action checks the relevant policy gate.
 * - No card lifecycle status is changed by any action here.
 * - PNG binary is never logged; QR token/hash is never exposed.
 */
class IdCardExportController extends Controller
{
    public function __construct(
        private readonly IdCardRenderDataFactory $dataFactory,
        private readonly IdCardSvgRenderer $svgRenderer,
        private readonly IdCardPngExporter $pngExporter,
        private readonly WriteAuditLogAction $audit,
    ) {}

    // ── SVG Preview ────────────────────────────────────────────────────

    public function previewFrontSvg(Request $request, IdCard $card): Response
    {
        $this->authorize('previewSvg', $card);

        $this->loadRenderRelations($card);

        $this->writeAudit($request, $card, AuditEventType::CardPreviewedSvg, 'front');

        $svg = $this->svgRenderer->renderFront($this->dataFactory->make($card));

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function previewBackSvg(Request $request, IdCard $card): Response
    {
        $this->authorize('previewSvg', $card);

        $this->loadRenderRelations($card);

        $this->writeAudit($request, $card, AuditEventType::CardPreviewedSvg, 'back');

        $svg = $this->svgRenderer->renderBack($this->dataFactory->make($card));

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    // ── PNG Export ─────────────────────────────────────────────────────

    public function exportFrontPng(Request $request, IdCard $card): Response|StreamedResponse
    {
        $this->authorize('exportPng', $card);

        $this->loadRenderRelations($card);

        $this->writeAudit($request, $card, AuditEventType::CardExportedPngServer, 'front');

        return $this->pngResponse(
            $this->svgRenderer->renderFront($this->dataFactory->make($card)),
            "id-card-{$card->card_number}-front.png",
        );
    }

    public function exportBackPng(Request $request, IdCard $card): Response|StreamedResponse
    {
        $this->authorize('exportPng', $card);

        $this->loadRenderRelations($card);

        $this->writeAudit($request, $card, AuditEventType::CardExportedPngServer, 'back');

        return $this->pngResponse(
            $this->svgRenderer->renderBack($this->dataFactory->make($card)),
            "id-card-{$card->card_number}-back.png",
        );
    }

    public function exportBothPng(Request $request, IdCard $card): Response|StreamedResponse
    {
        $this->authorize('exportPng', $card);

        $this->loadRenderRelations($card);

        $this->writeAudit($request, $card, AuditEventType::CardExportedPngServer, 'both');

        // For "both": return front PNG; the UI opens the back separately via exportBackPng.
        // A zip or combined image requires additional dependencies — keep it simple for now.
        $data = $this->dataFactory->make($card);

        return $this->pngResponse(
            $this->svgRenderer->renderFront($data),
            "id-card-{$card->card_number}-front.png",
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function pngResponse(string $svg, string $filename): Response
    {
        if (! $this->pngExporter->isAvailable()) {
            return response(
                'PNG export requires the Imagick PHP extension. Please contact the system administrator.',
                503,
                ['Content-Type' => 'text/plain'],
            );
        }

        try {
            $png = $this->pngExporter->svgToPng($svg);
        } catch (\Throwable $e) {
            return response('PNG conversion failed: '.$e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => strlen($png),
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function loadRenderRelations(IdCard $card): void
    {
        $card->load([
            'employee.currentAssignment.organization',
            'employee.currentAssignment.organizationUnit',
            'employee.currentAssignment.position',
        ]);
    }

    private function writeAudit(
        Request $request,
        IdCard $card,
        AuditEventType $eventType,
        string $side,
    ): void {
        $this->audit->execute(
            $eventType,
            $request->user(),
            $card,
            $card->employee?->currentAssignment?->organization_id,
            newValues: [
                'card_number' => $card->card_number,
                'side' => $side,
            ],
            request: $request,
        );
    }
}
