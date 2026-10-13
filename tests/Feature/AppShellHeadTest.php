<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The HTML shell stays light: no inlined route list, and the first-paint assets are announced early. */
class AppShellHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_list_is_not_inlined_into_the_page(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('const Ziggy', false);
        $response->assertDontSee('ziggy-routes-json', false);
    }

    public function test_first_paint_fonts_and_the_active_language_are_preloaded(): void
    {
        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Needs built assets (npm run build).');
        }

        // The locale cookie is a plain display preference (excluded from cookie encryption).
        $response = $this->withUnencryptedCookie('euisis_locale', 'am')->get('/login');

        $response->assertOk();
        $response->assertSee('rel="preload" as="font" type="font/woff2" crossorigin', false);
        $response->assertSee('inter-latin-400-normal', false);
        $response->assertSee('abyssinica-sil-ethiopic-400-normal', false);
        $response->assertSee('rel="modulepreload"', false);
    }

    public function test_the_ethiopic_font_is_not_preloaded_for_an_english_visitor(): void
    {
        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Needs built assets (npm run build).');
        }

        $response = $this->withUnencryptedCookie('euisis_locale', 'en')->get('/login');

        $response->assertOk();
        $response->assertSee('inter-latin-400-normal', false);
        $response->assertDontSee('abyssinica-sil-ethiopic-400-normal', false);
    }
}
