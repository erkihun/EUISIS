<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Contracts\Data\EmployeeFeedbackRatingSummary;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\PublicComplaints\Domain\Models\PublicComplaint;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\PublicFeedbackScenario;

/*
 * Public feedback end to end, from both sides of the counter.
 *
 * Customer side: a client scans the employee's feedback QR, sees who served
 * them, and leaves a star rating; bad input and inactive QRs are refused.
 * Admin side: the rating reaches the review inbox, can be reviewed and
 * hidden, and the employee detail page shows the clients' average by star —
 * within the viewer's feedback grants and organization scope only, never to
 * the employee about themselves, and never counting a hidden rating.
 */

/** One client's star rating through the public form, as a real visitor would post it. */
function efrSubmit(PublicFeedbackScenario $scenario, int $stars, string $comment, string $ip = '127.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post(
        '/service-feedback/'.$scenario->token->token,
        pfComplete('feedback', [
            'position_service_id' => $scenario->positionService->id,
            'rating' => $stars,
            'comment' => $comment,
        ]),
    );
}

/** @return array<string, mixed>|null the employee page's feedbackRating prop */
function efrRatingProp(User $viewer, PublicFeedbackScenario $scenario): ?array
{
    $rating = 'unset';
    test()->actingAs($viewer)
        ->get(route('employees.show', $scenario->employee))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$rating): void {
            $props = $page->component('Employees/Show')->toArray()['props'];
            // Always sent: null means "not for this viewer", never "forgotten".
            expect($props)->toHaveKey('feedbackRating');
            $rating = $props['feedbackRating'];
        });

    return $rating;
}

/** @param array<string, mixed> $rating @return array<int, int> stars => count */
function efrDistribution(array $rating): array
{
    return collect($rating['distribution'])->mapWithKeys(fn (array $row): array => [$row['stars'] => $row['count']])->all();
}

function efrCitywide(User $user): User
{
    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'scope_type' => OrganizationScopeType::Citywide,
        'organization_id' => null,
        'is_active' => true,
    ]);

    return $user;
}

