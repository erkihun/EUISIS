<?php

use App\Models\User;
use Illuminate\Support\Str;

function bellNotice(User $user, array $data = [])
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'data' => [...['title' => 'Activity returned', 'message' => 'Please review your activity.', 'url' => '/my-portal/daily-activity', 'internal_secret' => 'never exposed'], ...$data],
    ]);
}

test('notification endpoints require authentication', function (): void {
    $this->getJson(route('notifications.feed'))->assertUnauthorized();
    $this->postJson(route('notifications.read-all'))->assertUnauthorized();
    $this->postJson(route('notifications.read', Str::uuid()))->assertUnauthorized();
});

test('feed exposes only safe fields belonging to the signed in account', function (): void {
    $owner = User::factory()->create(['status' => 'active']);
    $other = User::factory()->create(['status' => 'active']);
    $mine = bellNotice($owner);
    bellNotice($other, ['title' => 'Private notification']);

    $this->actingAs($owner)->getJson(route('notifications.feed'))
        ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $mine->id)
        ->assertJsonPath('items.0.url', '/my-portal/daily-activity')
        ->assertJsonMissing(['title' => 'Private notification'])
        ->assertDontSee('internal_secret')->assertDontSee('never exposed')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('mark read is persistent idempotent and refuses another accounts notification', function (): void {
    $owner = User::factory()->create(['status' => 'active']);
    $other = User::factory()->create(['status' => 'active']);
    $mine = bellNotice($owner);
    $theirs = bellNotice($other);
    $this->actingAs($owner)->postJson(route('notifications.read', $theirs->id))->assertNotFound();
    $this->postJson(route('notifications.read', $mine->id))->assertOk();
    $readAt = $mine->fresh()->read_at;
    $this->postJson(route('notifications.read', $mine->id))->assertOk();
    expect($mine->fresh()->read_at->equalTo($readAt))->toBeTrue();
    expect($theirs->fresh()->read_at)->toBeNull();
    $this->getJson(route('notifications.feed'))->assertJsonPath('unread_count', 0)->assertJsonPath('items.0.read', true);
});

test('mark all read never changes another accounts notifications', function (): void {
    $owner = User::factory()->create(['status' => 'active']);
    $other = User::factory()->create(['status' => 'active']);
    bellNotice($owner);
    bellNotice($owner);
    $theirs = bellNotice($other);
    $this->actingAs($owner)->postJson(route('notifications.read-all'))->assertOk();
    expect($owner->unreadNotifications()->count())->toBe(0);
    expect($theirs->fresh()->read_at)->toBeNull();
});

test('feed paginates while counting all unread notifications', function (): void {
    $owner = User::factory()->create(['status' => 'active']);
    for ($i = 0; $i < 12; $i++) {
        bellNotice($owner);
    }
    $this->actingAs($owner)->getJson(route('notifications.feed'))
        ->assertJsonCount(10, 'items')->assertJsonPath('unread_count', 12)->assertJsonPath('last_page', 2);
    $this->getJson(route('notifications.feed', ['page' => 2]))->assertJsonCount(2, 'items');
});

test('unsafe notification destinations are not exposed as links', function (string $url): void {
    $owner = User::factory()->create(['status' => 'active']);
    bellNotice($owner, ['url' => $url]);
    $this->actingAs($owner)->getJson(route('notifications.feed'))->assertJsonPath('items.0.url', null);
})->with(['https://example.com', '//example.com', '/\\example.com', "\n//example.com", 'javascript:alert(1)']);

test('notices are worded in the reader language, not the language they were sent in', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    // Sent while the server spoke English.
    bellNotice($user, [
        'module' => 'daily_activity', 'kind' => 'returned', 'activity_date' => '2026-09-22', 'comment' => 'Add the output',
        'title' => 'Daily Activity returned for correction', 'message' => 'English text', 'url' => '/my-portal/daily-activity?date=2026-09-22',
    ]);

    $this->actingAs($user)->getJson(route('notifications.feed', ['locale' => 'am']))
        ->assertOk()
        ->assertJsonPath('items.0.title', trans('daily-activities.notifications.returned_subject', [], 'am'))
        ->assertJsonPath('items.0.message', trans('daily-activities.notifications.returned_body', ['date' => '2026-09-22', 'comment' => 'Add the output'], 'am'));

    $this->actingAs($user)->getJson(route('notifications.feed', ['locale' => 'en']))
        ->assertJsonPath('items.0.title', 'Daily Activity returned for correction');
});

test('notices without a known kind keep the text they were sent with', function (): void {
    $user = User::factory()->create(['status' => 'active']);
    bellNotice($user, ['title' => 'Plain title', 'message' => 'Plain message']);

    $this->actingAs($user)->getJson(route('notifications.feed', ['locale' => 'am']))
        ->assertJsonPath('items.0.title', 'Plain title')
        ->assertJsonPath('items.0.message', 'Plain message');
});
