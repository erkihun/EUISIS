<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Letters\Application\Services\LetterheadResolver;
use Modules\Letters\Application\Services\LetterLayout;
use Modules\Letters\Domain\Enums\LetterStatus;
use Modules\Letters\Domain\Models\LetterLetterhead;
use Tests\Support\LettersScenario;

/*
 * Header and footer layouts (ADR 0037 §13): several per institution, one the
 * default, platform layouts for institutions without their own, the content,
 * fonts, colours and alignment composed row by row and printed as composed.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->s = LettersScenario::build();
});

// A 1×1 PNG, enough for the type sniffer.
function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
}

/** @param array<string, mixed> $style */
function lhText(string $text, array $style = []): array
{
    return ['type' => 'text', 'text' => $text, 'language' => 'both', 'font_family' => '', 'font_size' => 10, 'bold' => false, 'underline' => false, 'color' => '#111111', ...$style];
}

/** A layout document as the editor sends it. */
function lhLayout(array $headerItems, array $footerItems, array $page = []): string
{
    $row = fn (array $items, string $border = 'none') => ['cells' => [['items' => $items, 'align' => 'center', 'valign' => 'middle', 'width' => 0]], 'border_position' => $border, 'border_style' => 'thick', 'border_color' => '#122170', 'space_after' => 3];

    return json_encode([
        'page' => ['paper' => 'a4', 'orientation' => 'portrait', 'margin_top' => 18, 'margin_right' => 18, 'margin_bottom' => 30, 'margin_left' => 18, 'font_family' => 'abyssinica', 'font_size' => 11.5, 'text_color' => '#111111', 'reference_align' => 'left', ...$page],
        'header' => ['rows' => [$row($headerItems, 'bottom')]],
        'footer' => ['rows' => [$row($footerItems, 'top')]],
    ], JSON_UNESCAPED_UNICODE);
}

/** @param array<string, string> $vars */
function renderLetterPdfHtml(LettersScenario $s, ?string $layoutId, string $language, string $status): string
{
    $letter = $s->outgoing('A', 'B', null, ['language' => $language]);
    $letter->letterhead_id = $layoutId;
    $letter->status = LetterStatus::from($status);
    $resolver = app(LetterheadResolver::class);

    return view('letters.pdf', [
        'letter' => $letter->load(['organization', 'registrations', 'attachments', 'copies']), 'locale' => $language, 'body' => '<p>x</p>', 'subject' => 's',
        'context' => ['letter.number' => 'LT-A/0007/2019', 'letter.date_ec' => '01/03/2019', 'letter.date_gc' => '10/11/2026', 'signatory.name' => '', 'today.date_ec' => '01/03/2019'],
        'qr' => null, 'verifyUrl' => null, 'letterhead' => $resolver->resolve($resolver->pick($letter->organization_id, $layoutId), $letter->organization),
        'fontCss' => '',
    ])->render();
}