beforeEach(function (): void {
    app()->setLocale('en');
    $this->withHeader('X-Locale', 'en');

    foreach ([
        'employees.view',
        'service_feedback.view',
        'service_feedback.review',
        'service_feedback.hide',
        'service_feedback.view_employee_target',
        'service_feedback.view_organization_target',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([
        'employees.view', 'service_feedback.view', 'service_feedback.review', 'service_feedback.hide',
        'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
    ]);
    // Reads the registry but holds no feedback grant.
    Role::findOrCreate('Registry Officer', 'web')->syncPermissions(['employees.view']);
    // Reads institution ratings only; employee-targeted feedback is a separate grant.
    Role::findOrCreate('Institution Feedback Reader', 'web')->syncPermissions([
        'employees.view', 'service_feedback.view', 'service_feedback.view_organization_target',
    ]);

    $this->scenario = PublicFeedbackScenario::employeeTarget('EFR');

    $this->admin = efrCitywide(tap(User::factory()->create())->assignRole('Super Admin'));
});

// ── Customer side ────────────────────────────────────────────────────────────

it('lets a customer scan the QR, see who served them and leave a star rating', function (): void {
    $this->get('/service-feedback/'.$this->scenario->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', true)
            ->where('target.display_name', 'Hanna Girma')
            ->where('target.target_type', 'employee'));

    efrSubmit($this->scenario, 4, 'Quick and polite.')
        ->assertRedirect('/service-feedback/'.$this->scenario->token->token);

    $stored = EmployeeServiceFeedback::query()->sole();

    expect($stored->employee_id)->toBe($this->scenario->employee->id)
        ->and($stored->organization_id)->toBe($this->scenario->organization->id)
        ->and($stored->rating)->toBe(4)
        ->and($stored->comment)->toBe('Quick and polite.')
        ->and($stored->status)->toBe(ServiceFeedbackStatus::Pending);
});

it('refuses a customer rating outside one to five stars', function (int $stars): void {
    efrSubmit($this->scenario, $stars, 'Out of range.')->assertSessionHasErrors('rating');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
})->with([0, 6, -1]);

it('stores nothing when a customer rates through a revoked QR', function (): void {
    $this->scenario->token->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

    efrSubmit($this->scenario, 5, 'Revoked QR.');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

// ── Admin side: inbox, review and moderation ─────────────────────────────────

it('delivers the customer rating to the admin inbox, where it can be opened and reviewed', function (): void {
    efrSubmit($this->scenario, 2, 'Waited an hour.')->assertRedirect();
    $feedback = EmployeeServiceFeedback::query()->sole();

    $this->actingAs($this->admin)
        ->get(route('service-feedback.admin.index', ['employee_id' => $this->scenario->employee->id]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($feedback): void {
            $rows = collect($page->toArray()['props']['feedback']['data']);
            expect($rows->pluck('id')->all())->toBe([$feedback->id])
                ->and($rows->first()['rating'])->toBe(2);
        });

    $this->actingAs($this->admin)->get(route('service-feedback.admin.show', $feedback->id))->assertOk();

    $this->actingAs($this->admin)
        ->post(route('service-feedback.admin.review', $feedback->id), [
            'status' => 'reviewed',
            'review_note' => 'Discussed queue handling with the unit.',
        ])
        ->assertRedirect();

    expect($feedback->fresh()->status)->toBe(ServiceFeedbackStatus::Reviewed)
        ->and((string) $feedback->fresh()->reviewed_by)->toBe((string) $this->admin->id);
});

// ── Admin side: the employee detail page's average by star ───────────────────

it('shows the average customer rating and the count of each star on the employee detail page', function (): void {
    efrSubmit($this->scenario, 5, 'Excellent.', '10.0.0.1')->assertRedirect();
    efrSubmit($this->scenario, 4, 'Good.', '10.0.0.2')->assertRedirect();
    efrSubmit($this->scenario, 4, 'Good again.', '10.0.0.3')->assertRedirect();
    efrSubmit($this->scenario, 2, 'Slow.', '10.0.0.4')->assertRedirect();

    $rating = efrRatingProp($this->admin, $this->scenario);

    expect($rating['total'])->toBe(4)
        ->and((float) $rating['average'])->toBe(3.75)
        ->and(array_column($rating['distribution'], 'stars'))->toBe([5, 4, 3, 2, 1])
        ->and(efrDistribution($rating))->toBe([5 => 1, 4 => 2, 3 => 0, 2 => 1, 1 => 0]);
});

it('shows an empty rating, not a zero average, before any customer has rated', function (): void {
    $rating = efrRatingProp($this->admin, $this->scenario);

    expect($rating['total'])->toBe(0)
        ->and($rating['average'])->toBeNull()
        ->and(array_sum(efrDistribution($rating)))->toBe(0);
});

it('drops a rating from the average once an admin hides it, and restores it when unhidden', function (): void {
    efrSubmit($this->scenario, 5, 'Great service.', '10.0.1.1')->assertRedirect();
    efrSubmit($this->scenario, 1, 'Abusive remark.', '10.0.1.2')->assertRedirect();
    $abusive = EmployeeServiceFeedback::query()->where('rating', 1)->sole();

    expect((float) efrRatingProp($this->admin, $this->scenario)['average'])->toBe(3.0);

    $this->actingAs($this->admin)->post(route('service-feedback.admin.hide', $abusive->id))->assertRedirect();
    expect($abusive->fresh()->status)->toBe(ServiceFeedbackStatus::Hidden);

    $hidden = efrRatingProp($this->admin, $this->scenario);
    expect($hidden['total'])->toBe(1)
        ->and((float) $hidden['average'])->toBe(5.0)
        ->and(efrDistribution($hidden)[1])->toBe(0);

    $this->actingAs($this->admin)->post(route('service-feedback.admin.hide', $abusive->id))->assertRedirect();
    expect(efrRatingProp($this->admin, $this->scenario)['total'])->toBe(2);
});

it('counts only star ratings, never complaints about the employee', function (): void {
    efrSubmit($this->scenario, 4, 'Fine.')->assertRedirect();
    $this->post('/service-feedback/'.$this->scenario->token->token.'/complaint', pfComplete('complaint', [
        'message' => 'Rude treatment at the desk.',
    ]))->assertSessionHasNoErrors()->assertRedirect();

    // ADR 0032: the complaint is stored by its own module and shows in the feedback view beside the rating.
    expect(PublicComplaint::query()->count())->toBe(1)
        ->and(EmployeeServiceFeedback::query()->count())->toBe(2)
        ->and(efrRatingProp($this->admin, $this->scenario)['total'])->toBe(1);
});

it('takes no Tip-off on an employee card, so none can reach the rating', function (): void {
    // Owner rule 2026-11-08: a Tip-off is given on the institution QR only.
    $this->post('/service-feedback/'.$this->scenario->token->token.'/suggestion', pfComplete('suggestion', [
        'message' => 'Open the counter earlier.',
    ]))->assertNotFound();

    expect(efrRatingProp($this->admin, $this->scenario)['total'])->toBe(0);
});

it('counts for a scoped admin only the ratings recorded in organizations they hold', function (): void {
    efrSubmit($this->scenario, 5, 'Here.')->assertRedirect();

    // The same employee rated while posted to another organization.
    $elsewhere = PublicFeedbackScenario::employeeTarget('EFR-OTHER');
    EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->scenario->employee->id,
        'organization_id' => $elsewhere->organization->id,
        'type' => 'feedback',
        'rating' => 1,
        'comment' => 'From the previous posting.',
        'status' => ServiceFeedbackStatus::Pending,
    ]);

    $scoped = tap(User::factory()->create())->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->scenario->organization->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
    ]);

    $scopedView = efrRatingProp($scoped, $this->scenario);
    expect($scopedView['total'])->toBe(1)->and((float) $scopedView['average'])->toBe(5.0);

    // The unrestricted admin sees both.
    $fullView = efrRatingProp($this->admin, $this->scenario);
    expect($fullView['total'])->toBe(2)->and((float) $fullView['average'])->toBe(3.0);
});

it('sends no rating to a viewer without the feedback view permission', function (): void {
    efrSubmit($this->scenario, 5, 'Excellent.')->assertRedirect();
    $registry = efrCitywide(tap(User::factory()->create())->assignRole('Registry Officer'));

    expect(efrRatingProp($registry, $this->scenario))->toBeNull();
});

it('sends no rating to a viewer who may read institution feedback only', function (): void {
    efrSubmit($this->scenario, 5, 'Excellent.')->assertRedirect();
    $reader = efrCitywide(tap(User::factory()->create())->assignRole('Institution Feedback Reader'));

    expect(efrRatingProp($reader, $this->scenario))->toBeNull();
});

it('never shows employees their own customer rating', function (): void {
    efrSubmit($this->scenario, 1, 'Rude.')->assertRedirect();
    $this->admin->forceFill(['employee_id' => $this->scenario->employee->id])->save();

    expect(efrRatingProp($this->admin, $this->scenario))->toBeNull();
});

// ── The summary itself ───────────────────────────────────────────────────────

it('derives total, rounded average and a full five-star distribution from the counts', function (): void {
    $summary = new EmployeeFeedbackRatingSummary([5 => 2, 3 => 1]);

    expect($summary->total)->toBe(3)
        ->and($summary->average)->toBe(4.33)
        ->and($summary->distribution)->toBe([1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 2])
        ->and((new EmployeeFeedbackRatingSummary([]))->average)->toBeNull();
});

it('rejects counts for a star outside one to five', function (): void {
    new EmployeeFeedbackRatingSummary([6 => 1]);
})->throws(InvalidArgumentException::class);
