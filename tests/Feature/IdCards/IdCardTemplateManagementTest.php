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
    $this->actingAs(User::factory()->create())->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
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
    $this->post(route('id-card-templates.store'), templatePayload([
        'orientation' => 'portrait', 'width_mm' => 60, 'height_mm' => 90,
        'back_background' => UploadedFile::fake()->image('back.png', 540, 856),
    ]))->assertSessionHasNoErrors();
    $data = app(IdCardRenderDataFactory::class)->make($card);
    expect($data->orientation)->toBe('portrait')->and($data->widthMm)->toBe(60.0)->and($data->heightMm)->toBe(90.0);
    $svg = app(IdCardSvgRenderer::class)->renderBack($data);
    $document = new DOMDocument;
    expect($document->loadXML($svg))->toBeTrue()->and($svg)->toContain('id="backBackground"')
        ->toContain('width="224" height="224"')->toContain('width="600" height="900"');
    expect(app(IdCardRenderDataFactory::class)->make($card, 'landscape')->widthMm)->toBe(90.0);
});

it('saves a style for every text role on both sides', function (): void {
    $config = [
        'front' => [
            'header' => ['color' => '#101010', 'font_size' => '12px', 'font_weight' => '700'],
            'label' => ['color' => '#112233', 'font_size' => '9px', 'font_weight' => '600'],
            'value' => ['color' => '#445566', 'font_size' => '10px', 'font_weight' => '500'],
            'footer' => ['color' => '#334455', 'font_size' => '7px', 'font_weight' => '400'],
        ],
        'back' => [
            'header' => ['color' => '#556677', 'font_size' => '12px', 'font_weight' => '700'],
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
                'footer' => ['color' => '#556677', 'font_size' => '8px', 'font_weight' => '400'],
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
    expect($front)->toContain('#AA0011')->toContain('#00BB22')->toContain('#CC3344')->toContain('#556677')
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