it('keeps several layouts per institution, lists them with the default first, and moves the default', function (): void {
    $admin = $this->s->administrator('A');
    $org = $this->s->org['A']->id;

    $this->actingAs($admin)->post(route('letters.letterheads.store'), [
        'organization_id' => $org, 'name' => 'Standard', 'active' => 1,
        'layout' => lhLayout([['type' => 'image', 'image' => 'img_seal', 'width_mm' => 20, 'language' => 'both'], lhText('{{institution.name_am}}', ['font_size' => 15, 'bold' => true, 'color' => '#122170'])], [lhText('{{institution.name}} · {{page}}')]),
        'new_images' => ['img_seal' => UploadedFile::fake()->createWithContent('seal.png', tinyPng())],
        'image_names' => ['img_seal' => 'City seal'],
        'stamp' => UploadedFile::fake()->createWithContent('stamp.png', tinyPng()),
    ])->assertSessionHasNoErrors();
    $first = LetterLetterhead::query()->where('name', 'Standard')->firstOrFail();
    expect($first->is_default)->toBeTrue() // the institution's first layout becomes its default
        ->and($first->imageKeys())->toBe(['img_seal'])->and(Storage::disk('local')->exists($first->imageList()[0]['path']))->toBeTrue()
        ->and($first->stamp_path)->not->toBeNull()
        ->and($first->header['rows'][0]['cells'][0]['items'][1]['font_size'])->toEqual(15.0)
        ->and($first->header['rows'][0]['border_position'])->toBe('bottom');

    $this->actingAs($admin)->post(route('letters.letterheads.store'), [
        'organization_id' => $org, 'name' => 'Bilingual memo', 'is_default' => 1, 'active' => 1, 'layout' => lhLayout([lhText('{{institution.name_en}}')], []),
    ])->assertSessionHasNoErrors();
    expect($first->fresh()->is_default)->toBeFalse()->and(LetterLetterhead::query()->where('name', 'Bilingual memo')->value('is_default'))->toBeTrue();

    $this->actingAs($admin)->get(route('letters.letterheads.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Letterheads/Index', false)->where('selected_organization_id', $org)->has('layouts', 2)
            ->where('layouts.0.name', 'Bilingual memo')->where('layouts.0.is_default', true)->where('layouts.1.has_stamp', true)->where('layouts.1.images', 1));
    $this->actingAs($admin)->get(route('letters.letterheads.edit', $first))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Letters/Letterheads/Form', false)->where('layout.name', 'Standard')->where('layout.images.0.name', 'City seal')
            ->where('layout.header.rows.0.cells.0.items.1.text', '{{institution.name_am}}')->has('previews.img_seal')->has('previews.stamp')->has('options.placeholders'));
    $this->actingAs($admin)->get(route('letters.letterheads.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('layout.id', null)->where('layout.is_default', false)->has('layout.header.rows', 1));

    // Make default, duplicate (files copied), update with an image removed.
    $this->actingAs($admin)->post(route('letters.letterheads.default', $first))->assertSessionHasNoErrors();
    expect($first->fresh()->is_default)->toBeTrue();
    $this->actingAs($admin)->post(route('letters.letterheads.duplicate', $first))->assertRedirect();
    $copy = LetterLetterhead::query()->where('name', 'Standard (copy)')->firstOrFail();
    expect($copy->is_default)->toBeFalse()->and($copy->imageList()[0]['path'])->not->toBe($first->imageList()[0]['path'])
        ->and(Storage::disk('local')->exists($copy->imageList()[0]['path']))->toBeTrue();

    $path = $first->imageList()[0]['path'];
    $this->actingAs($admin)->post(route('letters.letterheads.upload', $first), [
        '_method' => 'put', 'name' => 'Standard', 'is_default' => 1, 'active' => 1, 'remove_images' => ['img_seal'],
        'layout' => lhLayout([['type' => 'image', 'image' => 'img_seal', 'width_mm' => 20, 'language' => 'both'], lhText('{{institution.name_am}}')], []),
    ])->assertSessionHasNoErrors();
    expect(Storage::disk('local')->exists($path))->toBeFalse()->and($first->fresh()->imageKeys())->toBe([])
        ->and($first->fresh()->header['rows'][0]['cells'][0]['items'])->toHaveCount(1); // the image item pointing at nothing is dropped
});

it('prints the chosen layout as composed: placeholders, styles, language-only lines, page numbers and the stamp on dispatched letters only', function (): void {
    $admin = $this->s->administrator('A');
    $org = $this->s->org['A']->id;
    $this->actingAs($admin)->post(route('letters.letterheads.store'), [
        'organization_id' => $org, 'name' => 'Standard', 'active' => 1,
        'layout' => lhLayout([
            lhText('{{institution.name}}', ['font_size' => 15, 'bold' => true, 'color' => '#AA0000', 'font_family' => 'noto_sans', 'underline' => true]),
            lhText('የአዲስ አበባ ከተማ አስተዳደር', ['language' => 'am']),
            lhText('Addis Ababa City Administration', ['language' => 'en']),
            lhText('{{unit.name}}'),
        ], [lhText('Ref {{letter.number}} · page {{page}}', ['font_size' => 8])], ['reference_align' => 'right', 'paper' => 'a5']),
        'stamp' => UploadedFile::fake()->createWithContent('stamp.png', tinyPng()),
    ])->assertSessionHasNoErrors();
    $standard = LetterLetterhead::query()->where('name', 'Standard')->firstOrFail();
    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => $org, 'name' => 'Plain', 'active' => 1, 'layout' => lhLayout([lhText('PLAIN HEADER')], [])])->assertSessionHasNoErrors();
    $plain = LetterLetterhead::query()->where('name', 'Plain')->firstOrFail();

    $en = renderLetterPdfHtml($this->s, null, 'en', 'draft');
    expect($en)->toContain('Trade Bureau')->toContain('font-size: 15pt')->toContain('color: #AA0000')->toContain('font-weight: bold')->toContain('text-decoration: underline')
        ->toContain('Noto Sans Ethiopic')->toContain('Addis Ababa City Administration')->not->toContain('የአዲስ አበባ ከተማ አስተዳደር')
        ->toContain('Ref LT-A/0007/2019 · page <span class="lh-page"></span>')
        ->toContain('margin-left: 45%')->not->toContain('class="stamp"><img')
        ->and(substr_count($en, '<div style="'))->toBeGreaterThanOrEqual(3);
    expect(renderLetterPdfHtml($this->s, null, 'am', 'draft'))->toContain('የአዲስ አበባ ከተማ አስተዳደር')->not->toContain('Addis Ababa City Administration');
    expect(renderLetterPdfHtml($this->s, null, 'en', 'dispatched'))->toContain('class="stamp"><img');
    expect(renderLetterPdfHtml($this->s, $plain->id, 'en', 'draft'))->toContain('PLAIN HEADER')->not->toContain('Ref LT-A');

    $resolver = app(LetterheadResolver::class);
    expect($resolver->for($this->s->org['A'])['page']['paper'])->toBe('a5')
        ->and($resolver->for($this->s->org['A'], $plain->id)['id'])->toBe($plain->id)
        ->and($resolver->for($this->s->org['A'], $standard->id)['stamp'])->toStartWith('data:image/png');

    // An inactive layout is never chosen; another institution's is never chosen.
    $plain->forceFill(['active' => false])->save();
    expect($resolver->for($this->s->org['A'], $plain->id)['id'])->toBe($standard->id)
        ->and($resolver->for($this->s->org['B'], $standard->id)['source'])->toBe('default');
});

