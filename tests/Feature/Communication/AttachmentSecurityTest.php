<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Communication\Application\Actions\StartDirectConversationAction;
use Modules\Communication\Domain\Models\MessageAttachment;
use Tests\Support\CommunicationScenario;

/*
 * Attachments live on private storage; the type is checked from the content,
 * not the name or the client's claim; downloads re-check membership.
 */

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = CommunicationScenario::build();
    $this->conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
});

it('stores an allowed file privately and lets members download it once sent', function (): void {
    $upload = $this->actingAs($this->s->alice)->post(route('communication.api.attachments.store', $this->conversation), [
        'file' => UploadedFile::fake()->image('site-photo.png', 40, 30),
    ])->assertCreated();
    $attachment = MessageAttachment::query()->findOrFail($upload->json('data.id'));

    expect($attachment->disk)->toBe('local')->and($attachment->mime_type)->toBe('image/png')
        ->and(json_encode($upload->json()))->not->toContain($attachment->path);
    Storage::disk('local')->assertExists($attachment->path);

    // Unsent: only its uploader can fetch it.
    $this->actingAs($this->s->bob)->get(route('communication.api.attachments.download', $attachment))->assertNotFound();

    $this->actingAs($this->s->alice)->postJson(route('communication.api.messages.store', $this->conversation), ['attachment_ids' => [$attachment->id]])
        ->assertCreated()->assertJsonPath('data.type', 'IMAGE');

    $this->actingAs($this->s->bob)->get(route('communication.api.attachments.download', $attachment))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->s->carol)->get(route('communication.api.attachments.download', $attachment))->assertNotFound();
});

it('rejects disallowed types, disguised content and oversized files', function (): void {
    $this->actingAs($this->s->alice)->post(route('communication.api.attachments.store', $this->conversation), [
        'file' => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
    ])->assertSessionHasErrors('file');

    // Named .png, but the content is text: the detected type decides.
    $this->actingAs($this->s->alice)->post(route('communication.api.attachments.store', $this->conversation), [
        'file' => UploadedFile::fake()->createWithContent('photo.png', 'not really an image'),
    ])->assertSessionHasErrors('file');

    CommunicationScenario::setting('max_attachment_size_kb', 64);
    $this->actingAs($this->s->alice)->post(route('communication.api.attachments.store', $this->conversation), [
        'file' => UploadedFile::fake()->image('large.png', 2000, 2000)->size(200),
    ])->assertSessionHasErrors('file');

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('does not let one person send another person\'s upload', function (): void {
    $upload = $this->actingAs($this->s->alice)->post(route('communication.api.attachments.store', $this->conversation), [
        'file' => UploadedFile::fake()->image('a.png', 10, 10),
    ])->assertCreated();

    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.store', $this->conversation), ['attachment_ids' => [$upload->json('data.id')]])
        ->assertUnprocessable();
});
