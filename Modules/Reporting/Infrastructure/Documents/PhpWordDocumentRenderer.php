<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Documents;

use Modules\Reporting\Application\Data\ReportDocument;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Produces a real, editable Word (.docx) file with PHPWord.
 *
 * Amharic: every string is written as UTF-8 text with XML escaping on, and
 * the configured document font (an Ethiopic-capable face such as Nyala,
 * Ebrima or Noto Sans Ethiopic) is named for all scripts. The font is named,
 * not embedded: a machine without it shows the text in its own Ethiopic font.
 *
 * Names and times in the sign-off block are text recorded by the workflow.
 * No signature image is drawn and nothing is signed cryptographically; the
 * document says so.
 */
final readonly class PhpWordDocumentRenderer implements DocumentRenderer
{
    public function __construct(private HtmlToWord $html, private string $font, private float $fontSize = 11.0) {}

    public function renderWord(ReportDocument $document, string $targetPath): void
    {
        Settings::setOutputEscapingEnabled(true);
        $t = fn (string $key, array $replace = []): string => (string) trans("reporting::document.{$key}", $replace, $document->language);

        $word = new PhpWord;
        $word->setDefaultFontName($this->font);
        $word->setDefaultFontSize($this->fontSize);
        $word->getDocInfo()->setTitle($document->title)->setCreator($document->institutionName)->setCompany($document->institutionName)
            ->setSubject($document->periodLabel)->setDescription((string) $document->referenceNo);
        $word->addTitleStyle(1, ['name' => $this->font, 'size' => 14, 'bold' => true], ['spaceBefore' => 280, 'spaceAfter' => 120, 'keepNext' => true]);

        $section = $word->addSection(['marginTop' => 1300, 'marginBottom' => 1200, 'marginLeft' => 1300, 'marginRight' => 1300]);
        $muted = ['size' => 9, 'color' => '555555'];

        // Header: logo (when configured) and the institution; footer: identifiers, label and page numbers.
        $header = $section->addHeader();
        $headerTable = $header->addTable(['borderBottomSize' => 6, 'borderBottomColor' => '808080']);
        $headerTable->addRow();
        if ($document->logoPath !== null && is_file($document->logoPath)) {
            $headerTable->addCell(1200)->addImage($document->logoPath, ['height' => 34]);
        }
        $headerCell = $headerTable->addCell(8100);
        $headerCell->addText($document->institutionName, ['bold' => true, 'size' => 10], ['spaceAfter' => 0]);
        $headerCell->addText($document->title.' · '.$document->periodLabel, $muted, ['spaceAfter' => 60]);

        $footer = $section->addFooter();
        $footer->addText(implode('  ·  ', array_filter([
            $document->referenceNo, $document->versionNo === null ? null : $t('version', ['n' => $document->versionNo]), $t("label_{$document->label}"),
        ])), $muted, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $footer->addPreserveText($t('page_of', ['page' => '{PAGE}', 'pages' => '{NUMPAGES}']), $muted, ['alignment' => Jc::CENTER]);

        // Title block.
        $section->addText($t("label_{$document->label}"), ['bold' => true, 'size' => 10, 'color' => $document->label === 'approved' ? '1B5E20' : '8A4B00'], ['alignment' => Jc::END, 'spaceAfter' => 0]);
        $section->addText($document->title, ['bold' => true, 'size' => 18], ['alignment' => Jc::CENTER, 'spaceBefore' => 200, 'spaceAfter' => 80]);
        $section->addText($document->periodLabel, ['size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 240]);

        $facts = array_values(array_filter([
            ['label' => $t('institution'), 'value' => $document->institutionName],
            $document->receivingName === null ? null : ['label' => $t('submitted_to'), 'value' => $document->receivingName],
            ['label' => $t('reporting_period'), 'value' => $document->periodLabel],
            $document->referenceNo === null ? null : ['label' => $t('reference_no'), 'value' => $document->referenceNo],
            $document->versionNo === null ? null : ['label' => $t('version_label'), 'value' => (string) $document->versionNo],
            $document->identifier === null ? null : ['label' => $t('identifier'), 'value' => $document->identifier],
            $document->generatedAt === null ? null : ['label' => $t('generated_at'), 'value' => $document->generatedAt],
            ...$document->facts,
        ]));
        $this->grid($section, [], array_map(fn (array $fact): array => [$fact['label'], $fact['value']], $facts), [2800, 6500], boldFirstColumn: true);

        if ($document->nilReason !== null) {
            $section->addTitle($t('nil_report'), 1);
            $section->addText($t('nil_report_note'), ['italic' => true], ['spaceAfter' => 120]);
            $section->addText($document->nilReason);
        }
        if ($document->summary !== null && trim($document->summary) !== '') {
            $section->addTitle($t('summary'), 1);
            foreach (preg_split('/\R{2,}/u', trim($document->summary)) ?: [] as $paragraph) {
                $section->addText($paragraph, [], ['spaceAfter' => 120]);
            }
        }

        foreach ($document->sections as $index => $part) {
            $section->addTitle(($index + 1).'. '.$part['title'], 1);
            if (trim(strip_tags($part['html'])) !== '') {
                $this->html->write($section, $part['html']);
            } elseif (($part['guidance'] ?? null) !== null) {
                $section->addText($part['guidance'], ['italic' => true, 'color' => '777777'], ['spaceAfter' => 240]);
            } else {
                $section->addText('—', $muted);
            }
        }

        foreach ($document->tables as $table) {
            $section->addTitle($table['heading'], 1);
            $columns = max(1, count($table['header']));
            $this->grid($section, $table['header'], $table['rows'], array_fill(0, $columns, intdiv(9300, $columns)));
        }

        if ($document->annexes !== []) {
            $section->addTitle($t('annexes'), 1);
            foreach ($document->annexes as $number => $name) {
                $section->addText(($number + 1).'. '.$name, [], ['spaceAfter' => 40]);
            }
        }

        if ($document->signoff !== []) {
            $section->addTitle($t('signoff'), 1);
            $this->grid($section, [$t('signoff_role'), $t('signoff_name'), $t('signoff_time')],
                array_map(fn (array $row): array => [$row['role'], $row['name'], $row['at'] ?? '—'], $document->signoff), [2600, 3700, 3000]);
            $section->addText($t('signoff_note'), $muted, ['spaceBefore' => 120]);
        }

        IOFactory::createWriter($word, 'Word2007')->save($targetPath);
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @param  list<int>  $widths
     */
    private function grid(object $section, array $header, array $rows, array $widths, bool $boldFirstColumn = false): void
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '808080', 'cellMargin' => 80]);
        if ($header !== []) {
            $table->addRow(null, ['tblHeader' => true]);
            foreach ($header as $i => $text) {
                $table->addCell($widths[$i] ?? 2000, ['bgColor' => 'EDEDED'])->addText($text, ['bold' => true], ['spaceAfter' => 0]);
            }
        }
        foreach ($rows as $row) {
            $table->addRow();
            foreach (array_values($row) as $i => $text) {
                $table->addCell($widths[$i] ?? 2000, $boldFirstColumn && $i === 0 ? ['bgColor' => 'F5F5F5'] : [])
                    ->addText((string) $text, $boldFirstColumn && $i === 0 ? ['bold' => true] : [], ['spaceAfter' => 0]);
            }
        }
        $section->addTextBreak();
    }
}
