<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Data;

/**
 * Everything a renderer needs to produce a report as a Word file, taken from
 * an immutable snapshot. Names and times are the actual ones recorded by the
 * workflow; the renderer prints them as text and never as a signature.
 */
final readonly class ReportDocument
{
    /**
     * @param  string  $language  am or en
     * @param  string  $label  draft, submitted, approved, template or consolidated: printed on the document
     * @param  list<array{title: string, html: string, guidance?: ?string}>  $sections
     * @param  list<string>  $annexes  file names of the annexes kept with the version
     * @param  list<array{role: string, name: string, at: ?string}>  $signoff  prepared / reviewed / approved, in order
     * @param  list<array{label: string, value: string}>  $facts  further lines of the title block (indicators, sources)
     * @param  list<array{heading: string, rows: list<list<string>>, header: list<string>}>  $tables  generated tables (consolidation)
     */
    public function __construct(
        public string $language,
        public string $label,
        public string $title,
        public string $institutionName,
        public ?string $receivingName,
        public string $periodLabel,
        public ?string $referenceNo,
        public ?int $versionNo,
        public ?string $identifier,
        public array $sections,
        public ?string $summary = null,
        public ?string $nilReason = null,
        public array $annexes = [],
        public array $signoff = [],
        public array $facts = [],
        public array $tables = [],
        public ?string $logoPath = null,
        public ?string $generatedAt = null,
    ) {}
}
