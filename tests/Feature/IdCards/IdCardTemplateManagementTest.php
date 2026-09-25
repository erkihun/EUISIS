<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\IdCardTemplate;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardPngExporter;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\IdCardTemplateService;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function templatePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'City artwork', 'code' => 'city-artwork', 'description' => 'Test artwork',
        'orientation' => 'landscape', 'width_mm' => 85.6, 'height_mm' => 54,
        'status' => 'active', 'is_default' => true,
    ], $overrides);
}

function templateCard(): IdCard
{
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-TEMPLATE-1', 'full_name' => 'Template Employee',
        'first_name' => 'Template', 'last_name' => 'Employee', 'status' => EmployeeStatus::Active,
    ]);
    $type = OrganizationType::query()->create(['code' => 'TEMPLATE', 'name_en' => 'Template test']);
    $organization = Organization::query()->create([
        'code' => 'TEMPLATE', 'name_en' => 'Template organization', 'organization_type_id' => $type->id,
        'status' => OrganizationStatus::Active,
    ]);
    $version = HierarchyVersion::query()->create(['version_name' => 'template-test', 'status' => HierarchyVersionStatus::Published]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'hierarchy_version_id' => $version->id,
        'assignment_status' => AssignmentStatus::Active, 'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);
    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'CARD-TEMPLATE-1',
        'status' => CardStatus::Active, 'token_hash' => hash('sha256', 'existing-token'),
        'token_version' => 3, 'qr_payload' => 'existing-payload', 'is_current' => true,
    ]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);

    return $card->refresh();
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = User::factory()->create();
    $abilities = ['view', 'create', 'update', 'delete', 'set_default'];
    foreach ($abilities as $ability) {
        Permission::findOrCreate('id_card_templates.'.$ability, 'web');
    }
    foreach (['cards.view', 'id-cards.previewSvg', 'id-cards.exportPng'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->admin->givePermissionTo([
        ...array_map(fn ($ability) => 'id_card_templates.'.$ability, $abilities),
        'cards.view', 'id-cards.previewSvg', 'id-cards.exportPng',
    ]);
    $this->actingAs($this->admin);
});

it('opens the authorized template page without exposing private paths', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 856, 540),
    ]))->assertSessionHasNoErrors();
    $this->get(route('id-card-templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('SystemSettings/IdCardTemplates')->has('templates', 1)
        ->missing('templates.0.front_background_path')->missing('templates.0.back_background_path')
        ->where('can.create', true)->where('can.set_default', true));
});

it('denies every management operation without its permission', function (): void {
    $template = IdCardTemplate::query()->create(templatePayload(['is_default' => false]));
    $this->actingAs(User::factory()->create());
    $this->get(route('id-card-templates.index'))->assertForbidden();
    $this->post(route('id-card-templates.store'), templatePayload())->assertForbidden();
    $this->patch(route('id-card-templates.update', $template), templatePayload())->assertForbidden();
    $this->delete(route('id-card-templates.destroy', $template))->assertForbidden();
});

it('uploads both PNG files using generated private paths', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 856, 540),
        'back_background' => UploadedFile::fake()->image('back.png', 856, 540),
        'front_background_path' => '../../unsafe.png',
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    expect($template->front_background_path)->toStartWith('id-card-templates/')->toEndWith('.png')
        ->not->toContain('front.png')->not->toContain('base64')
        ->and($template->back_background_path)->not->toBe($template->front_background_path)
        ->and($template->created_by)->toBe($this->admin->id);
    Storage::disk('local')->assertExists([$template->front_background_path, $template->back_background_path]);
});

it('rejects non PNG content and disguised extensions', function (string $kind): void {
    $validPng = UploadedFile::fake()->image('valid.png', 200, 200);
    $file = match ($kind) {
        'jpeg' => UploadedFile::fake()->image('image.jpg', 200, 200),
        'disguised' => UploadedFile::fake()->createWithContent('image.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        'extension' => new UploadedFile($validPng->getPathname(), 'image.jpg', 'image/png', null, true),
    };
    $this->post(route('id-card-templates.store'), templatePayload(['front_background' => $file]))
        ->assertSessionHasErrors('front_background');
    expect(IdCardTemplate::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['jpeg', 'disguised', 'extension']);

it('enforces the system upload limit and safe image dimensions', function (): void {
    SystemSetting::query()->updateOrCreate(['group' => 'security', 'key' => 'max_upload_size_mb'], ['type' => 'integer', 'value' => '1']);
    app(SystemSettingsService::class)->clearCache();
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('large.png', 200, 200)->size(1025),
    ]))->assertSessionHasErrors('front_background');
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('tiny.png', 10, 10),
    ]))->assertSessionHasErrors('front_background');
});

