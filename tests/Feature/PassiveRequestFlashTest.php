<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/flash-source', fn () => redirect('/_test/flash-landing')->with('flash.message', 'Saved'));
    Route::middleware('web')->get('/_test/flash-landing', fn () => response()->json(['message' => session('flash.message')]));
    Route::middleware('web')->get('/_test/other-page', fn () => response()->json(['ok' => true]));
});

test('a passive background poll keeps flashed data for the page that follows', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->get('/_test/flash-source')->assertRedirect('/_test/flash-landing');

    // The notification bell polls between the redirect and the page it leads to.
    $this->getJson(route('notifications.feed'), ['X-Activity' => 'passive'])->assertOk();

    $this->getJson('/_test/flash-landing')->assertJsonPath('message', 'Saved');
});

test('a request the client marks passive keeps flashed data even off the configured list', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->get('/_test/flash-source')->assertRedirect('/_test/flash-landing');
    $this->getJson('/_test/other-page', ['X-Activity' => 'passive'])->assertOk();

    $this->getJson('/_test/flash-landing')->assertJsonPath('message', 'Saved');
});

test('an ordinary request between a redirect and its page still consumes the flash', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->get('/_test/flash-source')->assertRedirect('/_test/flash-landing');
    $this->getJson('/_test/other-page')->assertOk();

    $this->getJson('/_test/flash-landing')->assertJsonPath('message', null);
});
