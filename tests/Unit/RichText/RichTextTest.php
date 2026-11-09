<?php

declare(strict_types=1);

use App\Support\RichText\RichText;

/*
 * What a reader sees in a formatted field: drives "required", length
 * limits, CSV/Excel cells and search text.
 */

it('treats markup without words as empty', function (?string $value): void {
    expect(RichText::isEmpty($value))->toBeTrue()
        ->and(RichText::plainText($value))->toBe('');
})->with([
    [null], [''], ['   '], ['<p></p>'], ['<p><br></p>'], ['<p>&nbsp;</p>'], ["<p>\u{00A0}</p>"],
    ['<p><br></p><p><br></p>'], ['<h2> </h2>'], ['<ul><li></li></ul>'],
    ['<table><tbody><tr><td></td></tr></tbody></table>'], ['<p><strong></strong></p>'],
]);

it('reads markup as lines of plain text', function (): void {
    expect(RichText::plainText('<h2>Title</h2><p>One<br>two &amp; three</p><ul><li>a</li><li>b</li></ul>'))
        ->toBe("Title\nOne\ntwo & three\na\nb");
});

it('reads table rows as lines, cells side by side', function (): void {
    expect(RichText::plainText('<table><tr><th>Name</th><th>Role</th></tr><tr><td>Abebe</td><td>Officer</td></tr></table>'))
        ->toBe("Name Role\nAbebe Officer");
});

it('never returns script or style bodies as text', function (): void {
    expect(RichText::plainText('<p>ok</p><script>alert(1)</script><style>p{}</style>'))->toBe('ok');
});

it('leaves legacy plain text as it was', function (): void {
    expect(RichText::isHtml("Line one\nLine two"))->toBeFalse()
        ->and(RichText::isHtml('5 < 6 and 7 > 3'))->toBeFalse()
        ->and(RichText::plainText("Line one\r\nLine two"))->toBe("Line one\nLine two");
});

it('counts Amharic by characters, not bytes, and ignores markup', function (): void {
    $html = '<p><strong>ሰላም</strong> ዓለም</p>';

    expect(RichText::plainLength($html))->toBe(7)
        ->and(strlen($html))->toBeGreaterThan(30);
});

it('escapes plain text into paragraphs', function (): void {
    expect(RichText::fromPlainText("a <b>\nc\n\n\n d & e "))->toBe('<p>a &lt;b&gt;<br>c</p><p>d &amp; e</p>');
});
