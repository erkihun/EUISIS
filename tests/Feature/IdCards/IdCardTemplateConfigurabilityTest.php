<?php

declare(strict_types=1);

use App\Models\IdCardTemplate;
use App\Models\User;
use App\Services\IdCards\IdCardLayoutElement;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\IdCardTemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/IdCardFrontHelpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    $abilities = ['view', 'create', 'update', 'delete', 'set_default'];
    foreach ($abilities as $ability) {
        Permission::findOrCreate('id_card_templates.'.$ability, 'web');
    }
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(array_map(fn (string $ability): string => 'id_card_templates.'.$ability, $abilities));
    $this->actingAs($this->admin);
});

function configurableTemplatePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Configurable', 'code' => 'configurable', 'description' => null,
        'orientation' => 'landscape', 'width_mm' => 85.6, 'height_mm' => 54,
        'status' => 'active', 'is_default' => true,
    ], $overrides);
}

it('prints the front rows in the order the template lists them', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create(configurableTemplatePayload([
        'employee_fields' => ['phone', 'idNumber', 'name', 'nationality'],
    ]));

    $keys = array_column(app(IdCardRenderDataFactory::class)->make($card->fresh())->bilingualFields, 4);

    expect($keys)->toBe(['phone', 'idNumber', 'name', 'nationality']);
});

it('keeps the built-in row order for a template that stores no field list', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create(configurableTemplatePayload(['employee_fields' => null]));

    $keys = array_column(app(IdCardRenderDataFactory::class)->make($card->fresh())->bilingualFields, 4);

    expect($keys)->toBe(['name', 'sex', 'dob', 'nationality', 'employment', 'phone', 'idNumber']);
});

it('saves the field order exactly as submitted', function (): void {
    $this->post(route('id-card-templates.store'), configurableTemplatePayload([
        'employee_fields' => ['idNumber', 'name', 'dob'],
    ]))->assertSessionHasNoErrors();

    expect(IdCardTemplate::query()->sole()->employee_fields)->toBe(['idNumber', 'name', 'dob']);
});

it('rejects a template that prints no employee fields', function (): void {
    $this->post(route('id-card-templates.store'), configurableTemplatePayload(['employee_fields' => []]))
        ->assertSessionHasErrors('employee_fields');
});

it('duplicates a template as an inactive copy that owns its artwork', function (): void {
    $this->post(route('id-card-templates.store'), configurableTemplatePayload([
        'employee_fields' => ['name', 'idNumber'],
        'front_background' => UploadedFile::fake()->image('front.png', 856, 540),
        'seal' => UploadedFile::fake()->image('seal.png', 200, 200),
        'layout_config' => ['front' => ['photo' => ['x' => 70.0, 'y' => 20.0, 'w' => 20.0, 'h' => 40.0]]],
    ]))->assertSessionHasNoErrors();
    $source = IdCardTemplate::query()->sole();

    $this->post(route('id-card-templates.duplicate', $source))->assertRedirect()->assertSessionHasNoErrors();

    $copy = IdCardTemplate::query()->whereKeyNot($source->id)->sole();
    expect($copy->name)->toBe('Configurable (copy)')
        ->and($copy->code)->toBe('configurable-copy')
        ->and($copy->status)->toBe('inactive')
        ->and($copy->is_default)->toBeFalse()
        ->and($copy->employee_fields)->toBe(['name', 'idNumber'])
        ->and((float) $copy->layout_config['front']['photo']['x'])->toBe(70.0)
        ->and($copy->created_by)->toBe($this->admin->id)
        // Own files, so deleting either template's artwork leaves the other's.
        ->and($copy->front_background_path)->not->toBe($source->front_background_path)
        ->and($copy->seal_path)->not->toBe($source->seal_path)
        ->and($copy->back_background_path)->toBeNull()
        ->and($source->fresh()->is_default)->toBeTrue();
    Storage::disk('local')->assertExists([$copy->front_background_path, $copy->seal_path]);
});

it('numbers repeated copies so every code stays unique', function (): void {
    $source = IdCardTemplate::query()->create(configurableTemplatePayload());

    $this->post(route('id-card-templates.duplicate', $source))->assertSessionHasNoErrors();
    $this->post(route('id-card-templates.duplicate', $source))->assertSessionHasNoErrors();

    expect(IdCardTemplate::query()->orderBy('code')->pluck('code')->all())
        ->toBe(['configurable', 'configurable-copy', 'configurable-copy-2']);
});

it('gives every printed field its own movable box on the front', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create(configurableTemplatePayload([
        'employee_fields' => ['name', 'phone', 'idNumber'],
        'layout_config' => ['front' => [
            'field_phone' => ['x' => 50.0, 'y' => 60.0, 'w' => 40.0, 'h' => 10.0],
        ]],
    ]));

    $svg = renderFrontSvg($card);

    // 50% of 856 is 428; 60% of 540 is 324, and the label hangs one line (16) lower.
    expect($svg)->toMatch('/<text x="428" y="340"[^>]*>ስ\.ቁ \/ Phone No\.</u')
        // Unmoved fields keep their default box: name at 28% / 22%.
        ->and($svg)->toMatch('/<text x="240" y="135"[^>]*>ስም</u');
});

