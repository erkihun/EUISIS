<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Application\Data\ReportDocument;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use Modules\Reporting\Application\Ports\PdfConverter;
use Modules\Reporting\Application\Ports\UploadInspector;
use Modules\Reporting\Application\Support\UploadRejected;
use Modules\Reporting\Infrastructure\Documents\ReportDocumentFactory;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\ReportingScenario;

/*
 * Word output (real, editable .docx; Amharic and tables preserved) and the
 * inspection of uploaded files.
 */

/** @return array<string, string> part name => contents */
function docxParts(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $parts;
}

/** A zip with the given entries, saved under a .docx name. */
function fakeDocx(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'rpt-fake').'.docx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

function minimalDocx(array $extra = [], ?string $contentTypes = null): string
{
    return fakeDocx([
        '[Content_Types].xml' => $contentTypes ?? '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Report</w:t></w:r></w:p></w:body></w:document>',
        ...$extra,
    ]);
}

it('writes an editable Word file that preserves Amharic text, lists and tables', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rpt-out').'.docx';
    app(DocumentRenderer::class)->renderWord(new ReportDocument(
        language: 'am', label: 'approved', title: 'ወርሃዊ የሥራ አፈጻጸም ሪፖርት', institutionName: 'ንግድ ቢሮ', receivingName: 'ፐብሊክ ሰርቪስ ቢሮ',
        periodLabel: 'ጥቅምት 2019 ዓ.ም', referenceNo: 'RPT-20261110-ABCD1234', versionNo: 2, identifier: '019a-test',
        sections: [
            ['title' => 'አጠቃላይ ማጠቃለያ', 'html' => '<p>በወሩ <strong>12 ተግባራት</strong> ተከናውነዋል። R&amp;D &lt;test&gt;</p><ul><li>የመጀመሪያ ነጥብ</li><li>ሁለተኛ ነጥብ<ol><li>ንዑስ ነጥብ</li></ol></li></ul>'],
            ['title' => 'የዕቅድና የክንውን ንጽጽር', 'html' => '<table><tr><th>ተግባር</th><th>ዕቅድ</th><th>ክንውን</th></tr><tr><td>ሥልጠና</td><td>10</td><td>8</td></tr><tr><td colspan="2">ድምር</td><td>8</td></tr></table><script>alert(1)</script><img src="https://attacker.example/pixel.png">'],
        ],
        summary: 'አጭር ማጠቃለያ', annexes: ['አባሪ-1.pdf'],
        signoff: [['role' => 'ያዘጋጀው', 'name' => 'አበበ ከበደ', 'at' => 'ኅዳር 1 ቀን 2019 ዓ.ም 09:00 (EAT)'], ['role' => 'ያጸደቀው', 'name' => 'ሰላም ተስፋዬ', 'at' => null]],
        generatedAt: 'ኅዳር 11 ቀን 2019 ዓ.ም',
    ), $path);

    $parts = docxParts($path);
    $body = $parts['word/document.xml'];
    $all = implode("\n", $parts);

    // A real WordprocessingML package, readable back by the library.
    expect(array_keys($parts))->toContain('[Content_Types].xml', 'word/document.xml', 'word/styles.xml')
        ->and(IOFactory::load($path)->getSections())->toHaveCount(1)
        // Amharic is stored as text (UTF-8), not as images or entities.
        ->and($body)->toContain('ወርሃዊ የሥራ አፈጻጸም ሪፖርት', 'ንግድ ቢሮ', 'ጥቅምት 2019 ዓ.ም', '12 ተግባራት', 'የመጀመሪያ ነጥብ', 'ንዑስ ነጥብ', 'አበበ ከበደ', 'አባሪ-1.pdf')
        ->and(mb_check_encoding($body, 'UTF-8'))->toBeTrue()
        // Tables are Word tables with the cells' text, the merged cell kept.
        ->and(substr_count($body, '<w:tbl>'))->toBeGreaterThanOrEqual(3)
        ->and($body)->toContain('ሥልጠና', 'ክንውን', 'w:gridSpan')
        // Lists are numbered/bulleted paragraphs.
        ->and($body)->toContain('<w:numPr>')
        // Escaped, never executed or fetched.
        ->and($body)->toContain('R&amp;D &lt;test&gt;')
        ->and($all)->not->toContain('alert(1)')->not->toContain('attacker.example')->not->toContain('TargetMode="External"')
        // Reference, version, label and page numbers in the footer; the institution in the header.
        ->and($all)->toContain('RPT-20261110-ABCD1234', 'ዕትም 2', 'የጸደቀ', 'PAGE', 'NUMPAGES')
        ->and($all)->toContain('w:ascii="Nyala"')
        // The sign-off block is text and says it is not a signature.
        ->and($body)->toContain('ይህ ክፍል ፊርማ አይደለም');

    // The same content in English uses the English wording.
    $english = tempnam(sys_get_temp_dir(), 'rpt-out').'.docx';
    app(DocumentRenderer::class)->renderWord(new ReportDocument(language: 'en', label: 'draft', title: 'Monthly report', institutionName: 'Trade Bureau', receivingName: null, periodLabel: 'Tikimt 2019 EC', referenceNo: null, versionNo: 1, identifier: null, sections: []), $english);
    expect(implode("\n", docxParts($english)))->toContain('DRAFT — not submitted', 'Version 1', 'Trade Bureau');
});

