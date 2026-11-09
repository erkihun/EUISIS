<?php

declare(strict_types=1);

use App\Http\Middleware\SetClientLocale;
use App\Services\IdCards\IdCardSvgRenderer;

/*
 * The locale reaches <html lang>, which is what switches the typography
 * tokens (resources/css/app.css). See docs/ui-typography.md.
 */

it('renders English pages with lang="en"', function (): void {
    $this->withUnencryptedCookie(SetClientLocale::COOKIE, 'en')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('font-sans', false);
});

it('renders Amharic pages with lang="am"', function (): void {
    $this->withUnencryptedCookie(SetClientLocale::COOKIE, 'am')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="am">', false);

    $this->withHeader('X-Locale', 'am')
        ->get(route('login'))
        ->assertSee('<html lang="am">', false);
});

it('ignores an unsupported locale rather than emitting it as the page language', function (): void {
    $this->withUnencryptedCookie(SetClientLocale::COOKIE, 'fr')
        ->get(route('login'))
        ->assertOk()
        ->assertDontSee('<html lang="fr">', false);
});

it('serves fonts only from the application itself', function (): void {
    $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

    expect($csp)->toContain("font-src 'self' data:")
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.gstatic.com');
});

it('keeps the ID card SVG on the configured Inter / Abyssinica SIL stack', function (): void {
    $stack = (string) config('typography.id_card.stack');
    $renderer = app(IdCardSvgRenderer::class);

    expect($stack)->toStartWith("'Inter','Abyssinica SIL'")
        ->and((new ReflectionProperty($renderer, 'font'))->getValue($renderer))->toBe($stack)
        // The built-in default (used without app config) must not drift from config.
        ->and((new ReflectionClassConstant(IdCardSvgRenderer::class, 'DEFAULT_FONT'))->getValue())->toBe($stack);
});