it('replaces and removes backgrounds without losing default selection', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 200, 200),
        'back_background' => UploadedFile::fake()->image('back.png', 200, 200),
    ]))->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    $oldFront = $template->front_background_path;
    $oldBack = $template->back_background_path;
    $this->post(route('id-card-templates.update', $template), templatePayload([
        '_method' => 'patch', 'front_background' => UploadedFile::fake()->image('replacement.png', 300, 200),
        'remove_back_background' => true,
    ]))->assertSessionHasNoErrors();
    $template->refresh();
    Storage::disk('local')->assertMissing([$oldFront, $oldBack]);
    Storage::disk('local')->assertExists($template->front_background_path);
    expect($template->back_background_path)->toBeNull()->and($template->is_default)->toBeTrue();
});

it('keeps old files and cleans new files if saving fails', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 200, 200),
    ]))->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    $path = $template->front_background_path;
    Event::listen('eloquent.saving: '.IdCardTemplate::class, function (IdCardTemplate $record): void {
        if ($record->name === 'Fail save') {
            throw new RuntimeException('Simulated database failure');
        }
    });
    $this->withoutExceptionHandling();
    try {
        $this->patch(route('id-card-templates.update', $template), templatePayload([
            'name' => 'Fail save', 'front_background' => UploadedFile::fake()->image('new.png', 200, 200),
        ]));
        $this->fail('The simulated failure should propagate.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated database failure');
    } finally {
        Event::forget('eloquent.saving: '.IdCardTemplate::class);
    }
    expect($template->refresh()->front_background_path)->toBe($path)
        ->and(Storage::disk('local')->allFiles('id-card-templates'))->toBe([$path]);
});

it('requires separate default permission and rejects inactive defaults', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['id_card_templates.create', 'id_card_templates.update', 'id_card_templates.delete']);
    $this->actingAs($user)->post(route('id-card-templates.store'), templatePayload())->assertForbidden();
    $this->post(route('id-card-templates.store'), templatePayload(['is_default' => false]))->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    $this->patch(route('id-card-templates.update', $template), templatePayload())->assertForbidden();
    $this->actingAs($this->admin)->patch(route('id-card-templates.update', $template), templatePayload(['status' => 'inactive']))->assertSessionHasErrors('status');
    $this->patch(route('id-card-templates.update', $template), templatePayload())->assertSessionHasNoErrors();
    $this->actingAs($user)->delete(route('id-card-templates.destroy', $template))->assertForbidden();
});

it('maintains one default and falls back after deleting it', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload())->assertSessionHasNoErrors();
    $first = IdCardTemplate::query()->sole();
    $this->post(route('id-card-templates.store'), templatePayload(['code' => 'second']))->assertSessionHasNoErrors();
    $second = IdCardTemplate::query()->where('code', 'second')->sole();
    expect($first->refresh()->is_default)->toBeFalse()->and($second->is_default)->toBeTrue()
        ->and(IdCardTemplate::query()->where('is_default', true)->count())->toBe(1);
    $this->delete(route('id-card-templates.destroy', $second))->assertRedirect();
    $this->assertSoftDeleted($second);
    expect(app(IdCardTemplateService::class)->active())->toBeNull();
});

it('allows default selection with only its dedicated permission', function (): void {
    $template = IdCardTemplate::query()->create(templatePayload(['is_default' => false]));
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('id-card-templates.set-default', $template))->assertForbidden();
    $user->givePermissionTo('id_card_templates.set_default');
    $this->post(route('id-card-templates.set-default', $template))->assertSessionHasNoErrors();
    expect($template->refresh()->is_default)->toBeTrue();
    $this->patch(route('id-card-templates.update', $template), templatePayload(['name' => 'Forbidden edit']))->assertForbidden();
});

