<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\PublicPageMeta;
use App\Services\PublicSite\PublicSiteContent;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The public home page explains what EUISIS is. Its copy lives in
 * resources/js/i18n/{en,am}/home.ts and renders in the browser, so content
 * completeness is checked against those files and Welcome.tsx; the HTTP
 * response is checked for guest access, metadata and the absence of data.
 */

/** @return array<string, string> key => value of one locale's home copy */
function homeCopy(string $locale): array
{
    $source = (string) file_get_contents(resource_path("js/i18n/{$locale}/home.ts"));
    preg_match_all("/^\s{4}(\w+):\s*'((?:[^'\\\\]|\\\\.)*)',?\s*$/mu", $source, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $m) => [$m[1] => stripslashes($m[2])])->all();
}

function welcomeSource(): string
{
    return (string) file_get_contents(resource_path('js/Pages/Welcome.tsx'));
}

function supportSource(): string
{
    return (string) file_get_contents(resource_path('js/Pages/Public/Support.tsx'));
}

/** Every key the home page must explain, grouped by the topic it covers. */
const HOME_TOPICS = [
    'core description' => ['heroSubtitle', 'manageSectionTitle', 'manageSectionSubtitle', 'faq1A', 'faq2A'],
    'organization structure' => ['manageOrgTitle', 'manageOrgDesc'],
    'positions' => ['managePositionsTitle', 'managePositionsDesc'],
    'employee registry' => ['manageRegistryTitle', 'manageRegistryDesc', 'faq5A'],
    'unified ID' => ['serviceCardTitle', 'serviceCardDesc'],
    'employee services' => ['servicesSectionSubtitle', 'serviceCafeteriaDesc', 'serviceTransportDesc', 'servicesNote', 'faq3A', 'faq4A'],
    'performance' => ['workDailyDesc', 'workPerformanceDesc', 'workCompetencyDesc'],
    'feedback and grievance' => ['workFeedbackDesc', 'workGrievanceDesc'],
    'integration' => ['serviceIntegrationDesc', 'faq6A'],
    'security and privacy' => ['trust1Desc', 'trust2Desc', 'trust3Desc', 'trust4Desc', 'faq7A'],
    'verification' => ['verifySectionTitle', 'verifySectionBody', 'faq8A'],
];

it('loads the home page for a guest with its metadata and no private data', function (): void {
    $employee = Employee::query()->create(['employee_number' => 'HOME-PRIV-1', 'first_name' => 'Zelalem', 'last_name' => 'Privatename', 'full_name' => 'Zelalem Privatename', 'status' => 'active']);

    $response = $this->get('/')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->has('meta')
        ->missing('statistics')
        ->missing('stats')
        ->missing('employees'));
    $response->assertDontSee('Privatename')->assertDontSee($employee->employee_number)
        // Planning capacity must never be shown as a registration count.
        ->assertDontSee('180,000')->assertDontSee('180000');
});

it('passes administrator SEO metadata for the home page to the layout', function (): void {
    PublicPageMeta::query()->updateOrCreate(['page' => 'home'], ['meta_description_en' => 'Configured home description']);
    PublicSiteContent::flush();

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('meta.description_en', 'Configured home description'));
    expect(welcomeSource())->toContain('meta={meta}')->toContain("description={t('home.metaDescription')}");
});

it('explains every capability in both languages', function (string $topic, array $keys): void {
    $en = homeCopy('en');
    $am = homeCopy('am');
    foreach ($keys as $key) {
        expect($en[$key] ?? '')->not->toBe('', "English {$topic}: {$key}")
            ->and($am[$key] ?? '')->not->toBe('', "Amharic {$topic}: {$key}")
            // Amharic copy is written in Ethiopic script, not left in English.
            ->and(preg_match('/\p{Ethiopic}/u', $am[$key]))->toBe(1, "Amharic {$topic}: {$key}");
    }
})->with(fn () => collect(HOME_TOPICS)->map(fn ($keys, $topic) => [$topic, $keys])->all());

