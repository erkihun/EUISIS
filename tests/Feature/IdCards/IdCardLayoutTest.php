<?php

declare(strict_types=1);

use App\Models\IdCardTemplate;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardLayoutElement;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\IdCardTemplateService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/IdCardFrontFieldsTest.php';

/** Minimum valid payload for the save endpoint. */
function layoutTemplatePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Layout template', 'code' => 'layout-template',
        'orientation' => 'landscape', 'width_mm' => 85.6, 'height_mm' => 54,
        'status' => 'active', 'is_default' => true,
    ], $overrides);
}

beforeEach(function (): void {
    $abilities = ['view', 'create', 'update', 'delete', 'set_default'];
    foreach ($abilities as $ability) {
        Permission::findOrCreate('id_card_templates.'.$ability, 'web');
    }
    $admin = User::factory()->create();
    $admin->givePermissionTo(array_map(fn (string $ability): string => 'id_card_templates.'.$ability, $abilities));
    $this->actingAs($admin);
});

/** A template positioning the front elements away from the built-in arrangement. */
function movedLayout(array $overrides = []): array
{
    return ['front' => array_replace([
        'photo' => ['x' => 70.0, 'y' => 20.0, 'w' => 20.0, 'h' => 40.0],
        'footer' => ['x' => 0.0, 'y' => 80.0, 'w' => 100.0, 'h' => 6.0],
    ], $overrides)];
}

it('keeps the built-in arrangement when a template stores no layout', function (): void {
    $card = frontCard();
    $before = renderFrontSvg($card);

    IdCardTemplate::query()->create([
        'name' => 'No layout', 'code' => 'no-layout', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true, 'layout_config' => null,
    ]);

    // A template that positions nothing must not move a single pixel.
    expect(renderFrontSvg($card->fresh()))->toBe($before);
});

it('moves elements to the positions a template stores', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Moved', 'code' => 'moved', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => movedLayout(),
    ]);

    $svg = renderFrontSvg($card->fresh());

    // 70% of the 856 canvas is 599; 20% of 540 is 108.
    expect($svg)->toContain('<rect x="599" y="108" width="171" height="216"');
});

it('resolves every element for the client, filling gaps from the defaults', function (): void {
    $template = IdCardTemplate::query()->create([
        'name' => 'Partial', 'code' => 'partial', 'orientation' => 'landscape',
        'status' => 'active', 'layout_config' => ['front' => ['photo' => ['x' => 50.0, 'y' => 50.0, 'w' => 10.0, 'h' => 10.0]]],
    ]);

    $layout = app(IdCardTemplateService::class)->presentation($template)['layout_config']['front'];

    expect(array_keys($layout))->toBe(array_keys(IdCardLayoutElement::ELEMENTS['front']))
        ->and($layout['photo'])->toBe(['x' => 50.0, 'y' => 50.0, 'w' => 10.0, 'h' => 10.0])
        ->and($layout['footer'])->toBe(IdCardLayoutElement::ELEMENTS['front']['footer']);
});

it('rejects positions that fall outside the card', function (array $box, string $key): void {
    $this->post(route('id-card-templates.store'), layoutTemplatePayload([
        'layout_config' => ['front' => ['photo' => $box]],
    ]))->assertSessionHasErrors('layout_config.front.photo.'.$key);
})->with([
    'negative x' => [['x' => -5, 'y' => 10, 'w' => 20, 'h' => 20], 'x'],
    'x over 100' => [['x' => 140, 'y' => 10, 'w' => 20, 'h' => 20], 'x'],
    'zero width' => [['x' => 10, 'y' => 10, 'w' => 0, 'h' => 20], 'w'],
    'runs off the right edge' => [['x' => 90, 'y' => 10, 'w' => 30, 'h' => 20], 'w'],
    'runs off the bottom edge' => [['x' => 10, 'y' => 90, 'w' => 20, 'h' => 30], 'h'],
    'not numeric' => [['x' => 'left', 'y' => 10, 'w' => 20, 'h' => 20], 'x'],
]);

it('saves a layout through the controller and reads it back', function (): void {
    $this->post(route('id-card-templates.store'), layoutTemplatePayload([
        'layout_config' => movedLayout(),
    ]))->assertSessionHasNoErrors();

    // The request layer stringifies form values; what matters is the numbers.
    $photo = array_map('floatval', IdCardTemplate::query()->sole()->layout_config['front']['photo']);
    expect($photo)->toBe(['x' => 70.0, 'y' => 20.0, 'w' => 20.0, 'h' => 40.0]);
});

it('clamps a stored position that is somehow out of range', function (): void {
    // Written straight to the database, bypassing validation.
    IdCardTemplate::query()->create([
        'name' => 'Corrupt', 'code' => 'corrupt', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['front' => ['photo' => ['x' => 300.0, 'y' => -50.0, 'w' => 400.0, 'h' => 10.0]]],
    ]);

    $box = app(IdCardTemplateService::class)->box(IdCardTemplate::query()->sole(), 'front', 'photo');

    expect($box->x)->toBe(99.0)->and($box->y)->toBe(0.0)
        ->and($box->x + $box->w)->toBeLessThanOrEqual(100.0);
});