it('serves active artwork to card consumers and restricts drafts and traversal', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 200, 200),
    ]))->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    $url = route('id-card-templates.background', [$template, 'front']);
    // SBH-002: being signed in is not enough; the artwork carries the seal and signature.
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $cardStaff = User::factory()->create();
    $cardStaff->givePermissionTo(Permission::findOrCreate('cards.view', 'web'));
    $this->actingAs($cardStaff)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $template->update(['is_default' => false]);
    $this->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get($url)->assertOk();
    $template->update(['front_background_path' => '../secret.png']);
    $this->get($url)->assertNotFound();
    $this->get(route('id-card-templates.background', [$template, 'invalid']))->assertNotFound();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

it('shares artwork in card previews and the SVG export source without changing QR identity', function (): void {
    $card = templateCard();
    $before = $card->getRawOriginal();
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 856, 540),
        'back_background' => UploadedFile::fake()->image('back.png', 800, 500),
    ]))->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    $presentation = app(IdCardTemplateService::class)->presentation($template);
    $this->get(route('id-cards.preview', $card))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('IdCards/Preview')->where('idCardTemplate.front_background_url', $presentation['front_background_url'])
        ->where('idCardTemplate.back_background_url', $presentation['back_background_url']));
    foreach (['front', 'back'] as $side) {
        $uri = app(IdCardTemplateService::class)->dataUri($template->{$side.'_background_path'});
        $this->get(route('id-cards.preview.svg.'.$side, $card))->assertOk()
            ->assertSee('id="'.$side.'Background"', false)->assertSee($uri, false);
        if (app(IdCardPngExporter::class)->isAvailable()) {
            $response = $this->get(route('id-cards.export.png.'.$side, $card))->assertOk()->assertHeader('Content-Type', 'image/png');
            expect(substr($response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
        }
    }
    $this->patch(route('id-card-templates.update', $template), templatePayload([
        'front_background' => UploadedFile::fake()->image('replace.png', 856, 540),
        'remove_back_background' => true,
    ]))->assertSessionHasNoErrors();
    $this->post(route('id-card-templates.store'), templatePayload(['code' => 'new-default']))->assertSessionHasNoErrors();
    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    expect($data->frontBackgroundDataUri)->toBeNull()->and($data->backBackgroundDataUri)->toBeNull();
    expect(app(IdCardSvgRenderer::class)->renderFront($data))->not->toContain('id="frontBackground"');
    expect($card->refresh()->getRawOriginal())->toBe($before);
});

it('renders portrait backgrounds with configured output dimensions and square QR modules', function (): void {
    $card = templateCard();
    $feedbackToken = app(EmployeeFeedbackTokenService::class)->ensureActiveToken($card->employee);
    $this->post(route('id-card-templates.store'), templatePayload([
        'orientation' => 'portrait', 'width_mm' => 60, 'height_mm' => 90,
        'back_background' => UploadedFile::fake()->image('back.png', 540, 856),
    ]))->assertSessionHasNoErrors();
    $data = app(IdCardRenderDataFactory::class)->make($card);
    expect($data->orientation)->toBe('portrait')->and($data->widthMm)->toBe(60.0)->and($data->heightMm)->toBe(90.0)
        ->and($data->feedbackQrUrl)->toBe($feedbackToken->publicUrl());
    $svg = app(IdCardSvgRenderer::class)->renderBack($data);
    $document = new DOMDocument;
    expect($document->loadXML($svg))->toBeTrue()->and($svg)->toContain('id="backBackground"')
        ->toContain('Feedback and Suggestion QR')
        // The root carries the card's physical size, so printing or exporting
        // it reproduces 60 x 90 mm rather than a pixel count the viewer scales.
        ->toContain('width="60mm" height="90mm"');
    // Only a portrait template exists, so a landscape card falls back to the
    // built-in ID-1 card rather than borrowing the portrait template's size.
    expect(app(IdCardRenderDataFactory::class)->make($card, 'landscape')->widthMm)->toBe(85.6);
});

