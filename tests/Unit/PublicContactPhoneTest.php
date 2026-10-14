<?php

declare(strict_types=1);

use App\Support\Phone\PublicContactPhone;

/*
 * An institution's public number becomes a tel: link on the public QR pages,
 * so only shapes that are unambiguous phone numbers may pass — never a
 * scheme, an extension or anything a browser could run.
 */

it('normalises Ethiopian numbers to +251 and formats them for reading', function (string $input, string $dial, string $display): void {
    $phone = PublicContactPhone::parse($input);

    expect($phone)->not->toBeNull()
        ->and($phone->dial)->toBe($dial)
        ->and($phone->display)->toBe($display)
        ->and($phone->telUri())->toBe('tel:'.$dial);
})->with([
    'fixed line, local' => ['011 123 4567', '+251111234567', '011 123 4567'],
    'fixed line, no spaces' => ['0111234567', '+251111234567', '011 123 4567'],
    'fixed line, brackets and hyphen' => ['(011) 123-4567', '+251111234567', '011 123 4567'],
    'fixed line, +251' => ['+251 11 123 4567', '+251111234567', '011 123 4567'],
    'fixed line, 251 without +' => ['251111234567', '+251111234567', '011 123 4567'],
    'regional fixed line' => ['0582 20 1234', '+251582201234', '058 220 1234'],
    'mobile, local' => ['0911223344', '+251911223344', '0911 223 344'],
    'mobile, +251 with hyphens' => ['+251-911-22-33-44', '+251911223344', '0911 223 344'],
    'mobile, 07' => ['0711 223 344', '+251711223344', '0711 223 344'],
    'already stored' => ['+251111234567', '+251111234567', '011 123 4567'],
]);

it('keeps short codes and international numbers as dialable values', function (string $input, string $dial): void {
    expect(PublicContactPhone::parse($input)?->dial)->toBe($dial);
})->with([
    'four-digit hotline' => ['8044', '8044'],
    'three-digit hotline' => ['991', '991'],
    'international' => ['+44 20 7946 0958', '+442079460958'],
]);

it('refuses anything that is not plainly a phone number', function (?string $input): void {
    expect(PublicContactPhone::parse($input))->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
    'javascript scheme' => ['javascript:alert(1)'],
    'tel scheme typed in' => ['tel:+251111234567'],
    'data scheme' => ['data:text/html,<script>alert(1)</script>'],
    'web link' => ['http://example.test'],
    'markup after a number' => ['+251 11 123 4567<script>'],
    'pause or extension' => ['0111234567,1'],
    'semicolon extension' => ['0111234567;ext=2'],
    'written extension' => ['011 123 4567 ext 2'],
    'hash' => ['0911223344#'],
    'line break' => ["011\n1234567"],
    'percent-encoded plus' => ['%2B251111234567'],
    'nine digits without the leading 0' => ['911223344'],
    'too short local' => ['0111'],
    'two digits' => ['12'],
    'short code with a leading 0' => ['0044'],
    'Ethiopian number of the wrong length' => ['+251 11 123 456'],
    'national number starting with 0' => ['+2510111234567'],
    'plus then zero' => ['+0123456789'],
    'double plus' => ['++251111234567'],
    'plus in the middle' => ['0111+234567'],
    'too long' => [str_repeat('1', 33)],
    'letters' => ['CALL-NOW'],
]);

it('only ever produces a tel: link of a plus sign and digits', function (): void {
    foreach (['011 123 4567', '0911223344', '8044', '+44 20 7946 0958'] as $input) {
        expect(PublicContactPhone::parse($input)->telUri())->toMatch('/^tel:\+?[0-9]+$/D');
    }
});