it('does not touch the QR token or card identity when the layout changes', function (): void {
    $card = frontCard();
    $before = $card->getRawOriginal();
    $qrBefore = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    $this->post(route('id-card-templates.store'), layoutTemplatePayload([
        'layout_config' => movedLayout(),
    ]))->assertSessionHasNoErrors();

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    app(IdCardSvgRenderer::class)->renderFront($data);

    expect($card->refresh()->getRawOriginal())->toBe($before)
        ->and($data->qrVerificationUrl)->toBe($qrBefore);
});

it('keeps the built-in back arrangement when a template stores no layout', function (): void {
    $card = frontCard();
    $before = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card));

    IdCardTemplate::query()->create([
        'name' => 'No back layout', 'code' => 'no-back-layout', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true, 'layout_config' => null,
    ]);

    $after = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    expect($after)->toBe($before);
});

it('moves back elements to the positions a template stores', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Back moved', 'code' => 'back-moved', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['back' => ['qr' => ['x' => 60.0, 'y' => 10.0, 'w' => 22.0, 'h' => 45.0]]],
    ]);

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // 60% of the 856 canvas is 514; the QR box follows its element.
    expect($svg)->toContain('<rect x="514"');
});

it('validates back element positions the same way as the front', function (): void {
    $this->post(route('id-card-templates.store'), layoutTemplatePayload([
        'layout_config' => ['back' => ['qr' => ['x' => 90.0, 'y' => 10.0, 'w' => 30.0, 'h' => 20.0]]],
    ]))->assertSessionHasErrors('layout_config.back.qr.w');
});

it('resolves every back element for the client', function (): void {
    $template = IdCardTemplate::query()->create([
        'name' => 'Back partial', 'code' => 'back-partial', 'orientation' => 'landscape',
        'status' => 'active', 'layout_config' => ['back' => ['seal' => ['x' => 10.0, 'y' => 10.0, 'w' => 20.0, 'h' => 20.0]]],
    ]);

    $layout = app(IdCardTemplateService::class)->presentation($template)['layout_config']['back'];

    expect(array_keys($layout))->toBe(array_keys(IdCardLayoutElement::ELEMENTS['back']))
        ->and($layout['seal'])->toBe(['x' => 10.0, 'y' => 10.0, 'w' => 20.0, 'h' => 20.0])
        ->and($layout['qr'])->toBe(IdCardLayoutElement::ELEMENTS['back']['qr']);
});

it('moves each back element independently', function (string $element, array $box, string $needle): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Back '.$element, 'code' => 'back-el-'.$element, 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['back' => [$element => $box]],
    ]);

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    expect($svg)->toContain($needle);
})->with([
    // 856x540 canvas: x% of 856, y% of 540.
    'qr' => ['qr', ['x' => 60.0, 'y' => 10.0, 'w' => 22.0, 'h' => 45.0], '<rect x="514"'],
    'details' => ['details', ['x' => 40.0, 'y' => 60.0, 'w' => 50.0, 'h' => 20.0], 'x="342"'],
]);

it('places the official seal where the template puts it', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Seal placed', 'code' => 'seal-placed', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['back' => ['seal' => ['x' => 10.0, 'y' => 12.0, 'w' => 20.0, 'h' => 30.0]]],
    ]);

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    $box = $data->box('back', 'seal');

    // The seal image only renders when a seal is configured, so assert the
    // resolved geometry rather than depending on the fixture having artwork.
    expect($box)->not->toBeNull()
        ->and($box->xIn(856))->toBe(86)
        ->and($box->yIn(540))->toBe(65);
});

it('positions the issue and expiry dates from the template', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Dates moved', 'code' => 'dates-moved', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['back' => ['dates' => ['x' => 50.0, 'y' => 40.0, 'w' => 40.0, 'h' => 10.0]]],
    ]);

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // 50% of 856 is 428; 40% of 540 is 216.
    expect($svg)->toContain('x="428" y="216"');
});

it('prints no scan instructions or QR disclaimer on the card', function (): void {
    $card = frontCard();
    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    expect($svg)->not->toContain('SCAN TO VERIFY')
        ->and($svg)->not->toContain('QR contains no personal info')
        ->and($svg)->not->toContain('ለማረጋገጥ ስካን ያድርጉ');
});

it('prints the emergency contact on the back where the template puts it', function (): void {
    $card = frontCard();
    $card->employee->forceFill([
        'emergency_contact_name' => 'Almaz Bekele',
        'emergency_contact_phone' => '+251911999888',
    ])->save();

    IdCardTemplate::query()->create([
        'name' => 'Emergency placed', 'code' => 'emergency-placed', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['back' => ['emergency' => ['x' => 30.0, 'y' => 50.0, 'w' => 25.0, 'h' => 15.0]]],
    ]);

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // 30% of 856 is 257; 50% of 540 is 270.
    expect($svg)->toContain('Almaz Bekele')
        ->and($svg)->toContain('+251911999888')
        ->and($svg)->toContain('x="257" y="270"');
});

it('omits the emergency block when the employee has no contact', function (): void {
    $card = frontCard();
    $card->employee->forceFill([
        'emergency_contact_name' => null,
        'emergency_contact_phone' => null,
    ])->save();

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    expect($svg)->not->toContain('Emergency Contact');
});