it('falls back to the platform layouts, then to the built-in one', function (): void {
    expect(app(LetterheadResolver::class)->for($this->s->org['B'])['source'])->toBe('default')
        ->and(app(LetterheadResolver::class)->for($this->s->org['B'])['header']['rows'])->toEqual(LetterLayout::defaults()['header']['rows']);

    $this->actingAs($this->s->steward())->post(route('letters.letterheads.store'), ['organization_id' => '', 'name' => 'City', 'active' => 1, 'layout' => lhLayout([lhText('CITY HEADER')], [])])->assertSessionHasNoErrors();
    $resolved = app(LetterheadResolver::class)->for($this->s->org['B']);
    expect($resolved['source'])->toBe('platform')->and($resolved['header']['rows'][0]['cells'][0]['items'][0]['text'])->toBe('CITY HEADER')
        ->and(app(LetterheadResolver::class)->options($this->s->org['B']->id))->toHaveCount(1);

    $this->actingAs($this->s->administrator('B'))->get(route('letters.letterheads.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('layouts', [])->where('inherits_platform', true));
});

it('refuses unknown placeholders, bad images, other institutions and platform layouts without city-wide reach', function (): void {
    $admin = $this->s->administrator('A');
    $org = $this->s->org['A']->id;

    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => $org, 'name' => 'X', 'layout' => lhLayout([lhText('{{secret.salary}}')], [])])
        ->assertSessionHasErrors('layout');
    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => $org, 'name' => 'X', 'layout' => 'not json'])->assertSessionHasErrors('layout');
    $this->actingAs($admin)->post(route('letters.letterheads.store'), [
        'organization_id' => $org, 'name' => 'X', 'layout' => lhLayout([], []), 'new_images' => ['img_x' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1;')],
    ])->assertSessionHasErrors('new_images');
    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => $this->s->org['C']->id, 'name' => 'X', 'layout' => lhLayout([], [])])->assertForbidden();
    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => '', 'name' => 'X', 'layout' => lhLayout([], [])])->assertForbidden();
    $this->actingAs($this->s->officer('A'))->get(route('letters.letterheads.index'))->assertForbidden();
    expect(LetterLetterhead::query()->count())->toBe(0);

    $this->actingAs($this->s->administrator('C'))->post(route('letters.letterheads.store'), ['organization_id' => $this->s->org['C']->id, 'name' => 'C', 'layout' => lhLayout([], [])])->assertSessionHasNoErrors();
    $layoutC = LetterLetterhead::query()->firstOrFail();
    $this->actingAs($admin)->get(route('letters.letterheads.edit', $layoutC))->assertForbidden();
    $this->actingAs($admin)->get(route('letters.letterheads.preview', $layoutC))->assertForbidden();
    $this->actingAs($admin)->delete(route('letters.letterheads.destroy', $layoutC))->assertForbidden();

    // A letter cannot pick another institution's layout.
    $this->actingAs($this->s->officer('A'))->post(route('letters.compose.store'), [
        'direction' => 'outgoing', 'organization_id' => $org, 'external_party_name' => 'X', 'subject' => 'Y', 'body' => '<p>z</p>', 'letter_date' => '2026-11-05', 'letterhead_id' => $layoutC->id,
    ])->assertSessionHasErrors('letterhead_id');
});