it('saves a box per field through the editor and serves each one back', function (): void {
    $this->post(route('id-card-templates.store'), configurableTemplatePayload([
        'layout_config' => [
            'front' => ['field_dob' => ['x' => 5.0, 'y' => 80.0, 'w' => 30.0, 'h' => 8.0]],
            'back' => ['field_emergency_contact_phone' => ['x' => 2.0, 'y' => 60.0, 'w' => 40.0, 'h' => 15.0]],
        ],
    ]))->assertSessionHasNoErrors();

    $layout = app(IdCardTemplateService::class)->presentation(IdCardTemplate::query()->sole())['layout_config'];

    expect($layout['front']['field_dob'])->toBe(['x' => 5.0, 'y' => 80.0, 'w' => 30.0, 'h' => 8.0])
        ->and($layout['back']['field_emergency_contact_phone'])->toBe(['x' => 2.0, 'y' => 60.0, 'w' => 40.0, 'h' => 15.0])
        ->and($layout['front']['field_name'])->toBe(IdCardLayoutElement::ELEMENTS['front']['field_name']);
});

it('lays the fields of an older template out inside the block it stored', function (): void {
    // Saved before fields had their own boxes: only the shared containers.
    $template = IdCardTemplate::query()->create(configurableTemplatePayload([
        'employee_fields' => ['sex', 'name', 'idNumber'],
        'layout_config' => [
            'front' => [
                'fields' => ['x' => 10.0, 'y' => 30.0, 'w' => 60.0, 'h' => 40.0],
                'emphasis' => ['x' => 5.0, 'y' => 85.0, 'w' => 50.0, 'h' => 10.0],
            ],
            'back' => ['emergency' => ['x' => 20.0, 'y' => 40.0, 'w' => 50.0, 'h' => 30.0]],
        ],
    ]));
    $service = app(IdCardTemplateService::class);

    // Two columns of (60 - 1.5) / 2 in the stored block, in the template's order.
    expect($service->box($template, 'front', 'field_sex')->toArray())->toBe(['x' => 10.0, 'y' => 30.0, 'w' => 29.25, 'h' => 10.0])
        ->and($service->box($template, 'front', 'field_name')->toArray())->toBe(['x' => 40.75, 'y' => 30.0, 'w' => 29.25, 'h' => 10.0])
        ->and($service->box($template, 'front', 'field_idNumber')->toArray())->toBe(['x' => 5.0, 'y' => 85.0, 'w' => 50.0, 'h' => 10.0])
        ->and($service->box($template, 'back', 'field_emergency_contact_name')->toArray())->toBe(['x' => 20.0, 'y' => 40.0, 'w' => 50.0, 'h' => 15.0])
        ->and($service->box($template, 'back', 'field_emergency_contact_phone')->toArray())->toBe(['x' => 20.0, 'y' => 55.0, 'w' => 50.0, 'h' => 15.0]);
});

it('derives exactly the built-in field boxes when nothing is stored', function (): void {
    $service = app(IdCardTemplateService::class);

    foreach (IdCardLayoutElement::ELEMENTS as $side => $elements) {
        foreach ($elements as $element => $default) {
            if (str_starts_with($element, 'field_')) {
                expect([$side.'.'.$element => $service->box(null, $side, $element)->toArray()])
                    ->toBe([$side.'.'.$element => $default]);
            }
        }
    }
});

it('places each emergency contact on the back by its own box', function (): void {
    $card = frontCard();
    $card->employee->forceFill(['emergency_contact_name' => 'Almaz Bekele', 'emergency_contact_phone' => '+251911999888'])->save();
    IdCardTemplate::query()->create(configurableTemplatePayload([
        'layout_config' => ['back' => [
            'field_emergency_contact_phone' => ['x' => 10.0, 'y' => 70.0, 'w' => 40.0, 'h' => 15.0],
        ]],
    ]));

    $svg = app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    // The phone moved to 10% / 70% (86, 378); the name stayed in its default box.
    expect($svg)->toMatch('/<text x="86" y="3[89]\d"[^>]*>የአስቸኳይ ጊዜ ተጠሪ ስልክ</u')
        ->and($svg)->toMatch('/<text x="355" y="\d+"[^>]*>የአስቸኳይ ጊዜ ተጠሪ ስም</u');
});