it('saves a style for every text role on both sides', function (): void {
    $config = [
        'front' => [
            'header' => ['color' => '#101010', 'font_size' => '12px', 'font_weight' => '700'],
            'label' => ['color' => '#112233', 'font_size' => '9px', 'font_weight' => '600'],
            'value' => ['color' => '#445566', 'font_size' => '10px', 'font_weight' => '500'],
            'employee_name' => ['color' => '#556677', 'font_size' => '12px', 'font_weight' => '700'],
            'employee_position' => ['color' => '#667788', 'font_size' => '9px', 'font_weight' => '600'],
        ],
        // The back has no heading role; its notes lead the text column.
        'back' => [
            'label' => ['color' => '#778899', 'font_size' => '8px', 'font_weight' => '600'],
            'value' => ['color' => '#AABBCC', 'font_size' => '13px', 'font_weight' => '400'],
            'footer' => ['color' => '#CCDDEE', 'font_size' => '7px', 'font_weight' => '400'],
        ],
    ];
    $this->post(route('id-card-templates.store'), templatePayload(['text_style_config' => $config]))
        ->assertSessionHasNoErrors();

    expect(IdCardTemplate::query()->sole()->text_style_config)->toBe($config);

    // The edit page hands every saved role back to the form.
    $this->get(route('id-card-templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('templates.0.text_style_config.front.value', $config['front']['value'])
        ->where('templates.0.text_style_config.front.employee_name', $config['front']['employee_name'])
        ->where('templates.0.text_style_config.front.employee_position', $config['front']['employee_position'])
        ->where('templates.0.text_style_config.back.footer', $config['back']['footer']));
});

it('rejects colours, sizes and weights outside the allowed set', function (array $style, string $key): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'text_style_config' => ['front' => ['label' => $style]],
    ]))->assertSessionHasErrors('text_style_config.front.label.'.$key);
})->with([
    'colour' => [['color' => 'red', 'font_size' => '10px', 'font_weight' => '600'], 'color'],
    'unsafe colour' => [['color' => 'red;}body{display:none', 'font_size' => '10px', 'font_weight' => '600'], 'color'],
    'size' => [['color' => '#112233', 'font_size' => '99px', 'font_weight' => '600'], 'font_size'],
    'unsafe size' => [['color' => '#112233', 'font_size' => '10px;x', 'font_weight' => '600'], 'font_size'],
    'weight' => [['color' => '#112233', 'font_size' => '10px', 'font_weight' => '900'], 'font_weight'],
]);

it('applies saved template styles to the exported card without touching the QR token', function (): void {
    $card = templateCard();
    $before = $card->getRawOriginal();
    $this->post(route('id-card-templates.store'), templatePayload([
        'text_style_config' => [
            'front' => [
                'label' => ['color' => '#AA0011', 'font_size' => '11px', 'font_weight' => '700'],
                'value' => ['color' => '#00BB22', 'font_size' => '14px', 'font_weight' => '500'],
                'header' => ['color' => '#CC3344', 'font_size' => '12px', 'font_weight' => '800'],
            ],
            'back' => [
                'value' => ['color' => '#123456', 'font_size' => '9px', 'font_weight' => '600'],
            ],
        ],
    ]))->assertSessionHasNoErrors();

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    $front = app(IdCardSvgRenderer::class)->renderFront($data);
    $back = app(IdCardSvgRenderer::class)->renderBack($data);

    // Card coordinates are twice the millimetre size, so px values are doubled.
    expect($front)->toContain('#AA0011')->toContain('#00BB22')->toContain('#CC3344')
        ->toContain('font-size="22"')->toContain('font-size="28"')
        ->toContain('font-weight="700"')->toContain('font-weight="500"')->toContain('font-weight="800"');
    expect($back)->toContain('#123456')->toContain('font-size="18"');

    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($card->fresh());
    expect($card->refresh()->getRawOriginal())->toBe($before)
        ->and($data->qrVerificationUrl)->toBe($qr);
});

it('keeps rendering templates saved before per-template typography', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload())->assertSessionHasNoErrors();
    IdCardTemplate::query()->sole()->forceFill(['text_style_config' => null])->save();

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    $svg = app(IdCardSvgRenderer::class)->renderFront($data);

    // Falls back to the neutral card surface ink, not the retired dark theme.
    expect($svg)->toContain('ስም')->toContain('Name')->toContain('#0F172A')->toContain('#475569');
});

it('overrides the header text per template and inherits every blank field', function (): void {
    $card = templateCard();
    SystemSetting::query()->updateOrCreate(
        ['group' => 'id_cards', 'key' => 'city_name_am'],
        ['type' => 'string', 'value' => 'የስርዓቱ ከተማ'],
    );
    app(SystemSettingsService::class)->clearCache();

    $this->post(route('id-card-templates.store'), templatePayload([
        // Only the English city is overridden; the Amharic one must inherit.
        'header_config' => ['city_name_en' => 'Template City'],
    ]))->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    expect($svg)->toContain('Template City')->toContain('የስርዓቱ ከተማ');
});

