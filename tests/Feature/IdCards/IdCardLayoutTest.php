<?php

declare(strict_types=1);

use App\Models\IdCardTemplate;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardLayoutElement;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\IdCardTemplateService;
use App\Services\IdCards\IdCardTextStyle;
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
        'dates' => ['x' => 0.0, 'y' => 80.0, 'w' => 100.0, 'h' => 6.0],
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
        ->and($layout['dates'])->toBe(IdCardLayoutElement::ELEMENTS['front']['dates']);
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
    'card_number' => ['card_number', ['x' => 40.0, 'y' => 60.0, 'w' => 35.0, 'h' => 7.0], 'x="342"'],
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
        'layout_config' => ['front' => ['dates' => ['x' => 50.0, 'y' => 40.0, 'w' => 40.0, 'h' => 10.0]]],
    ]);

    $svg = app(IdCardSvgRenderer::class)->renderFront(app(IdCardRenderDataFactory::class)->make($card->fresh()));

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

it('styles every back text element from the template', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Back styled', 'code' => 'back-styled', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'text_style_config' => ['back' => [
            'label' => ['color' => '#22BB22', 'font_size' => '8px', 'font_weight' => '500'],
            'value' => ['color' => '#3333CC', 'font_size' => '11px', 'font_weight' => '700'],
            'footer' => ['color' => '#DD8800', 'font_size' => '7px', 'font_weight' => '400'],
        ]],
    ]);

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // Every role reaches the card: colour, size (doubled for the canvas), weight.
    expect($svg)->toContain('#22BB22')->toContain('font-size="16"')
        ->and($svg)->toContain('#3333CC')->toContain('font-size="22"')->toContain('font-weight="700"')
        ->and($svg)->toContain('#DD8800')->toContain('font-size="14"');
});

it('prints emergency contact as labelled stacked fields like the front', function (): void {
    $card = frontCard();
    $card->employee->forceFill([
        'emergency_contact_name' => 'Alemu Bekele',
        'emergency_contact_phone' => '+251911222333',
    ])->save();

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // Each field carries its own bilingual label, Amharic row above English.
    foreach ([
        'የአስቸኳይ ጊዜ ተጠሪ ስም', 'Emergency Contact Name',
        'የአስቸኳይ ጊዜ ተጠሪ ስልክ', 'Emergency Contact Phone',
        'Alemu Bekele', '+251911222333',
    ] as $needle) {
        expect($svg)->toContain($needle);
    }

    // The two blocks must not collide.
    preg_match('/<text x="\d+" y="(\d+)"[^>]*>የአስቸኳይ ጊዜ ተጠሪ ስም</u', $svg, $name);
    preg_match('/<text x="\d+" y="(\d+)"[^>]*>የአስቸኳይ ጊዜ ተጠሪ ስልክ</u', $svg, $phone);
    expect((int) $phone[1])->toBeGreaterThan((int) $name[1] + 60);
});

it('gives the card number and signature their own sections with dates in both calendars', function (): void {
    $card = frontCard();
    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // Card number and signature carry bilingual captions of their own.
    expect($svg)->toContain('የካርድ ቁጥር/Card No')
        ->and($svg)->toContain('ፊርማ/Signature');

    // Dates moved to the front, so the back no longer prints them.
    $front = app(IdCardSvgRenderer::class)->renderFront(app(IdCardRenderDataFactory::class)->make($card->fresh()));
    expect($front)->toContain('የስጦታ ቀን/Issue Date')
        ->and($front)->toContain('ያልቃ/Exp')
        ->and($front)->not->toContain('ISSUE DATE')
        ->and($svg)->not->toContain('የስጦታ ቀን/Issue Date');

    // The return address wraps rather than being cut off mid-word.
    expect($svg)->not->toContain('Public Service &amp; H<');
});

it('gives the header band room for its own lines and keeps the body clear of it', function (): void {
    $header = IdCardLayoutElement::ELEMENTS['front']['header'];
    $photo = IdCardLayoutElement::ELEMENTS['front']['photo'];
    $fields = IdCardLayoutElement::ELEMENTS['front']['fields'];

    // The band holds the city name in both languages, so the elements beneath
    // it must start below where it ends.
    $headerBottom = $header['y'] + $header['h'];
    expect($photo['y'])->toBeGreaterThanOrEqual($headerBottom)
        ->and($fields['y'])->toBeGreaterThanOrEqual($headerBottom);
});

it('positions each header logo independently of the other', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create([
        'name' => 'Split logos', 'code' => 'split-logos', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'layout_config' => ['front' => [
            'logo_primary' => ['x' => 40.0, 'y' => 1.1, 'w' => 6.5, 'h' => 6.7],
        ]],
    ]);

    $svg = renderFrontSvg($card->fresh());

    // Each mark draws its own circle; moving the left one must leave the right
    // where the built-in arrangement puts it.
    $secondaryDefault = IdCardLayoutElement::ELEMENTS['front']['logo_secondary'];
    $expectedSecondaryCx = (int) round(856 * $secondaryDefault['x'] / 100)
        + (int) round(min(856 * $secondaryDefault['w'] / 100, 540 * $secondaryDefault['h'] / 100) / 2);

    // 40% of the 856 canvas is 342, plus the radius.
    expect($svg)->toContain('cx="'.$expectedSecondaryCx.'"')
        ->and($svg)->toContain('cx="360"');
});

it('keeps the whole back face inside the card', function (): void {
    $card = frontCard();
    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card));

    // The renderer clips silently at the card edge, so an element that runs off
    // the bottom simply disappears rather than reporting an error.
    preg_match_all('/<text x="-?\d+" y="(\d+)"/', $svg, $matches);
    $maxY = max(array_map('intval', $matches[1]));

    expect($maxY)->toBeLessThanOrEqual(540);
});

it('does not render the removed card detail block', function (): void {
    $card = frontCard();
    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card));

    expect(IdCardLayoutElement::ELEMENTS['back'])->not->toHaveKey('details')
        ->and($svg)->not->toContain('Public Service &amp; HRD Bureau');
});

it('does not print an official identification heading on either back layout', function (string $orientation): void {
    $card = frontCard();
    $svg = app(IdCardSvgRenderer::class)->renderBack(
        app(IdCardRenderDataFactory::class)->make($card, $orientation),
    );

    // The notes lead the back's text column; there is no heading above them.
    expect($svg)->not->toContain('OFFICIAL')
        ->and(IdCardTextStyle::ROLES['back'])->not->toHaveKey('header');
})->with(['landscape', 'portrait']);

it('keeps the php and client layout defaults in step', function (): void {
    // The two renderers must agree on the built-in arrangement; a value changed
    // in one file and not the other is the drift this guards against.
    $tsx = file_get_contents(base_path('resources/js/Components/IdCards/IdCardTemplateContext.tsx'));

    foreach (IdCardLayoutElement::ELEMENTS as $side => $elements) {
        foreach ($elements as $element => $box) {
            $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            $expected = sprintf(
                '%s: { x: %s, y: %s, w: %s, h: %s }',
                $element,
                $number($box['x']), $number($box['y']), $number($box['w']), $number($box['h']),
            );

            // A failure here names the element whose TSX value drifted.
            expect([$side.'.'.$element => str_contains($tsx, $expected)])
                ->toBe([$side.'.'.$element => true]);
        }
    }
});
