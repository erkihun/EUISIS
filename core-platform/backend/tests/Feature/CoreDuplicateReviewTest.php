<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\EmployeeVerification;
use App\Actions\UpdateEmployee;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeDuplicateReview;
use App\Models\Organization;
use App\Models\User;
use App\Services\CardEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P2 (ADR 0009): possible duplicates become reviews; a reviewer decides; a confirmed duplicate is blocked. */
class CoreDuplicateReviewTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function signIn(string $role = 'core_officer', ?Organization $scope = null): User
    {
        $user = User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password', 'role' => $role,
            'scope_organization_id' => $scope?->id, 'scope_type' => $scope === null ? User::SCOPE_CITYWIDE : User::SCOPE_SUBTREE, 'is_active' => true,
        ]);
        Auth::forgetGuards();
        $this->actingAs($user, 'web');

        return $user;
    }

    /** A draft registered over a match, as an officer of bureau B. */
    private function registerTwin(array $person): Employee
    {
        $this->signIn('core_officer', $this->bureauB);
        $person += ['position_id' => $this->positionB->id, 'reason' => 'A different person: other parents on file'];
        // The screen warns first, then accepts the registrar's statement.
        $this->post('/employees', $person)->assertSessionHasErrors('reason');
        $this->post('/employees', $person)->assertSessionHasNoErrors();

        return Employee::query()->where('full_name', trim($person['first_name'].' '.$person['last_name']))->where('status', Employee::DRAFT)->sole();
    }

    public function test_a_strong_match_waits_in_the_queue_and_blocks_verification_until_someone_else_decides(): void
    {
        $this->employee->update(['date_of_birth' => '1990-05-01']);
        $twin = $this->registerTwin(['first_name' => 'Abebe', 'last_name' => 'EMP-0001', 'date_of_birth' => '1990-05-01']);
        $registrar = Auth::user();
        $review = EmployeeDuplicateReview::query()->where('employee_id', $twin->id)->sole();
        $this->assertSame(['strong_match', 'pending', 'registration'], [$review->match_level, $review->decision, $review->source]);

        $verification = app(EmployeeVerification::class);
        $verification->submit($twin, $registrar);
        $verifier = $this->signIn();
        $this->assertSame('Possible duplicates must be reviewed first: EMP-0001.',
            rescue(fn () => $verification->verify($twin->fresh(), $verifier, array_fill_keys(array_keys($verification->checklist($twin)), true)) && '', fn (ValidationException $e) => $e->errors()['employee'][0], false));

        // The person whose change raised the match does not decide it.
        Auth::forgetGuards();
        $this->actingAs($registrar, 'web');
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'not_duplicate', 'reason' => 'I already said so'])->assertSessionHasErrors('review');
        $this->assertSame(EmployeeDuplicateReview::PENDING, $review->fresh()->decision);

        $reviewer = $this->signIn();
        $this->get('/core/duplicates')->assertOk()->assertInertia(fn ($page) => $page->component('Core/DuplicateReviews')->where('counts.pending', 1)
            ->where('reviews.data.0.flagged.employee_number', $twin->employee_number)->where('reviews.data.0.matched.date_of_birth', '1990-05-01')
            ->where('reviews.data.0.reasons', ['name_and_date_of_birth'])->where('reviews.data.0.can_decide', true));
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'not_duplicate', 'reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'not_duplicate', 'reason' => 'Different father and birth place in the file'])->assertSessionHasNoErrors();
        $this->assertSame([EmployeeDuplicateReview::NOT_DUPLICATE, $reviewer->id], [$review->fresh()->decision, (int) $review->fresh()->decided_by]);
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'confirmed_duplicate', 'reason' => 'Changed my mind'])->assertSessionHasErrors('review');

        $this->assertSame(Employee::VERIFIED, $verification->verify($twin->fresh(), $verifier, array_fill_keys(array_keys($verification->checklist($twin)), true))->status);
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'employee.duplicate_reviewed', 'subject_id' => $review->id, 'actor_user_id' => $reviewer->id]);
    }

    public function test_a_possible_match_can_need_more_information_and_a_confirmed_duplicate_is_blocked(): void
    {
        $this->employee->update(['phone' => '0911000000']);
        $twin = $this->registerTwin(['first_name' => 'Kebede', 'last_name' => 'Tola', 'phone' => '0911000000']);
        $review = EmployeeDuplicateReview::query()->where('employee_id', $twin->id)->sole();
        $this->assertSame(['possible_match', ['phone']], [$review->match_level, $review->reasons]);

        $this->signIn();
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'needs_more_information', 'reason' => 'Ask HR for the appointment letter'])->assertSessionHasNoErrors();
        $this->get('/core/duplicates?status=needs_more_information')->assertInertia(fn ($page) => $page->where('counts.needs_more_information', 1)->where('counts.pending', 0));
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'confirmed_duplicate', 'reason' => 'The same person registered twice'])->assertSessionHasNoErrors();

        $twin->forceFill(['status' => Employee::ACTIVE])->save();
        $twin->currentAssignment->forceFill(['assignment_status' => 'active'])->save();
        $this->giveVerifiedPhoto($twin->id);
        $this->assertArrayHasKey('DUPLICATE_CONFIRMED', app(CardEligibility::class)->decide($twin->fresh()));
        // The original record is not the duplicate.
        $this->assertArrayNotHasKey('DUPLICATE_CONFIRMED', app(CardEligibility::class)->decide($this->employee->fresh()));
        $this->get('/employees/'.$twin->id)->assertInertia(fn ($page) => $page->where('employee.duplicate_flags.0.matched_fields', ['phone', 'confirmed_duplicate']));
    }

    public function test_the_queue_and_its_decisions_stay_inside_the_scope(): void
    {
        $twin = $this->registerTwin(['first_name' => 'Abebe', 'last_name' => 'EMP-0001']);
        $review = EmployeeDuplicateReview::query()->where('employee_id', $twin->id)->sole();

        // Bureau A does not hold the flagged record (bureau B): not listed, not decided, not even found.
        $this->signIn('core_officer', $this->bureauA);
        $this->get('/core/duplicates')->assertOk()->assertInertia(fn ($page) => $page->where('counts.pending', 0)->where('reviews.data', []));
        $this->post('/core/duplicates/'.$review->id.'/decide', ['decision' => 'not_duplicate', 'reason' => 'Out of my reach'])->assertNotFound();

        // Bureau B sees its record in full and the matching one (bureau A) by number and name only.
        $this->signIn('core_officer', $this->bureauB);
        $this->get('/core/duplicates')->assertInertia(fn ($page) => $page->where('reviews.data.0.matched.in_scope', false)
            ->where('reviews.data.0.matched.employee_number', 'EMP-0001')->missing('reviews.data.0.matched.phone'));
        // A viewer has no queue.
        $this->signIn('core_viewer');
        $this->get('/core/duplicates')->assertForbidden();
    }

    public function test_an_identity_change_looks_again_but_never_against_the_record_itself(): void
    {
        $other = $this->placedEmployee('EMP-0200', $this->positionB, '2024-01-01');
        $officer = $this->signIn();
        $update = app(UpdateEmployee::class);

        // Changing something that is not identity, or keeping the same name: no review.
        $update->details($this->employee->fresh(), ['gender' => 'female'], $officer);
        $update->details($this->employee->fresh(), ['first_name' => 'Abebe', 'last_name' => 'EMP-0001'], $officer);
        $this->assertSame(0, EmployeeDuplicateReview::query()->count());

        // Taking the other employee's name raises a review against that record only.
        $update->details($this->employee->fresh(), ['first_name' => 'Abebe', 'last_name' => 'EMP-0200'], $officer);
        $review = EmployeeDuplicateReview::query()->sole();
        $this->assertSame([(string) $this->employee->id, (string) $other->id, 'update'], [(string) $review->employee_id, (string) $review->matched_employee_id, $review->source]);

        // Changing it again does not repeat the pair; another national ID holder is refused.
        $update->details($this->employee->fresh(), ['phone' => '0922000000'], $officer);
        $this->assertSame(1, EmployeeDuplicateReview::query()->count());
        $update->details($other->fresh(), ['national_id' => '1111 2222 3333'], $officer);
        $this->assertSame('A person with this national ID is already registered.',
            rescue(fn () => $update->details($this->employee->fresh(), ['national_id' => '111122223333'], $officer) && '', fn (ValidationException $e) => $e->errors()['national_id'][0], false));
        // The national ID's hash never reaches the audit trail.
        $this->assertStringNotContainsString((string) $other->fresh()->national_id_hash, (string) CoreAuditLog::query()->get()->toJson());
    }
}