it('keeps the bureau names off the header band', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'header_config' => [
            'city_name_am' => 'የከተማ ስም', 'city_name_en' => 'City Name',
            'bureau_name_am' => 'የቢሮ ስም', 'bureau_name_en' => 'Bureau Name',
        ],
    ]))->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    // The header carries the city name only, in both languages.
    expect($svg)->toContain('የከተማ ስም')->toContain('City Name')
        ->not->toContain('የቢሮ ስም')->not->toContain('Bureau Name');
});

it('renders identically to the global settings when a template stores no header', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload())->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();

    $inherited = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    // An explicit empty override must read the same as storing nothing at all.
    $template->forceFill(['header_config' => ['city_name_en' => '', 'bureau_name_en' => '']])->save();
    $blank = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    expect($blank)->toBe($inherited);
});

it('stores each header logo slot and serves it through the asset route', function (string $slot): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'logo_'.$slot => UploadedFile::fake()->image('logo.png', 200, 200),
    ]))->assertSessionHasNoErrors();

    $template = IdCardTemplate::query()->sole();
    $path = $template->{'logo_'.$slot.'_path'};
    expect($path)->toStartWith('id-card-templates/')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $this->get(route('id-card-templates.background', [$template, 'logo-'.$slot]))->assertOk();
})->with(['primary', 'secondary']);

it('keeps the two header logo slots independent', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'logo_primary' => UploadedFile::fake()->image('left.png', 200, 200),
        'logo_secondary' => UploadedFile::fake()->image('right.png', 200, 200),
    ]))->assertSessionHasNoErrors();

    $template = IdCardTemplate::query()->sole();
    expect($template->logo_primary_path)->not->toBe($template->logo_secondary_path);

    // Both marks are drawn, each with its own clip path.
    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );
    expect($svg)->toContain('id="logoClip"')->toContain('id="logoClipSecondary"');
});

it('rejects a header logo that is not a real png', function (string $slot): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'logo_'.$slot => UploadedFile::fake()->image('logo.jpg', 200, 200),
    ]))->assertSessionHasErrors('logo_'.$slot);

    expect(IdCardTemplate::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['primary', 'secondary']);

it('never exposes a stored logo path to the client', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'logo_primary' => UploadedFile::fake()->image('left.png', 200, 200),
        'logo_secondary' => UploadedFile::fake()->image('right.png', 200, 200),
    ]))->assertSessionHasNoErrors();

    $template = IdCardTemplate::query()->sole();
    $payload = app(IdCardTemplateService::class)->managementData($this->admin, 10);
    $encoded = json_encode($payload);

    foreach (['logo_primary_path', 'logo_secondary_path'] as $column) {
        expect($encoded)->not->toContain($template->{$column})
            ->and($payload['templates'][0])->not->toHaveKey($column);
    }
});

it('hides each header logo slot on its own', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'logo_primary' => UploadedFile::fake()->image('left.png', 200, 200),
        'logo_secondary' => UploadedFile::fake()->image('right.png', 200, 200),
        // Only the right mark is hidden; the left one must survive.
        'header_config' => ['show_secondary_logo' => false],
    ]))->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    expect($svg)->toContain('id="logoClip"')->not->toContain('id="logoClipSecondary"');
});

it('draws no placeholder when a header logo slot is turned off', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'header_config' => ['show_logo' => false, 'show_secondary_logo' => false],
    ]))->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    // Neither the image nor the initials placeholder is drawn.
    expect($svg)->not->toContain('logoClip')->not->toContain('>AA<');
});

it('does not rotate the QR reference when header content changes', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload())->assertSessionHasNoErrors();
    $template = IdCardTemplate::query()->sole();
    $before = $card->fresh()->getRawOriginal();
    $qrBefore = app(CardQrPayloadService::class)->buildStableQrUrl($card->fresh());

    $this->post(route('id-card-templates.update', $template), templatePayload([
        'header_config' => ['city_name_en' => 'Renamed City'],
        'logo_primary' => UploadedFile::fake()->image('logo.png', 200, 200),
    ]))->assertSessionHasNoErrors();

    expect($card->refresh()->getRawOriginal())->toBe($before)
        ->and(app(CardQrPayloadService::class)->buildStableQrUrl($card->fresh()))->toBe($qrBefore);
});

