<?php

declare(strict_types=1);

use CafeteriaSystem\Http\Middleware\SetLocale;
use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaUser;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->provider = CafeteriaProvider::query()->create([
        'code' => 'CAF-L10N',
        'name' => 'Locale Cafe',
        'status' => 'active',
    ]);

    $this->cafeteria = Cafeteria::query()->create([
        'provider_id' => $this->provider->id,
        'name' => 'Locale Point',
        'code' => 'L10N-TP',
        'status' => 'active',
    ]);

    $this->operator = CafeteriaUser::query()->create([
        'provider_id' => $this->provider->id,
        'cafeteria_id' => $this->cafeteria->id,
        'name' => 'Locale Operator',
        'email' => 'locale@test.local',
        'password' => 'password',
        'role' => 'operator',
        'status' => 'active',
    ]);
});

/** Flatten a nested translation array to dotted key paths. */
function flattenKeys(array $tree, string $prefix = ''): array
{
    $keys = [];

    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $path));

            continue;
        }

        $keys[] = $path;
    }

    return $keys;
}

/** Parse a TypeScript dictionary into dotted key paths. */
function tsDictionaryKeys(string $file): array
{
    $source = file_get_contents(base_path($file));
    $keys = [];
    $stack = [];

    foreach (explode("\n", $source) as $line) {
        $trimmed = trim($line);

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*\{$/', $trimmed, $m) === 1) {
            $stack[] = $m[1];

            continue;
        }

        if (str_starts_with($trimmed, '}')) {
            array_pop($stack);

            continue;
        }

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*[\'"]/', $trimmed, $m) === 1) {
            $keys[] = implode('.', [...$stack, $m[1]]);
        }
    }

    return $keys;
}

it('defines the same keys in both frontend dictionaries', function (): void {
    $en = tsDictionaryKeys('resources/js/i18n/en.ts');
    $am = tsDictionaryKeys('resources/js/i18n/am.ts');

    expect($en)->not->toBeEmpty();

    sort($en);
    sort($am);

    // A key present in one file only renders as its raw path (`scan.qrToken`)
    // in the other language, which reaches the operator as visible breakage.
    expect(array_values(array_diff($en, $am)))->toBe([])
        ->and(array_values(array_diff($am, $en)))->toBe([]);
});

it('defines the same keys in both backend lang files', function (): void {
    foreach (['cafeteria', 'auth'] as $file) {
        $en = flattenKeys(require base_path("lang/en/{$file}.php"));
        $am = flattenKeys(require base_path("lang/am/{$file}.php"));

        sort($en);
        sort($am);

        expect($am)->toBe($en, "lang/{$file}.php keys differ between locales");
    }
});

it('translates backend messages into amharic', function (): void {
    app()->setLocale('am');

    expect(__('auth.failed'))->not->toBe('auth.failed')
        ->and(__('cafeteria.duplicateService'))->not->toBe('cafeteria.duplicateService');
});

it('defaults to english', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('switches the interface language and remembers it', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->post('/locale', ['locale' => 'am'])
        ->assertRedirect();

    // A second, independent request must still be Amharic, or the operator
    // would have to re-pick the language on every page.
    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'am'));
});

it('ignores an unsupported locale', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->post('/locale', ['locale' => 'fr'])
        ->assertRedirect();

    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('falls back to english when the session holds a bad locale', function (): void {
    // Guards against a stale or tampered session value rendering raw keys.
    $this->withSession(['locale' => 'zz'])
        ->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('lets a guest switch language on the login page', function (): void {
    // An operator who cannot read English must be able to translate the login
    // form before they have any way to authenticate.
    $this->post('/locale', ['locale' => 'am'])->assertRedirect();

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'am'));
});

it('shares the supported locales with every page', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertInertia(fn (Assert $page) => $page->where('supported_locales', SetLocale::SUPPORTED));
});
