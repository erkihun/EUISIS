<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;

/*
| The performance-settings forms come back to `redirect_to`. It was followed
| whenever it merely started with the app URL, so http://app.host.evil.test
| passed. Only this application (same origin, or a root-relative path) is
| followed now; anything else falls back to the settings page (#3, 6e93e50).
*/

function redirectHardeningAdmin(): User
{
    $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $admin->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $admin;
}

test('redirect_to on the performance settings only returns to this application', function (string|Closure $target, bool $followed): void {
    $target = $target instanceof Closure ? $target() : $target;

    $this->actingAs(redirectHardeningAdmin())
        ->post(route('court.settings.performance-evaluation.categories.store'), [
            'name' => 'Redirect '.md5($target),
            'redirect_to' => $target,
        ])
        ->assertRedirect($followed ? $target : route('court.settings.performance-evaluation.index'));
})->with([
    'same app' => [fn () => url('/court/admin/performance-evaluations'), true],
    'lookalike host' => [fn () => rtrim(url('/'), '/').'.evil.test/phish', false],
    'other host' => ['https://evil.test/phish', false],
    'credentials in url' => [fn () => str_replace('://', '://user@', url('/x')), false],
    'relative path' => ['/court/admin/performance-evaluations?page=2', true],
    'protocol relative' => ['//evil.test/phish', false],
    'backslash trick' => ['/\\evil.test/phish', false],
    'javascript' => ['javascript:alert(1)', false],
]);