it('prints the header city name in both languages beside one logo', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'header_config' => ['city_name_am' => 'የከተማ ስም', 'city_name_en' => 'City Name'],
    ]))->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    // Both lines print, and they share one text column at the same x.
    preg_match_all('/<text x="(\d+)" y="(\d+)"[^>]*>([^<]*)</u', $svg, $matches, PREG_SET_ORDER);
    $lines = array_values(array_filter($matches, fn (array $m): bool => in_array(
        trim($m[3]), ['የከተማ ስም', 'City Name'], true,
    )));

    expect($lines)->toHaveCount(2)
        ->and(array_unique(array_column($lines, 1)))->toHaveCount(1);
});

it('keeps the whole header inside its own band', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'header_config' => ['city_name_am' => 'የከተማ ስም', 'city_name_en' => 'City Name'],
    ]))->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    // The band is the first header rect; no header line may fall past it.
    preg_match('/height="(\d+)" fill="rgba\(15,23,42,0\.15\)"/', $svg, $band);
    preg_match_all('/<text x="\d+" y="(\d+)"[^>]*>([^<]*)</u', $svg, $matches, PREG_SET_ORDER);
    $headerYs = array_map(
        fn (array $m): int => (int) $m[1],
        array_filter($matches, fn (array $m): bool => in_array(
            trim($m[2]), ['የከተማ ስም', 'City Name'], true,
        )),
    );

    expect($headerYs)->not->toBeEmpty()
        ->and(max($headerYs))->toBeLessThanOrEqual((int) $band[1]);
});

it('accepts a header logo at any size or aspect ratio', function (int $width, int $height): void {
    // The renderer scales a logo into its layout box, so the file's own
    // dimensions are not constrained the way card artwork's are.
    $this->post(route('id-card-templates.store'), templatePayload([
        'logo_primary' => UploadedFile::fake()->image('left.png', $width, $height),
        'logo_secondary' => UploadedFile::fake()->image('right.png', $width, $height),
    ]))->assertSessionHasNoErrors();

    $template = IdCardTemplate::query()->sole();
    expect($template->logo_primary_path)->not->toBeNull()
        ->and($template->logo_secondary_path)->not->toBeNull();
})->with([
    'tiny square' => [8, 8],
    'small square' => [32, 32],
    'large square' => [2000, 2000],
    'wide banner' => [1200, 60],
    'tall strip' => [60, 1200],
]);

it('keeps the 100px floor on background artwork', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'front_background' => UploadedFile::fake()->image('front.png', 32, 32),
    ]))->assertSessionHasErrors('front_background');
});

it('keeps the back photo off until a template turns it on', function (): void {
    $card = templateCard();
    $card->employee->forceFill(['photo_path' => 'employee-photos/sample.png'])->save();
    $this->post(route('id-card-templates.store'), templatePayload())->assertSessionHasNoErrors();

    $svg = app(IdCardSvgRenderer::class)->renderBack(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    expect($svg)->not->toContain('backPhoto');
});

it('draws the back photo with the opacity, contrast and fit a template stores', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'back_photo_config' => [
            'show' => true, 'opacity' => 25, 'contrast' => 140,
            'fit' => 'contain', 'background_color' => '#EEF2FF',
        ],
    ]))->assertSessionHasNoErrors();

    $stored = IdCardTemplate::query()->sole()->back_photo_config;
    expect((int) $stored['opacity'])->toBe(25)
        ->and((int) $stored['contrast'])->toBe(140)
        ->and($stored['fit'])->toBe('contain');
});

it('rejects a back photo setting outside its allowed range', function (array $config, string $key): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'back_photo_config' => $config,
    ]))->assertSessionHasErrors('back_photo_config.'.$key);
})->with([
    'opacity over 100' => [['show' => true, 'opacity' => 150], 'opacity'],
    'negative opacity' => [['show' => true, 'opacity' => -10], 'opacity'],
    'contrast over 300' => [['show' => true, 'contrast' => 400], 'contrast'],
    'unknown fit' => [['show' => true, 'fit' => 'tile'], 'fit'],
    'unsafe colour' => [['show' => true, 'background_color' => 'red; --x:1'], 'background_color'],
]);

