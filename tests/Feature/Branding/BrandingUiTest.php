<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Rebrand brief §37 (8–13) and §42: the sign-in, navigation, dashboard and
 * public QR pages carry the new brand through the shared `branding` prop,
 * and no component, translation file or language file still hard-codes the
 * former product names. The browser pass in docs/branding.md confirms the
 * rendered result in both languages.
 */

const LEGACY_BRAND_PATTERNS = ['EUISIS', 'Employee Unified ID', 'Unified ID & Service', 'Unified Employee ID', 'Service Integration System', 'Employee Unified Identity', 'ኢዩሲሲስ'];

function legacyBrandHits(string $directory, array $extensions, bool $allowComments): array
{
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! in_array($file->getExtension(), $extensions, true)) {
            continue;
        }
        foreach (file($file->getPathname()) as $number => $line) {
            foreach (LEGACY_BRAND_PATTERNS as $pattern) {
                if (! str_contains($line, $pattern)) {
                    continue;
                }
                $trimmed = ltrim($line);
                $isComment = str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '{/*');
                if ($allowComments && $isComment) {
                    continue;
                }
                $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    return array_values(array_unique($hits));
}

beforeEach(function (): void {
    Permission::findOrCreate('dashboard.view', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('has no former product name left in components, translations or language files', function (): void {
    expect(legacyBrandHits(resource_path('js/i18n'), ['ts'], false))->toBe([])
        ->and(legacyBrandHits(lang_path(), ['php'], false))->toBe([])
        ->and(legacyBrandHits(resource_path('js/Pages'), ['tsx'], true))->toBe([])
        ->and(legacyBrandHits(resource_path('js/Components'), ['tsx', 'ts'], true))->toBe([])
        ->and(legacyBrandHits(resource_path('js/Layouts'), ['tsx'], true))->toBe([])
        ->and(legacyBrandHits(resource_path('views'), ['php'], false))->toBe([]);
});

it('renders the sign-in, password and MFA pages with the new brand and nothing of the old one', function (): void {
    foreach (['/login', '/forgot-password'] as $path) {
        $this->get($path)->assertOk()
            ->assertSee('<title data-inertia>Addis One</title>', false)
            ->assertDontSee('EUISIS')
            ->assertInertia(fn (Assert $page) => $page->where('branding.brandName', 'Addis One')->where('branding.shortName', 'AA-IEMSS'));
    }
});

it('carries the brand into the dashboard shell for the sidebar and mobile navigation', function (): void {
    $this->actingAs($this->admin)->get('/dashboard')->assertOk()
        ->assertDontSee('EUISIS')
        ->assertInertia(fn (Assert $page) => $page
            ->where('branding.brandName', 'Addis One')
            ->where('branding.shortName', 'AA-IEMSS')
            ->where('settings', fn ($settings) => $settings['app.name'] === 'Addis One' && $settings['app.short_name'] === 'AA-IEMSS'));
});

it('brands the public QR pages while the scanned target stays the primary identity', function (): void {
    $a = PublicFeedbackScenario::employeeTarget('BRU');
    $orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($a->organization, $this->admin)->public_token;

    foreach (['/service-feedback/'.$a->token->token, '/feedback/'.$orgToken, '/verify'] as $path) {
        $this->get($path)->assertOk()
            ->assertDontSee('EUISIS')
            ->assertInertia(fn (Assert $page) => $page->where('branding.brandName', 'Addis One'));
    }
});
