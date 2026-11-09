<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The /employee sign-in shortcut.
 *
 * Sibling portals are reached at /provider/portal/login and
 * /cafeteria/portal/login, so /employee/login is the address people try. Both
 * spellings must land somewhere useful, signed in or not.
 */
test('the employee shortcut sends a guest to the sign-in page', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/employee', '/employee/login']);

test('the employee shortcut sends a signed-in employee to their own portal', function (string $path): void {
    // A self-registered employee holds no roles, so the admin dashboard —
    // the framework default for an authenticated visitor to a guest route —
    // is a 403 for them.
    $employee = User::factory()->create(['status' => 'active']);

    $this->actingAs($employee)->get($path)->assertRedirect(route('employee.portal'));
})->with(['/employee', '/employee/login']);
