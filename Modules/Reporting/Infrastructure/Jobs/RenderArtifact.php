<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use Modules\Reporting\Application\Ports\PdfConverter;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Infrastructure\Documents\ReportDocumentFactory;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Consolidation;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use Throwable;

/**
 * Generates one file from an immutable snapshot: queued → processing →
 * completed, or failed with the error kept on the row. A failure touches only
 * the artifact; the submission it belongs to is unaffected and the file can
 * be generated again.
 */
final class RenderArtifact implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds between tries */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $artifactId) {}

    public function handle(ReportDocumentFactory $factory, DocumentRenderer $renderer, PdfConverter $pdf, ReportingSettings $settings): void
    {
        if ($settings->isPaused()) {
            // The row stays queued; institutional-reporting:run artifacts picks it up after the switch is turned off.
            return;
        }
        // Claim under a lock so a duplicate job (a retry racing the scheduled recovery) does nothing.
        $artifact = DB::transaction(function (): ?Artifact {
            $row = Artifact::query()->whereKey($this->artifactId)->lockForUpdate()->first();
            if ($row === null || $row->status === 'completed' || ($row->status === 'processing' && $row->started_at?->gt(CarbonImmutable::now('UTC')->subMinutes(15)))) {
                return null;
            }
            $row->forceFill(['status' => 'processing', 'attempts' => $row->attempts + 1, 'started_at' => CarbonImmutable::now('UTC'), 'error' => null])->save();

            return $row;
        });
        if ($artifact === null) {
            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'rpt');
        $word = $temporary.'.docx';
        try {
            $language = (string) config('reporting.document_language', 'am');
            $document = match ($artifact->owner_type) {
                'submission' => $factory->forSubmission(Submission::query()->findOrFail($artifact->owner_id), $artifact->label, $language),
                'consolidation' => $factory->forConsolidation(Consolidation::query()->findOrFail($artifact->owner_id), $language),
            };
            $renderer->renderWord($document, $word);
            $source = $word;
            if ($artifact->format === 'pdf') {
                $source = $temporary.'.pdf';
                $pdf->convert($word, $source);
            }

            $disk = (string) config('reporting.disk');
            $path = sprintf('reporting/generated/%s/%s/%s-%s.%s', $artifact->owner_type, $artifact->owner_id, $artifact->label, $artifact->id, $artifact->format);
            $stream = fopen($source, 'rb');
            Storage::disk($disk)->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $artifact->forceFill(['status' => 'completed', 'disk' => $disk, 'path' => $path, 'size' => (int) filesize($source), 'completed_at' => CarbonImmutable::now('UTC')])->save();

            // A PDF preview is requested only once the Word file exists, and only when a converter is configured.
            if ($artifact->format === 'docx' && $pdf->isAvailable()) {
                $preview = Artifact::query()->firstOrCreate(['owner_type' => $artifact->owner_type, 'owner_id' => $artifact->owner_id, 'format' => 'pdf', 'label' => $artifact->label], ['status' => 'queued']);
                $preview->forceFill(['status' => 'queued', 'error' => null])->save();
                self::dispatch($preview->id);
            }
        } catch (Throwable $e) {
            $artifact->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        } finally {
            foreach ([$temporary, $word, $temporary.'.pdf'] as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