it('renders every card, step and question the copy defines', function (): void {
    $source = welcomeSource();
    foreach (['manageOrg', 'managePositions', 'manageRegistry', 'manageInsight', 'serviceCard', 'serviceCafeteria', 'serviceTransport', 'serviceProviders', 'serviceIntegration', 'serviceConsumer', 'serviceHealth', 'serviceWelfare', 'workDaily', 'workPerformance', 'workCompetency', 'workFeedback', 'workGrievance', 'workPortal', 'trust1', 'trust2', 'trust3', 'trust4'] as $card) {
        expect($source)->toContain("key: '{$card}'");
    }
    // Home shows the first five questions; the Support page falls back to all eight.
    expect($source)->toContain('const STEP_COUNT = 8;')->toContain('const FAQ_COUNT = 5;')->toContain('grid-cols-8')
        ->and(supportSource())->toContain('const DEFAULT_FAQS = [1, 2, 3, 4, 5, 6, 7, 8];');
    $en = homeCopy('en');
    foreach (range(1, 8) as $n) {
        expect($en)->toHaveKeys(["step{$n}Title", "step{$n}Desc", "faq{$n}Q", "faq{$n}A"]);
    }
});

it('keeps both languages complete and free of encoding damage', function (): void {
    $en = homeCopy('en');
    $am = homeCopy('am');
    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ([$en, $am] as $copy) {
        foreach ($copy as $key => $value) {
            expect($value)->not->toBe('', $key)->not->toContain('???');
        }
    }
});

it('marks services that are not yet integrated as planned', function (): void {
    $source = welcomeSource();
    foreach (['serviceConsumer', 'serviceHealth', 'serviceWelfare'] as $planned) {
        expect($source)->toMatch("/key: '{$planned}'[^}]*status: 'planned'/");
    }
    foreach (['serviceCafeteria', 'serviceTransport'] as $available) {
        expect($source)->toMatch("/key: '{$available}'[^}]*status: 'available'/");
    }
    expect(homeCopy('en')['statusPlanned'])->toBe('Planned / Future Integration');
});

it('makes no absolute or exaggerated claims', function (): void {
    $copy = strtolower(implode(' ', homeCopy('en')));
    foreach (['100% secure', 'cannot be hacked', 'tamper-proof', 'unalterable', 'immutable', 'append-only', 'revolutionary', 'perfect', 'eliminates all fraud', 'guarantees', 'government-grade', 'fully operational'] as $claim) {
        expect($copy)->not->toContain($claim);
    }
});

it('links its calls to action only to routes that exist', function (): void {
    preg_match_all("/route\('([\w.]+)'/", welcomeSource(), $matches);
    $routes = array_unique($matches[1]);
    expect($routes)->toContain('public.verify', 'employee.login', 'public.services', 'public.support');
    foreach ($routes as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
    $this->get(route('public.verify'))->assertOk();
});

it('routes support visitors to real pages and keeps FAQ answers when none are configured', function (): void {
    preg_match_all("/routeName: '([\w.]+)'/", supportSource(), $matches);
    expect($matches[1])->toBe(['public.verify', 'employee.login', 'public.services']);
    foreach ($matches[1] as $name) {
        expect(Route::has($name))->toBeTrue("Missing route {$name}");
    }
    $copy = (string) file_get_contents(resource_path('js/i18n/am/publicSite.ts'));
    foreach (['helpTitle', 'helpVerifyDesc', 'helpPortalDesc', 'helpFeedbackDesc', 'helpServicesDesc', 'footerHelp', 'footerHelpLink'] as $key) {
        expect($copy)->toMatch("/{$key}: '[^']*\p{Ethiopic}/u");
    }

    $this->get('/support')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Support')->where('faqs', []));
});

it('gives an unconfigured footer a description and a way to help', function (): void {
    $footer = (string) file_get_contents(resource_path('js/Components/public/PublicFooter.tsx'));
    expect($footer)->toContain("|| t('home.metaDescription')")->toContain("route('public.support')")
        ->toContain('grid grid-cols-2');
});
