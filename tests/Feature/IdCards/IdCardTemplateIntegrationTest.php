<?php

declare(strict_types=1);

use App\Models\IdCardTemplate;
use App\Models\User;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\IdCardTemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/IdCardFrontFieldsTest.php';

/**
 * End-to-end checks that an uploaded template actually reaches every consumer:
 * the shared Inertia prop, the artwork route, and the export renderer — for a
 * plain card viewer, not only for template administrators.
 */
function publishedTemplate(): IdCardTemplate
{
    Storage::fake('local');

    $template = IdCardTemplate::query()->create([
        'name' => 'Published', 'code' => 'published', 'orientation' => 'landscape',
        'status' => 'active', 'is_default' => true,
        'text_style_config' => [
            'front' => [
                'label' => ['color' => '#053A7A', 'font_size' => '8px', 'font_weight' => '400'],
                'value' => ['color' => '#FD7E09', 'font_size' => '11px', 'font_weight' => '600'],
            ],
        ],
    ]);

    foreach (['front', 'back'] as $side) {
        $path = 'id-card-templates/'.$template->id.'-'.$side.'.png';
        Storage::disk('local')->put($path, UploadedFile::fake()->image($side.'.png', 856, 540)->get());
        $template->forceFill([$side.'_background_path' => $path])->save();
    }

    return $template->refresh();
}

function cardViewer(): User
{
    foreach (['cards.view', 'id-cards.previewSvg'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    // Deliberately no id_card_templates.* permission — a normal card consumer.
    $user->givePermissionTo(['cards.view', 'id-cards.previewSvg']);

    return $user;
}

it('shares the active template with every authenticated page', function (): void {
    $template = publishedTemplate();
    $expected = app(IdCardTemplateService::class)->presentation($template);

    $this->actingAs(cardViewer())
        ->get(route('id-cards.preview', frontCard()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('idCardTemplate.front_background_url', $expected['front_background_url'])
            ->where('idCardTemplate.back_background_url', $expected['back_background_url'])
            ->where('idCardTemplate.orientation', 'landscape')
            ->has('idCardTemplate.text_style_config.front.value'));
});

it('serves the active artwork to a viewer without template permissions', function (string $side): void {
    $template = publishedTemplate();

    $this->actingAs(cardViewer())
        ->get(route('id-card-templates.background', [$template, $side]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
})->with(['front', 'back']);

it('embeds the artwork and the configured styles in the export', function (): void {
    publishedTemplate();
    $card = frontCard();

    $svg = app(IdCardSvgRenderer::class)->renderFront(
        app(IdCardRenderDataFactory::class)->make($card->fresh()),
    );

    // Artwork is inlined, and the saved colours drive the text.
    expect($svg)->toContain('id="frontBackground"')
        ->toContain('data:image/png;base64,')
        ->toContain('#053A7A')
        ->toContain('#FD7E09');
});

it('falls back to the neutral surface when no template is published', function (): void {
    IdCardTemplate::query()->delete();
    $card = frontCard();

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    $svg = app(IdCardSvgRenderer::class)->renderFront($data);

    expect($data->frontBackgroundDataUri)->toBeNull()
        ->and($svg)->toContain('#0F172A')
        ->and($svg)->not->toContain('id="frontBackground"');
});

it('never exposes the private storage path to the client', function (): void {
    $template = publishedTemplate();
    $presentation = app(IdCardTemplateService::class)->presentation($template);

    expect($presentation)->not->toHaveKey('front_background_path')
        ->and(json_encode($presentation))->not->toContain('id-card-templates/');
});