it('renders a submitted version from its snapshot with the recorded names and times', function (): void {
    Storage::fake('local');
    ReportingScenario::clock('2026-11-10 09:00');
    $s = ReportingScenario::build()->configure();
    $obligation = $s->obligation($s->publish(), 'A');
    $officer = $s->officer('A');
    $submission = $s->writeAndSubmit($obligation, $officer);
    $s->approve($submission);

    $document = app(ReportDocumentFactory::class)->forSubmission($submission->fresh(), 'approved', 'am');
    $roles = array_column($document->signoff, 'role');

    expect($document->institutionName)->toBe('ንግድ ቢሮ')
        ->and($document->periodLabel)->toBe('ጥቅምት 2019 ዓ.ም')
        ->and($document->referenceNo)->toBe($submission->reference_no)
        ->and($document->sections)->toHaveCount(3)
        ->and($document->signoff)->toHaveCount(3)
        ->and($document->signoff[0]['name'])->toBe($officer->name)
        ->and($document->signoff[0]['at'])->toContain('ኅዳር 1 ቀን 2019 ዓ.ም 09:00 (EAT)')
        ->and($roles[2])->toContain('ያጸደቀው');

    // The stored file is the approved one, and no PDF is attempted when no converter is configured.
    expect(app(PdfConverter::class)->isAvailable())->toBeFalse()
        ->and(Artifact::query()->where('owner_id', $submission->id)->where('format', 'pdf')->count())->toBe(0)
        ->and(Artifact::query()->where('owner_id', $submission->id)->where('format', 'docx')->where('status', 'completed')->count())->toBe(2);
});

it('accepts a real Word document, an Excel annex and a PDF', function (): void {
    $word = new PhpWord;
    $word->addSection()->addLink('https://example.gov.et', 'A plain hyperlink');
    $docx = tempnam(sys_get_temp_dir(), 'rpt-ok').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($docx);
    $pdf = tempnam(sys_get_temp_dir(), 'rpt-ok').'.pdf';
    file_put_contents($pdf, "%PDF-1.4\n%test\n");
    $inspector = app(UploadInspector::class);

    // A plain hyperlink is an external relationship and is allowed.
    expect($inspector->inspect($docx, 'ሪፖርት.docx', 'report_document'))->toBe('not_scanned')
        ->and($inspector->inspect($pdf, 'annex.pdf', 'annex'))->toBe('not_scanned');
});

