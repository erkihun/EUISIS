<?php

declare(strict_types=1);

use App\Support\RichText\RichText;
use App\Support\RichText\RichTextSanitizer;

/*
 * Editor output is never trusted: only the allowlist in
 * resources/js/richtext/policy.json survives a save. These cases are the
 * XSS gate for every formatted field in the system.
 */

function sanitizeRich(?string $html): ?string
{
    return app(RichTextSanitizer::class)->sanitize($html);
}

it('removes every script vector', function (string $payload): void {
    $clean = (string) sanitizeRich($payload.'<p>kept</p>');

    expect($clean)->toContain('kept')
        ->and(strtolower($clean))
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('vbscript:')
        ->not->toContain('data:')
        ->not->toContain('onerror')
        ->not->toContain('onload')
        ->not->toContain('onclick')
        ->not->toContain('onmouseover')
        ->not->toContain('<iframe')
        ->not->toContain('<object')
        ->not->toContain('<embed')
        ->not->toContain('<img')
        ->not->toContain('<svg')
        ->not->toContain('<style')
        ->not->toContain('<form')
        ->not->toContain('<input')
        ->not->toContain('<meta')
        ->not->toContain('<link')
        ->not->toContain('<base')
        ->not->toContain('expression(')
        ->not->toContain('url(');
})->with([
    'script tag' => ['<script>alert(1)</script>'],
    'script, mixed case' => ['<ScRiPt src="//evil.example/x.js"></sCrIpT>'],
    'img onerror' => ['<img src=x onerror="alert(1)">'],
    'base64 image' => ['<img src="data:image/png;base64,iVBORw0KGgo=">'],
    'svg onload' => ['<svg onload="alert(1)"><circle r="1"/></svg>'],
    'iframe' => ['<iframe src="https://evil.example"></iframe>'],
    'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
    'object' => ['<object data="evil.swf"></object>'],
    'embed' => ['<embed src="evil.swf">'],
    'javascript link' => ['<a href="javascript:alert(1)">x</a>'],
    'javascript link, entity encoded' => ['<a href="&#106;avascript:alert(1)">x</a>'],
    'javascript link, whitespace' => ['<a href=" javascript:alert(1)">x</a>'],
    'vbscript link' => ['<a href="vbscript:msgbox(1)">x</a>'],
    'data link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
    'event handler on allowed tag' => ['<p onclick="alert(1)" onmouseover="alert(2)">x</p>'],
    'style expression' => ['<p style="width: expression(alert(1))">x</p>'],
    'style url' => ['<p style="background: url(javascript:alert(1))">x</p>'],
    'style block' => ['<style>body{display:none}</style>'],
    'form and input' => ['<form action="https://evil.example"><input name="password"></form>'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
    'link stylesheet' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
    'base href' => ['<base href="https://evil.example/">'],
    'broken markup' => ['<p><scr<script>ipt>alert(1)</script></p>'],
    'comment trick' => ['<!--<img src=x onerror=alert(1)>-->'],
    'math href' => ['<math><a xlink:href="javascript:alert(1)">x</a></math>'],
]);

it('keeps the formatting the editor offers', function (): void {
    $html = '<h2>Title</h2><h3>Sub</h3><h4>Minor</h4>'
        .'<p style="text-align: center;"><strong>b</strong> <em>i</em> <u>u</u><br>next</p>'
        .'<ul><li>one</li></ul><ol><li>two</li></ol><blockquote><p>quote</p></blockquote>'
        .'<table><thead><tr><th colspan="2">h</th></tr></thead><tbody><tr><td rowspan="2">c</td><td>d</td></tr></tbody></table>'
        .'<p><a href="https://addis.gov.et" title="Addis">site</a> <a href="mailto:hr@addis.gov.et">mail</a> <a href="tel:+251111234567">call</a></p>';

    $clean = (string) sanitizeRich($html);

    foreach (['<h2>', '<h3>', '<h4>', '<strong>', '<em>', '<u>', '<br>', '<ul>', '<ol>', '<li>', '<blockquote>', '<thead>', '<tbody>',
        'colspan="2"', 'rowspan="2"', 'text-align:center', 'href="https://addis.gov.et"', 'title="Addis"', 'href="mailto:hr@addis.gov.et"', 'href="tel:+251111234567"'] as $kept) {
        expect($clean)->toContain($kept);
    }
});

it('keeps only text-align from inline styles', function (): void {
    expect(sanitizeRich('<p style="text-align: right; color: red; font-family: Comic Sans; font-size: 40px">x</p>'))
        ->toBe('<p style="text-align:right;">x</p>');
});

it('drops formatting the presets do not offer, keeping its text', function (): void {
    expect(sanitizeRich('<p><span style="color:red">red</span> <font face="x">font</font> <code>code</code></p>'))
        ->toBe('<p>red font code</p>');
});

it('folds legacy and pasted tags into allowed ones', function (): void {
    expect(sanitizeRich('<h1>A</h1><h5>B</h5><p><b>bold</b> <i>it</i></p>'))
        ->toBe('<h2>A</h2><h4>B</h4><p><strong>bold</strong> <em>it</em></p>');
});

it('adds rel noopener noreferrer to links opening a new tab', function (): void {
    $clean = (string) sanitizeRich('<p><a href="https://addis.gov.et" target="_blank">x</a></p>');

    expect($clean)->toContain('target="_blank"')
        ->toContain('noopener')
        ->toContain('noreferrer');
});

it('refuses link targets other than a new tab', function (): void {
    expect((string) sanitizeRich('<p><a href="https://a.et" target="_parent">x</a></p>'))->not->toContain('target');
});

it('stores empty content as null', function (?string $empty): void {
    expect(sanitizeRich($empty))->toBeNull();
})->with([
    'null' => [null],
    'blank' => ['   '],
    'empty paragraph' => ['<p></p>'],
    'editor placeholder' => ['<p><br></p>'],
    'nbsp' => ['<p>&nbsp;</p>'],
    'several empty blocks' => ['<p><br></p><h2> </h2><ul><li></li></ul>'],
    'only removed content' => ['<script>alert(1)</script><img src=x>'],
]);

it('is idempotent and never double-encodes', function (string $html): void {
    $once = sanitizeRich($html);

    expect(sanitizeRich($once))->toBe($once)
        ->and(sanitizeRich(sanitizeRich($once)))->toBe($once)
        ->and((string) $once)->not->toContain('&amp;amp;')
        ->not->toContain('&amp;lt;');
})->with([
    'entities' => ['<p>AT&amp;T &lt;tag&gt; "quoted" \'single\'</p>'],
    'amharic' => ['<p>የሥራ ዝርዝር፤ ሰላም ዓለም።</p>'],
    'new tab link' => ['<p><a href="https://a.et/?a=1&amp;b=2" target="_blank">x</a></p>'],
    'alignment and table' => ['<p style="text-align: justify;">j</p><table><tbody><tr><td colspan="2">c</td></tr></tbody></table>'],
    'plain text with ampersand' => ['Plan & budget < 5%'],
    'malformed' => ['<p>unclosed <strong>bold <em>both</p><li>stray'],
]);

it('preserves Amharic and English text exactly', function (): void {
    $text = '<h2>የሥራ መዘርዝር</h2><p>ዋና ተግባራት: ዕቅድ ማዘጋጀት፣ ሪፖርት ማቅረብ። Main duties: planning.</p>';

    expect(sanitizeRich($text))->toBe($text);
});

it('repairs malformed markup instead of failing', function (): void {
    expect(sanitizeRich('<p>unclosed <strong>bold'))->toBe('<p>unclosed <strong>bold</strong></p>')
        ->and(sanitizeRich('</p></div>text<p>'))->toContain('text');
});

it('turns a plain-text value into escaped paragraphs', function (): void {
    expect(sanitizeRich("First line\nsecond line\n\nNew paragraph with 5 < 6 & 7"))
        ->toBe('<p>First line<br>second line</p><p>New paragraph with 5 &lt; 6 &amp; 7</p>');
});

it('round-trips its own output through the plain-text helpers', function (): void {
    $clean = sanitizeRich('<h2>Duties</h2><ul><li>Plan</li><li>Report</li></ul><p>AT&amp;T</p>');

    expect(RichText::plainText($clean))->toBe("Duties\nPlan\nReport\nAT&T");
});
