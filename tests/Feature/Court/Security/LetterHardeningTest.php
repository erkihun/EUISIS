<?php

declare(strict_types=1);

/*
 * Letters: an approved letter has gone to the parties, so it can no longer be
 * decided again (which re-sent the approval emails and case messages), and
 * its edit page sends the user back with a notice. The letters list's search
 * box searches reference, subject, case number and party names.
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Modules\Court\Domain\Models\Letter;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function hardenedLetter(User $author, array $attributes = []): Letter
{
    $template = LetterTemplate::query()->create(['title' => 'Summons '.Str::random(4), 'body' => '<p>Body</p>']);

    return Letter::query()->create(array_merge([
        'letter_template_id' => $template->id,
        'user_id' => $author->id,
        'subject' => 'Summons',
        'reference_number' => 'REF-'.Str::upper(Str::random(6)),
        'body' => '<p>Body</p>',
        'send_to_applicant' => true,
        'send_to_respondent' => true,
    ], $attributes));
}

test('an approved letter cannot be decided again', function (): void {
    Mail::fake();
    $registrar = jwStaff(['letters.view', 'letters.approve']);
    $case = jwCase();
    $letter = hardenedLetter($registrar, ['case_number' => $case->case_number, 'approval_status' => 'approved', 'approved_by_name' => 'Registrar']);

    foreach (['approved', 'returned', 'rejected'] as $status) {
        $this->actingAs($registrar)
            ->from(route('court.letters.index'))
            ->post(route('court.letters.approve', $letter), ['status' => $status])
            ->assertRedirect(route('court.letters.index'))
            ->assertSessionHas('error', __('court::messages.error.approved_letter_update_locked'));
    }

    expect($letter->fresh()->approval_status)->toBe('approved')
        ->and(DB::table('court_case_messages')->where('case_id', $case->id)->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    // A letter that is not approved yet is still decided as before.
    $pending = hardenedLetter($registrar, ['approval_status' => 'pending']);
    $this->actingAs($registrar)
        ->post(route('court.letters.approve', $pending), ['status' => 'returned'])
        ->assertRedirect(route('court.letters.index'))
        ->assertSessionHas('success');
    expect($pending->fresh()->approval_status)->toBe('returned');
});

test('the edit page of an approved letter redirects back with a notice', function (): void {
    $clerk = jwStaff(['letters.view', 'letters.update']);
    $approved = hardenedLetter($clerk, ['approval_status' => 'approved']);
    $pending = hardenedLetter($clerk, ['approval_status' => 'pending']);

    $this->actingAs($clerk)
        ->get(route('court.letters.edit', $approved))
        ->assertRedirect(route('court.letters.index'))
        ->assertSessionHas('error', __('court::messages.error.approved_letter_update_locked'));

    $this->actingAs($clerk)->get(route('court.letters.edit', $pending))->assertOk();
});

test('the letters list searches reference, subject, case number and party names', function (): void {
    $clerk = jwStaff(['letters.view']);
    $case = jwCase(['title' => 'Selamawit Bekele', 'respondent_name' => 'Kirkos Sub-city']);
    $byReference = hardenedLetter($clerk, ['reference_number' => 'ZX-REF-001', 'subject' => 'First']);
    $bySubject = hardenedLetter($clerk, ['subject' => 'Hearing adjournment notice']);
    $byCase = hardenedLetter($clerk, ['case_number' => $case->case_number, 'subject' => 'Third']);
    hardenedLetter($clerk, ['subject' => 'Unrelated']);

    $ids = fn (string $q): array => collect($this->actingAs($clerk)
        ->get(route('court.letters.index', ['q' => $q]))
        ->assertOk()
        ->inertiaProps('letters.data'))->pluck('id')->all();

    expect($ids('ZX-REF'))->toBe([$byReference->id])
        ->and($ids('adjournment'))->toBe([$bySubject->id])
        ->and($ids($case->case_number))->toBe([$byCase->id])
        ->and($ids('Selamawit'))->toBe([$byCase->id])
        ->and($ids('Kirkos'))->toBe([$byCase->id])
        ->and($ids('100%'))->toBe([]);

    $this->actingAs($clerk)
        ->get(route('court.letters.index', ['q' => 'Kirkos', 'status' => 'pending']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', ['status' => 'pending', 'q' => 'Kirkos'])
            ->etc());
});