it('refuses uploads that are not what they claim, or that are unsafe', function (string $reason, Closure $file, string $name, string $kind): void {
    expect(fn () => app(UploadInspector::class)->inspect($file(), $name, $kind))
        ->toThrow(fn (UploadRejected $e) => expect($e->reason)->toBe($reason));
})->with([
    'a text file renamed .docx' => ['not_docx', function (): string {
        $path = tempnam(sys_get_temp_dir(), 'rpt-bad');
        file_put_contents($path, 'This is not a Word document.');

        return $path;
    }, 'report.docx', 'report_document'],
    'an old .doc' => ['not_docx', fn (): string => minimalDocx(), 'report.doc', 'report_document'],
    'a macro-enabled file (.docm)' => ['not_docx', fn (): string => minimalDocx(), 'report.docm', 'report_document'],
    'a zip that is not a Word package' => ['not_docx', fn (): string => fakeDocx(['readme.txt' => 'hello']), 'report.docx', 'report_document'],
    'macros inside a .docx' => ['macros_not_allowed', fn (): string => minimalDocx(['word/vbaProject.bin' => 'macro bytes']), 'report.docx', 'report_document'],
    'a macro-enabled content type' => ['macros_not_allowed', fn (): string => minimalDocx([], '<?xml version="1.0"?><Types><Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>'), 'report.docx', 'report_document'],
    'an embedded object' => ['unsafe_content', fn (): string => minimalDocx(['word/embeddings/oleObject1.bin' => 'ole']), 'report.docx', 'report_document'],
    'a remote template' => ['external_links', fn (): string => minimalDocx(['word/_rels/settings.xml.rels' => '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="https://attacker.example/t.dotm" TargetMode="External"/></Relationships>']), 'report.docx', 'report_document'],
    'a linked remote image' => ['external_links', fn (): string => minimalDocx(['word/_rels/document.xml.rels' => '<Relationships><Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://attacker.example/p.png" TargetMode="External"/></Relationships>']), 'report.docx', 'report_document'],
    'XML that declares entities' => ['unsafe_content', fn (): string => minimalDocx(['word/settings.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;">]><w:settings>&b;</w:settings>']), 'report.docx', 'report_document'],
    'an archive bomb' => ['archive_too_large', fn (): string => minimalDocx(['word/media/filler.xml' => str_repeat('0', 30 * 1024 * 1024)]), 'report.docx', 'report_document'],
    'a path leaving the archive' => ['unsafe_content', fn (): string => minimalDocx(['../../evil.xml' => '<x/>']), 'report.docx', 'report_document'],
    'an executable as an annex' => ['type_not_allowed', fn (): string => minimalDocx(), 'tool.exe', 'annex'],
    'a fake PDF' => ['type_not_allowed', function (): string {
        $path = tempnam(sys_get_temp_dir(), 'rpt-bad');
        file_put_contents($path, '<html>not a pdf</html>');

        return $path;
    }, 'annex.pdf', 'annex'],
    'an empty file' => ['too_large', function (): string {
        return (string) tempnam(sys_get_temp_dir(), 'rpt-empty');
    }, 'report.docx', 'report_document'],
]);

it('refuses a file the scanner flags and one larger than the configured limit', function (): void {
    $docx = minimalDocx();

    // The scanner convention: exit code 1 means infected, 0 means clean.
    config(['reporting.scanner.command' => [PHP_BINARY, '-r', 'exit(1);']]);
    expect(fn () => app(UploadInspector::class)->inspect($docx, 'report.docx', 'report_document'))->toThrow(fn (UploadRejected $e) => expect($e->reason)->toBe('malware_detected'));

    config(['reporting.scanner.command' => [PHP_BINARY, '-r', 'exit(0);']]);
    expect(app(UploadInspector::class)->inspect($docx, 'report.docx', 'report_document'))->toBe('clean');

    // A scanner that cannot run: kept as "not scanned", or refused when scanning is mandatory.
    config(['reporting.scanner.command' => [PHP_BINARY, '-r', 'exit(2);']]);
    expect(app(UploadInspector::class)->inspect($docx, 'report.docx', 'report_document'))->toBe('not_scanned');
    config(['reporting.scanner.required' => true]);
    expect(fn () => app(UploadInspector::class)->inspect($docx, 'report.docx', 'report_document'))->toThrow(UploadRejected::class);

    config(['reporting.scanner.command' => [], 'reporting.max_upload_kb' => 1]);
    app()->forgetScopedInstances();
    $large = minimalDocx(['word/media/image1.xml' => random_bytes(4096)]);
    expect(fn () => app(UploadInspector::class)->inspect($large, 'report.docx', 'report_document'))->toThrow(fn (UploadRejected $e) => expect($e->reason)->toBe('too_large'));
});
