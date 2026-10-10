<?php

declare(strict_types=1);

use App\Actions\Users\UploadUserProfilePhotoAction;
use App\Models\User;
use App\Support\Storage\PublicImageExtension;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Production risk register SEC-10: files on the public disk are served from
 * the application origin, so their extension must come from the detected
 * content, never from the uploader's file name.
 */
it('stores a profile photo named .html under its real image extension', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();

    $path = app(UploadUserProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('payload.html', 10, 10));

    expect($path)->toEndWith('.jpg')->not->toContain('.html');
});

it('derives the public extension from the content type', function (): void {
    // Real image bytes under misleading names and declared types.
    expect(PublicImageExtension::for(UploadedFile::fake()->image('a.html')))->toBe('jpg')
        ->and(PublicImageExtension::for(UploadedFile::fake()->image('b.png', 10, 10)->mimeType('text/html')))->toBe('png');
});

it('refuses a non-image for the public disk', function (): void {
    PublicImageExtension::for(UploadedFile::fake()->createWithContent('x.png', '<script>alert(1)</script>'));
})->throws(InvalidArgumentException::class);