it('prints the email once under a shared label, shrunk to fit rather than cut', function (): void {
    $card = frontCard();
    $email = 'firstname.middlename.lastname@addisababa.gov.et';
    $card->employee->forceFill(['email' => $email, 'address' => 'Bole, Woreda 03, Addis Ababa'])->save();
    IdCardTemplate::query()->create(configurableTemplatePayload(['employee_fields' => ['name', 'email', 'address']]));

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    $svg = app(IdCardSvgRenderer::class)->renderFront($data);
    $rows = collect($data->bilingualFields)->keyBy(4);

    // The card's own captions, as the preview shows them.
    expect($rows['email'][0])->toBe('ኢሜይል')
        ->and($rows['email'][2])->toBe('Email')
        ->and($rows['address'][2])->toBe('Address')
        // One shared label line and one value, like the phone number.
        ->and($svg)->toContain('ኢሜይል / Email')
        ->and($svg)->toContain('አድራሻ / Address')
        ->and($svg)->not->toContain('Personal email')
        // Too long for half a card even shrunk, so it breaks before the "@"
        // onto a second line — whole, once, never cut with "…".
        ->and(substr_count($svg, '>firstname.middlename.lastname<'))->toBe(1)
        ->and(substr_count($svg, '>@addisababa.gov.et<'))->toBe(1)
        ->and($svg)->not->toContain('…');
    preg_match('/font-size="([\d.]+)"[^>]*>firstname\.middlename\.lastname</', $svg, $size);
    // Shrunk to the smallest legible size first (70% of the 20-unit value).
    expect((float) $size[1])->toBe(14.0);
});

it('keeps a short email whole on one line at full size', function (): void {
    $card = frontCard();
    $card->employee->forceFill(['email' => 'a.b@aa.gov.et'])->save();
    IdCardTemplate::query()->create(configurableTemplatePayload(['employee_fields' => ['email']]));

    $svg = app(IdCardSvgRenderer::class)->renderFront(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    expect(substr_count($svg, '>a.b@aa.gov.et<'))->toBe(1)
        ->and(IdCardSvgRenderer::fitLines('one.two@three.gov.et', 14, true))->toBe(['one.two', '@three.gov.et'])
        ->and(IdCardSvgRenderer::fitLines('Bole, Woreda 03, Addis Ababa', 16, false))->toBe(['Bole, Woreda 03,', 'Addis Ababa']);
});

it('prints a portrait card the fields its template lists, each in its own box', function (): void {
    $card = frontCard();
    $card->employee->forceFill(['emergency_contact_name' => 'Almaz Bekele', 'emergency_contact_phone' => '+251911999888'])->save();
    IdCardTemplate::query()->create(configurableTemplatePayload([
        'orientation' => 'portrait', 'width_mm' => 54, 'height_mm' => 85.6,
        'employee_fields' => ['name', 'sex', 'phone', 'idNumber', 'emergency_contact_name'],
        'layout_config' => ['front' => ['field_phone' => ['x' => 10.0, 'y' => 50.0, 'w' => 80.0, 'h' => 6.0]]],
    ]));

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh(), 'portrait');
    $front = app(IdCardSvgRenderer::class)->renderFront($data);
    $back = app(IdCardSvgRenderer::class)->renderBack($data);

    // The phone moved to 10% / 50% of the 540 x 856 portrait canvas (54, 428).
    expect($front)->toMatch('/<text x="54" y="444"[^>]*>ስ\.ቁ \/ Phone No\.</u')
        // Sex keeps its portrait default: a full-width row at 4% / 24%.
        ->and($front)->toMatch('/<text x="22" y="221"[^>]*>ጾታ</u')
        ->and($front)->toContain('Front Employee')
        ->and($front)->toContain('EMP-FRONT-1')
        // Unlisted fields stay off the card.
        ->and($front)->not->toContain('Date of Birth')
        ->and($front)->not->toContain('Nationality')
        ->and($back)->toContain('Almaz Bekele')
        ->and($back)->not->toContain('+251911999888');
});

it('leaves the portrait name and ID number off when the template unticks them', function (): void {
    $card = frontCard();
    IdCardTemplate::query()->create(configurableTemplatePayload([
        'orientation' => 'portrait', 'width_mm' => 54, 'height_mm' => 85.6, 'employee_fields' => ['sex'],
    ]));

    $front = app(IdCardSvgRenderer::class)->renderFront(app(IdCardRenderDataFactory::class)->make($card->fresh(), 'portrait'));

    expect($front)->not->toContain('Front Employee')
        ->and($front)->not->toContain('EMP-FRONT-1')
        ->and($front)->toContain('ጾታ');
});

it('keeps the portrait field defaults in step between php and the client', function (): void {
    $tsx = file_get_contents(base_path('resources/js/Components/IdCards/IdCardTemplateContext.tsx'));
    $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

    foreach (IdCardLayoutElement::PORTRAIT_FIELDS as $side => $elements) {
        foreach ($elements as $element => $box) {
            $expected = sprintf('%s: { x: %s, y: %s, w: %s, h: %s }', $element, $number($box['x']), $number($box['y']), $number($box['w']), $number($box['h']));
            expect([$side.'.'.$element => str_contains($tsx, $expected)])->toBe([$side.'.'.$element => true]);
        }
    }

    // And the portrait organization name prints on the card, with no band behind it.
    expect(file_get_contents(base_path('resources/js/Components/IdCards/IdCardPortraitFront.tsx')))->not->toContain('bg-slate-900/4');
});

it('needs the create permission to duplicate', function (): void {
    $source = IdCardTemplate::query()->create(configurableTemplatePayload());
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('id_card_templates.view');
    $this->actingAs($viewer);

    $this->post(route('id-card-templates.duplicate', $source))->assertForbidden();
    expect(IdCardTemplate::query()->count())->toBe(1);
});