it('deletes only layouts no letter printed on, and serves the sample PDF', function (): void {
    $admin = $this->s->administrator('A');
    $org = $this->s->org['A']->id;
    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => $org, 'name' => 'Used', 'active' => 1, 'layout' => lhLayout([lhText('{{institution.name}}')], [])]);
    $this->actingAs($admin)->post(route('letters.letterheads.store'), ['organization_id' => $org, 'name' => 'Spare', 'active' => 1, 'layout' => lhLayout([], []), 'stamp' => UploadedFile::fake()->createWithContent('s.png', tinyPng())]);
    $used = LetterLetterhead::query()->where('name', 'Used')->firstOrFail();
    $spare = LetterLetterhead::query()->where('name', 'Spare')->firstOrFail();

    $this->actingAs($this->s->officer('A'))->post(route('letters.compose.store'), [
        'direction' => 'outgoing', 'organization_id' => $org, 'external_party_name' => 'X', 'subject' => 'Y', 'body' => '<p>z</p>', 'letter_date' => '2026-11-05', 'letterhead_id' => $used->id,
    ])->assertSessionHasNoErrors();
    expect($used->letters()->count())->toBe(1);

    $this->actingAs($admin)->get(route('letters.letterheads.preview', $used))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($admin)->delete(route('letters.letterheads.destroy', $used))->assertSessionHasErrors('letterhead');
    expect($used->fresh())->not->toBeNull();
    $stamp = $spare->stamp_path;
    $this->actingAs($admin)->delete(route('letters.letterheads.destroy', $spare))->assertSessionHasNoErrors();
    expect($spare->fresh())->toBeNull()->and(Storage::disk('local')->exists($stamp))->toBeFalse()->and($used->fresh()->is_default)->toBeTrue();
});

/** A real PNG of the given size, made with GD. */
function gdPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 18, 33, 112));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A PNG header that claims a size no server can decode; nothing follows it. */
function hugePngHeader(int $side): string
{
    $ihdr = 'IHDR'.pack('NNCCCCC', $side, $side, 8, 6, 0, 0, 0);

    return hex2bin('89504e470d0a1a0a').pack('N', 13).$ihdr.pack('N', crc32($ihdr));
}

