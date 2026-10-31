<?php

declare(strict_types=1);

/*
 * Bench notes: each of bench-notes.view/create/update/delete is checked on
 * the server (the routes only require bench-notes.manage, and the pages only
 * hid buttons); only the author or an administrator may edit or delete a
 * note; the title is stored (the column was missing); the note HTML is
 * purified on save and cleaned again on output.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

function benchNoteRow(int $caseId, User $author, string $note = '<p>Noted.</p>'): int
{
    return DB::table('court_bench_notes')->insertGetId([
        'case_id' => $caseId,
        'user_id' => $author->id,
        'title' => 'Sitting',
        'note' => $note,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function benchNotePayload(int $caseId, string $note = '<p>Adjourned.</p>'): array
{
    return [
        'case_id' => $caseId,
        'title' => 'First sitting',
        'note_date' => now()->toDateString(),
        'note' => $note,
        'judges' => [['admin_user_id' => null], ['admin_user_id' => null], ['admin_user_id' => null]],
    ];
}

test('bench-notes.manage alone no longer reads, writes or deletes notes', function (): void {
    $manager = jwStaff(['bench-notes.manage']);
    $case = jwCase();
    $noteId = benchNoteRow($case->id, $manager);

    $this->actingAs($manager)->get(route('court.bench-notes.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('court.bench-notes.show', $noteId))->assertForbidden();
    $this->actingAs($manager)->get(route('court.bench-notes.create'))->assertForbidden();
    $this->actingAs($manager)->post(route('court.bench-notes.store'), benchNotePayload($case->id))->assertForbidden();
    $this->actingAs($manager)->get(route('court.bench-notes.edit', $noteId))->assertForbidden();
    $this->actingAs($manager)->patch(route('court.bench-notes.update', $noteId), benchNotePayload($case->id, '<p>Changed.</p>'))->assertForbidden();
    $this->actingAs($manager)->delete(route('court.bench-notes.destroy', $noteId))->assertForbidden();

    expect(DB::table('court_bench_notes')->count())->toBe(1)
        ->and(DB::table('court_bench_notes')->where('id', $noteId)->value('note'))->toBe('<p>Noted.</p>');
});

test('each action needs its own permission', function (): void {
    $reader = jwStaff(['bench-notes.manage', 'bench-notes.view']);
    $case = jwCase();
    $noteId = benchNoteRow($case->id, $reader);

    $this->actingAs($reader)->get(route('court.bench-notes.index'))->assertOk();
    $this->actingAs($reader)->get(route('court.bench-notes.show', $noteId))->assertOk();
    $this->actingAs($reader)->post(route('court.bench-notes.store'), benchNotePayload($case->id))->assertForbidden();
    $this->actingAs($reader)->patch(route('court.bench-notes.update', $noteId), benchNotePayload($case->id, '<p>Changed.</p>'))->assertForbidden();
    $this->actingAs($reader)->delete(route('court.bench-notes.destroy', $noteId))->assertForbidden();

    expect(DB::table('court_bench_notes')->count())->toBe(1);
});

test('the case page offers the bench notes button only to staff who may open the list', function (): void {
    $case = jwCase();

    $this->actingAs(jwStaff(['cases.view', 'bench-notes.manage']))
        ->get(route('court.cases.show', $case->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manageBench', false)->etc());

    $this->actingAs(jwStaff(['cases.view', 'bench-notes.manage', 'bench-notes.view']))
        ->get(route('court.cases.show', $case->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manageBench', true)->etc());
});

test('only the author or an administrator may edit or delete a note', function (): void {
    $permissions = ['bench-notes.manage', 'bench-notes.view', 'bench-notes.create', 'bench-notes.update', 'bench-notes.delete'];
    $author = jwStaff($permissions);
    $colleague = jwStaff($permissions);
    $admin = jwStaff([], 'admin');
    $case = jwCase();
    $noteId = benchNoteRow($case->id, $author);

    $this->actingAs($colleague)
        ->get(route('court.bench-notes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notes.data.0.mine', false)->etc());
    $this->actingAs($colleague)->get(route('court.bench-notes.edit', $noteId))->assertForbidden();
    $this->actingAs($colleague)->patch(route('court.bench-notes.update', $noteId), benchNotePayload($case->id, '<p>Not mine.</p>'))->assertForbidden();
    $this->actingAs($colleague)->delete(route('court.bench-notes.destroy', $noteId))->assertForbidden();
    expect(DB::table('court_bench_notes')->where('id', $noteId)->value('note'))->toBe('<p>Noted.</p>');

    $this->actingAs($author)
        ->get(route('court.bench-notes.show', $noteId))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('note.mine', true)->etc());
    $this->actingAs($author)->patch(route('court.bench-notes.update', $noteId), benchNotePayload($case->id, '<p>Mine.</p>'))
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('court_bench_notes')->where('id', $noteId)->value('note'))->toBe('<p>Mine.</p>');

    $this->actingAs($admin)->delete(route('court.bench-notes.destroy', $noteId))->assertRedirect();
    expect(DB::table('court_bench_notes')->where('id', $noteId)->exists())->toBeFalse();
});

test('the title is stored and the note is purified on save and cleaned on output', function (): void {
    expect(Schema::hasColumn('court_bench_notes', 'title'))->toBeTrue();

    $judge = jwStaff(['bench-notes.manage', 'bench-notes.view', 'bench-notes.create']);
    $case = jwCase();

    $this->actingAs($judge)
        ->post(route('court.bench-notes.store'), benchNotePayload($case->id, '<p>Both present.</p><script>alert(1)</script>'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('court.bench-notes.index', ['case_id' => $case->id]));

    $stored = DB::table('court_bench_notes')->where('case_id', $case->id)->first();
    expect($stored->title)->toBe('First sitting')
        ->and($stored->note)->toContain('Both present.')
        ->and($stored->note)->not->toContain('<script');

    // A row written before purification on save is cleaned on the way out.
    $legacy = benchNoteRow($case->id, $judge, '<p>Old.</p><img src="x" onerror="alert(1)">');
    $this->actingAs($judge)
        ->get(route('court.bench-notes.show', $legacy))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('note.title', 'Sitting')
            ->where('note.note', fn (string $html): bool => str_contains($html, 'Old.') && ! str_contains($html, 'onerror'))
            ->etc());
});
