<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Typography\DocumentFonts;
use Barryvdh\DomPDF\Facade\Pdf;

/*
 * PDFs must embed an Ethiopic-capable font from resources/fonts — never rely
 * on a server- or viewer-installed font, and never print Amharic as boxes.
 */

/** Every view rendered through dompdf, found from the call sites. */
function pdfTemplateViews(): array
{
    $views = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php'
            && preg_match_all("/Pdf::loadView\(\s*'([\w.\-]+)'/", (string) file_get_contents($file->getPathname()), $matches) > 0) {
            array_push($views, ...$matches[1]);
        }
    }

    return array_values(array_unique($views));
}

/**
 * Glyph ids a PDF's embedded font assigns to code points (dompdf writes one
 * CIDToGIDMap per font; CID = Unicode code point, 2 bytes per entry).
 *
 * @param  list<int>  $codePoints
 * @return array<int, int> code point => largest glyph id found in any embedded font
 */
function pdfGlyphIds(string $pdf, array $codePoints): array
{
    $ids = array_fill_keys($codePoints, 0);
    preg_match_all('#/CIDToGIDMap\s+(\d+)\s+0\s+R#', $pdf, $refs);

    foreach (array_unique($refs[1]) as $object) {
        if (preg_match("#\n{$object} 0 obj\s*<<(.*?)>>\s*stream\r?\n#s", $pdf, $header, PREG_OFFSET_CAPTURE) !== 1
            || preg_match('#/Length (\d+)#', $header[1][0], $length) !== 1) {
            continue;
        }

        $map = (string) @gzuncompress(substr($pdf, $header[0][1] + strlen($header[0][0]), (int) $length[1]));

        foreach ($codePoints as $cp) {
            if (strlen($map) > 2 * $cp + 1) {
                $ids[$cp] = max($ids[$cp], (ord($map[2 * $cp]) << 8) | ord($map[2 * $cp + 1]));
            }
        }
    }

    return $ids;
}

it('includes the shared typography partial in every PDF template', function (): void {
    $views = pdfTemplateViews();

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        $source = (string) file_get_contents(resource_path('views/'.str_replace('.', '/', $view).'.blade.php'));

        expect($source)
            ->toContain("@include('pdf.partials.typography'")
            // No template names its own body font or reads fonts from storage.
            ->not->toMatch('/body\s*\{[^}]*font-family/')
            ->not->toContain("storage_path('fonts");
    }
});

it('points every document face at a bundled font file', function (): void {
    $fonts = app(DocumentFonts::class);

    foreach ($fonts->variants() as $variant) {
        $css = $fonts->css($variant);
        preg_match_all('/src: url\("([^"]+)"\)/', $css, $paths);

        expect($paths[1])->toHaveCount(4)
            ->and($css)->toContain('html, body { font-family: ');

        foreach ($paths[1] as $path) {
            expect(is_file($path))->toBeTrue()
                ->and(str_starts_with($path, str_replace('\\', '/', resource_path('fonts'))))->toBeTrue();
        }
    }

    expect($fonts->family('report'))->toStartWith("'Noto Sans Ethiopic'")
        ->and($fonts->family('formal'))->toStartWith("'Abyssinica SIL'");
});

it('refuses fonts outside the bundle and unknown variants', function (): void {
    $fonts = app(DocumentFonts::class);

    expect(fn () => $fonts->path('../../.env'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $fonts->path('missing.ttf'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $fonts->css('handwriting'))->toThrow(InvalidArgumentException::class);
});

it('embeds real Ethiopic and Latin glyphs in report and formal PDFs', function (string $variant, string $embedded): void {
    $fonts = app(DocumentFonts::class);
    $pdf = Pdf::loadView('pdf.typography-test', ['variant' => $variant, 'family' => $fonts->family($variant)])->output();

    // ሰ ራ ተ ኛ ። and A 7 — a glyph id of 0 is .notdef, the "box".
    $glyphs = pdfGlyphIds($pdf, [0x1230, 0x122B, 0x1270, 0x129B, 0x1362, 0x41, 0x37]);

    expect($pdf)->toMatch("#/BaseFont\s*/[A-Z]{6}\+{$embedded}#")
        ->not->toContain('/BaseFont /Times-Roman')
        ->and(array_filter($glyphs, fn (int $id): bool => $id === 0))->toBe([]);
})->with([
    'report' => ['report', 'NotoSansEthiopic-Regular'],
    'formal' => ['formal', 'AbyssinicaSIL-Regular'],
]);

it('serves the PDF check sheet to signed-in users outside production only', function (): void {
    $user = User::factory()->create();

    $this->get(route('dev.typography-test'))->assertRedirect(route('login'));

    $this->actingAs($user)
        ->get(route('dev.typography-test', ['variant' => 'formal']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($user)
        ->get(route('dev.typography-test', ['format' => 'html']))
        ->assertOk()
        ->assertSee('የሰራተኞች አስተዳደር');

    app()->detectEnvironment(fn (): string => 'production');

    $this->actingAs($user)->get(route('dev.typography-test'))->assertNotFound();
});
