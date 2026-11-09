<?php

declare(strict_types=1);

namespace App\Services\IdCards;

use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * A batch as files for card-printing software or a print shop: each card's
 * frozen front and back (vector SVG, so they print sharp at any resolution)
 * plus a manifest listing who is on which card, in print order.
 *
 * Only prepared cards are included — the files are the snapshots the batch
 * records, never a fresh render that could differ from what was confirmed.
 */
final class IdCardPrintBatchExporter
{
    /**
     * @param  Collection<int, CardPrintBatchItem>  $items  in print order, with card.employee and snapshot loaded
     * @return string absolute path of a temporary zip the caller deletes
     */
    public function zip(CardPrintBatch $batch, Collection $items): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cards-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the batch archive.');
        }

        $disk = Storage::disk('local');
        $manifest = fopen('php://temp', 'w+');
        fputcsv($manifest, ['sequence', 'card_number', 'employee_number', 'employee_name', 'organization', 'front_file', 'back_file']);
        $sequence = 0;
        foreach ($items as $item) {
            $artifact = $item->snapshot?->artifact_path;
            if ($artifact === null) {
                continue;
            }
            $sequence++;
            $base = sprintf('%03d-%s', $sequence, preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $item->card?->card_number));
            $files = [];
            foreach (['front', 'back'] as $side) {
                $source = $artifact.'-'.$side.'.svg';
                if ($disk->exists($source)) {
                    $files[$side] = $base.'-'.$side.'.svg';
                    $zip->addFromString($files[$side], (string) $disk->get($source));
                }
            }
            $employee = $item->card?->employee;
            fputcsv($manifest, [
                $sequence,
                $item->card?->card_number,
                $employee?->employee_number,
                $employee?->full_name,
                $employee?->currentAssignment?->organization?->name_en,
                $files['front'] ?? '',
                $files['back'] ?? '',
            ]);
        }
        rewind($manifest);
        // A BOM so spreadsheet software reads the Amharic names as UTF-8.
        $zip->addFromString('manifest.csv', "\u{FEFF}".stream_get_contents($manifest));
        fclose($manifest);
        $zip->setArchiveComment($batch->batch_number);
        $zip->close();

        return $path;
    }
}