it('clamps a back photo setting written straight to the database', function (): void {
    $card = templateCard();
    IdCardTemplate::query()->create([
        'name' => 'Corrupt photo', 'code' => 'corrupt-photo', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        // Bypasses validation, so the renderer must not trust it.
        'back_photo_config' => ['show' => true, 'opacity' => 900, 'contrast' => -50, 'fit' => 'tile'],
    ]);

    $photo = app(IdCardTemplateService::class)->backPhoto(IdCardTemplate::query()->sole());

    expect($photo->opacity)->toBe(100)
        ->and($photo->contrast)->toBe(0)
        ->and($photo->fit)->toBe('cover');
});

it('stores a seal and signature per template and serves them privately', function (): void {
    $this->post(route('id-card-templates.store'), templatePayload([
        'seal' => UploadedFile::fake()->image('seal.png', 300, 300),
        'signature' => UploadedFile::fake()->image('signature.png', 400, 120),
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $template = IdCardTemplate::query()->sole();
    expect($template->seal_path)->toStartWith('id-card-templates/')->toEndWith('.png')
        ->and($template->signature_path)->toStartWith('id-card-templates/')
        ->and($template->signature_path)->not->toBe($template->seal_path);
    Storage::disk('local')->assertExists([$template->seal_path, $template->signature_path]);

    // The page hands the editor URLs, never storage paths.
    $this->get(route('id-card-templates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('templates.0.seal_path')
        ->missing('templates.0.signature_path')
        ->whereNot('templates.0.seal_url', null)
        ->whereNot('templates.0.signature_url', null));

    $this->get(route('id-card-templates.background', [$template, 'seal']))->assertOk();
    $this->get(route('id-card-templates.background', [$template, 'signature']))->assertOk();
});

it('prints the template signature and prefers its seal over the global setting', function (): void {
    SystemSetting::query()->updateOrCreate(
        ['group' => 'general', 'key' => 'seal'],
        ['value' => 'system-assets/global-seal.png', 'type' => 'string'],
    );
    // The global seal is a public-disk asset, unlike template artwork.
    Storage::disk('public')->put('system-assets/global-seal.png', UploadedFile::fake()->image('g.png', 80, 80)->get());

    $this->post(route('id-card-templates.store'), templatePayload([
        'seal' => UploadedFile::fake()->image('seal.png', 300, 300),
        'signature' => UploadedFile::fake()->image('signature.png', 400, 120),
    ]))->assertSessionHasNoErrors();

    $template = IdCardTemplate::query()->sole();
    $data = app(IdCardRenderDataFactory::class)->make(templateCard());
    $back = app(IdCardSvgRenderer::class)->renderBack($data);

    $templateSeal = app(IdCardTemplateService::class)->dataUri($template->seal_path);
    expect($data->sealDataUri)->toBe($templateSeal)
        ->and($data->signatureDataUri)->not->toBeNull()
        ->and($back)->toContain('id="templateSignature"');

    $document = new DOMDocument;
    expect($document->loadXML($back))->toBeTrue();
});

it('falls back to the global seal when the template has none', function (): void {
    SystemSetting::query()->updateOrCreate(
        ['group' => 'general', 'key' => 'seal'],
        ['value' => 'system-assets/global-seal.png', 'type' => 'string'],
    );
    // The global seal is a public-disk asset, unlike template artwork.
    Storage::disk('public')->put('system-assets/global-seal.png', UploadedFile::fake()->image('g.png', 80, 80)->get());

    $this->post(route('id-card-templates.store'), templatePayload())->assertSessionHasNoErrors();

    $data = app(IdCardRenderDataFactory::class)->make(templateCard());

    // The global seal still prints, and no signature image is invented.
    expect($data->sealDataUri)->not->toBeNull()
        ->and($data->signatureDataUri)->toBeNull();
});

it('exports at the physical size the template configures', function (): void {
    $exporter = app(IdCardPngExporter::class);

    // The renderer writes physical units; the exporter reads them back.
    expect($exporter->millimetres('85.6mm', 0.0))->toBe(85.6)
        ->and($exporter->millimetres('60mm', 0.0))->toBe(60.0)
        // Older pixel form, at the canvas's 10 px per mm.
        ->and($exporter->millimetres('856', 0.0))->toBe(85.6)
        ->and($exporter->millimetres('', 54.0))->toBe(54.0)
        ->and($exporter->millimetres(null, 54.0))->toBe(54.0);

    // 300 DPI: an 85.6 mm card is 1011 px, a 60 x 90 mm card 709 x 1063.
    expect($exporter->pixelsForMillimetres(85.6))->toBe(1011)
        ->and($exporter->pixelsForMillimetres(54.0))->toBe(638)
        ->and($exporter->pixelsForMillimetres(60.0))->toBe(709)
        ->and($exporter->pixelsForMillimetres(90.0))->toBe(1063);
});

it('gives every rendered card face a physical size', function (): void {
    IdCardTemplate::query()->create(templatePayload([
        'orientation' => 'landscape', 'width_mm' => 85.6, 'height_mm' => 54,
    ]));

    $data = app(IdCardRenderDataFactory::class)->make(templateCard());
    $renderer = app(IdCardSvgRenderer::class);

    foreach ([$renderer->renderFront($data), $renderer->renderBack($data)] as $svg) {
        expect($svg)->toContain('width="85.6mm" height="54mm"')
            // The drawing coordinates are untouched, so nothing moves.
            ->and($svg)->toContain('viewBox="0 0 856 540"');
    }
});

it('applies a template only to cards of its own orientation', function (): void {
    $service = app(IdCardTemplateService::class);

    // A portrait template, made the default.
    $this->post(route('id-card-templates.store'), templatePayload([
        'code' => 'portrait-only', 'name' => 'Portrait only',
        'orientation' => 'portrait', 'width_mm' => 60, 'height_mm' => 90,
    ]))->assertSessionHasNoErrors();

    // It serves portrait cards, and nothing serves landscape ones.
    expect($service->active('portrait')?->orientation)->toBe('portrait')
        ->and($service->active('landscape'))->toBeNull();

    // Now add a landscape template. Each orientation keeps its own.
    $this->post(route('id-card-templates.store'), templatePayload([
        'code' => 'landscape-only', 'name' => 'Landscape only',
        'orientation' => 'landscape', 'is_default' => false,
    ]))->assertSessionHasNoErrors();

    expect($service->active('portrait')?->code)->toBe('portrait-only')
        ->and($service->active('landscape')?->code)->toBe('landscape-only')
        // With no orientation the default template still answers, whatever
        // shape it happens to be.
        ->and($service->active()?->code)->toBe('portrait-only');
});

it('renders each face with the template built for its orientation', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'code' => 'portrait-sized', 'name' => 'Portrait sized',
        'orientation' => 'portrait', 'width_mm' => 60, 'height_mm' => 90,
    ]))->assertSessionHasNoErrors();
    $this->post(route('id-card-templates.store'), templatePayload([
        'code' => 'landscape-sized', 'name' => 'Landscape sized', 'is_default' => false,
        'orientation' => 'landscape', 'width_mm' => 100, 'height_mm' => 70,
    ]))->assertSessionHasNoErrors();

    $factory = app(IdCardRenderDataFactory::class);

    // Each orientation gets its own template's millimetres, never the other's.
    expect($factory->make($card, 'portrait')->widthMm)->toBe(60.0)
        ->and($factory->make($card, 'portrait')->heightMm)->toBe(90.0)
        ->and($factory->make($card, 'landscape')->widthMm)->toBe(100.0)
        ->and($factory->make($card, 'landscape')->heightMm)->toBe(70.0);
});

it('falls back to the built-in card when an orientation has no template', function (): void {
    $card = templateCard();
    $this->post(route('id-card-templates.store'), templatePayload([
        'orientation' => 'landscape', 'width_mm' => 100, 'height_mm' => 70,
        'back_background' => UploadedFile::fake()->image('back.png', 856, 540),
    ]))->assertSessionHasNoErrors();

    $portrait = app(IdCardRenderDataFactory::class)->make($card, 'portrait');

    // No portrait template: built-in size, and none of the landscape
    // template's artwork bleeds across.
    expect($portrait->widthMm)->toBe(54.0)
        ->and($portrait->heightMm)->toBe(85.6)
        ->and($portrait->backBackgroundDataUri)->toBeNull();
});
