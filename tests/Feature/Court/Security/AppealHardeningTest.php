<?php

declare(strict_types=1);

/*
 * A decided appeal is a record: update() now applies the same stage rule as
 * the edit page (only draft and submitted appeals change), where before a
 * direct request could rewrite the title and grounds of a decided appeal.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function hardenedAppeal(int $caseId, string $status): int
{
    return DB::table('court_appeals')->insertGetId([
        'court_case_id' => $caseId,
        'appeal_number' => 'APL-'.Str::upper(Str::random(8)),
        'title' => 'Original title',
        'grounds' => 'Original grounds',
        'status' => $status,
        'decided_at' => in_array($status, ['approved', 'rejected', 'closed'], true) ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a decided appeal cannot be changed through update', function (string $status): void {
    $clerk = jwStaff(['appeals.view', 'appeals.edit']);
    $appealId = hardenedAppeal(jwCase(['status' => 'closed'])->id, $status);

    $this->actingAs($clerk)
        ->patch(route('court.appeals.update', $appealId), ['title' => 'Rewritten', 'grounds' => 'Rewritten grounds'])
        ->assertRedirect(route('court.appeals.show', $appealId))
        ->assertSessionHas('error', 'court::messages.error.cannot_edit_stage');

    $this->assertDatabaseHas('court_appeals', ['id' => $appealId, 'title' => 'Original title', 'grounds' => 'Original grounds', 'status' => $status]);
})->with(['under_review', 'approved', 'rejected', 'closed']);

test('a draft or submitted appeal is still updated', function (string $status): void {
    $clerk = jwStaff(['appeals.view', 'appeals.edit']);
    $appealId = hardenedAppeal(jwCase(['status' => 'closed'])->id, $status);

    $this->actingAs($clerk)
        ->from(route('court.appeals.edit', $appealId))
        ->patch(route('court.appeals.update', $appealId), ['title' => 'Corrected title', 'grounds' => 'Corrected grounds'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('court_appeals', ['id' => $appealId, 'title' => 'Corrected title', 'grounds' => 'Corrected grounds']);
})->with(['draft', 'submitted']);