it('stores an uploaded emblem and stamp at print size, and refuses an image too large to prepare', function (): void {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD is not loaded.');
    }
    $admin = $this->s->administrator('A');
    $org = $this->s->org['A']->id;
    $image = ['type' => 'image', 'image' => 'img_logo', 'width_mm' => 24];

    $this->actingAs($admin)->post(route('letters.letterheads.store'), [
        'organization_id' => $org, 'name' => 'Large logo', 'layout' => lhLayout([$image], []),
        'new_images' => ['img_logo' => UploadedFile::fake()->createWithContent('logo.png', gdPng(1500, 900))],
        'stamp' => UploadedFile::fake()->createWithContent('stamp.png', gdPng(900, 900)),
    ])->assertSessionHasNoErrors();

    $layout = LetterLetterhead::query()->where('name', 'Large logo')->firstOrFail();
    $logo = getimagesize(Storage::disk('local')->path($layout->imageList()[0]['path']));
    $stamp = getimagesize(Storage::disk('local')->path((string) $layout->stamp_path));
    expect(max($logo[0], $logo[1]))->toBe(600)->and($logo[1])->toBe(360)
        ->and(max($stamp[0], $stamp[1]))->toBe(600);
    // The page that failed on the live server: it opens, with the emblem ready to preview.
    $this->actingAs($admin)->get(route('letters.letterheads.edit', $layout))->assertOk();
    $this->actingAs($admin)->get(route('letters.letterheads.preview', $layout))->assertOk();

    $this->actingAs($admin)->post(route('letters.letterheads.store'), [
        'organization_id' => $org, 'name' => 'Impossible', 'layout' => lhLayout([$image], []),
        'new_images' => ['img_logo' => UploadedFile::fake()->createWithContent('logo.png', hugePngHeader(30000))],
    ])->assertSessionHasErrors('new_images');
    expect(LetterLetterhead::query()->where('name', 'Impossible')->exists())->toBeFalse();
});

it('fits images stored before fitting existed, and leaves an undecodable one out of the PDF instead of failing', function (): void {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD is not loaded.');
    }
    $disk = Storage::disk('local');
    $disk->put('letters/letterheads/old-logo.png', gdPng(1400, 700));
    $disk->put('letters/letterheads/old-stamp.png', gdPng(800, 800));
    $disk->put('letters/letterheads/broken.png', hugePngHeader(30000));
    $image = fn (string $key): array => ['type' => 'image', 'image' => $key, 'width_mm' => 24];
    $layout = LetterLetterhead::query()->create([
        'organization_id' => $this->s->org['A']->id, 'name' => 'Old', 'is_default' => true, 'active' => true,
        'page' => LetterLayout::defaults()['page'],
        'header' => ['rows' => [['cells' => [['items' => [$image('img_old'), $image('img_broken')], 'align' => 'center', 'valign' => 'middle', 'width' => 0]], 'border_position' => 'none', 'border_style' => 'thin', 'border_color' => '#111111', 'space_after' => 2]]],
        'footer' => ['rows' => []],
        'images' => [['key' => 'img_old', 'path' => 'letters/letterheads/old-logo.png', 'name' => 'Old'], ['key' => 'img_broken', 'path' => 'letters/letterheads/broken.png', 'name' => 'Broken']],
        'stamp_path' => 'letters/letterheads/old-stamp.png',
    ]);

    $this->artisan('letters:fit-images', ['--dry-run' => true])->assertSuccessful();
    expect(getimagesize($disk->path('letters/letterheads/old-logo.png'))[0])->toBe(1400);

    $this->artisan('letters:fit-images')->expectsOutputToContain('broken.png')->assertSuccessful();
    expect(getimagesize($disk->path('letters/letterheads/old-logo.png'))[0])->toBe(600)
        ->and(getimagesize($disk->path('letters/letterheads/old-stamp.png'))[0])->toBe(600);

    $resolved = app(LetterheadResolver::class)->resolve($layout->fresh(), $this->s->org['A']);
    expect($resolved['images'])->toHaveKey('img_old')->not->toHaveKey('img_broken')
        ->and($resolved['stamp'])->toStartWith('data:image/');
    $this->actingAs($this->s->administrator('A'))->get(route('letters.letterheads.edit', $layout))->assertOk();
});
