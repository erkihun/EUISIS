<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;
use Tests\Support\PublicFeedbackScenario;

/*
 * Owner rule 2026-11-08: service feedback, Tip-offs and public complaints are
 * separate modules with nothing shared. Each has its own address prefix
 * (/feedback-admin, /tip-off-admin, /complaint-admin), its own route names
 * and its own grant; old shared addresses lead to the module's own page.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');
    foreach (['service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->s = PublicFeedbackScenario::employeeTarget('SEP');
    $this->post('/service-feedback/'.$this->s->token->token.'/suggestion', ['message' => 'A tip.', 'tip_type' => 'IMPROPER_PRACTICE'])->assertSessionHasNoErrors();
    $this->post('/service-feedback/'.$this->s->token->token.'/complaint', pfComplete('complaint', ['message' => 'A complaint.']))->assertSessionHasNoErrors();
    $this->tip = EmployeeServiceFeedback::query()->where('type', 'suggestion')->sole();
    $this->complaint = EmployeeServiceFeedback::query()->where('type', 'complaint')->sole();
    $this->grant = function (array $permissions): User {
        $user = User::factory()->create();
        $user->givePermissionTo([...$permissions, 'service_feedback.view_employee_target', 'service_feedback.view_organization_target']);
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->s->organization->id, 'scope_type' => 'self', 'is_active' => true]);

        return $user;
    };
});

it('gives Tip-offs and complaints their own addresses and route names, and no shared case route', function (): void {
    $names = collect(Route::getRoutes()->getRoutesByName())->keys();
    expect($names->filter(fn (string $name): bool => str_starts_with($name, 'service-submissions.'))->values()->all())->toBe([]);

    foreach (['tip-offs' => 'tip-off-admin', 'public-complaints' => 'complaint-admin'] as $name => $prefix) {
        foreach (['dashboard', 'cases.index', 'unrouted', 'cases.show', 'cases.transition', 'cases.assign', 'reports.index', 'units.index', 'routing-rules.index', 'settings.index'] as $action) {
            $route = Route::getRoutes()->getByName("{$name}.{$action}");
            expect($route)->not->toBeNull()->and($route->uri())->toStartWith($prefix);
        }
    }
});

it('lets each grant open only its own module', function (): void {
    $tipOnly = ($this->grant)(['service_feedback.tip_offs.view']);
    $complaintOnly = ($this->grant)(['service_feedback.complaints.view']);
    $feedbackOnly = ($this->grant)(['service_feedback.view']);

    $this->actingAs($tipOnly)->get(route('tip-offs.dashboard'))->assertOk();
    $this->actingAs($tipOnly)->get(route('tip-offs.cases.show', $this->tip))->assertOk();
    $this->actingAs($tipOnly)->get(route('public-complaints.dashboard'))->assertForbidden();
    $this->actingAs($tipOnly)->get(route('public-complaints.unrouted'))->assertForbidden();
    $this->actingAs($tipOnly)->get(route('public-complaints.cases.show', $this->complaint))->assertForbidden();

    $this->actingAs($complaintOnly)->get(route('public-complaints.dashboard'))->assertOk();
    $this->actingAs($complaintOnly)->get(route('public-complaints.cases.show', $this->complaint))->assertOk();
    $this->actingAs($complaintOnly)->get(route('tip-offs.dashboard'))->assertForbidden();
    $this->actingAs($complaintOnly)->get(route('tip-offs.cases.show', $this->tip))->assertForbidden();

    // The service feedback grant alone opens neither.
    $this->actingAs($feedbackOnly)->get(route('tip-offs.cases.show', $this->tip))->assertForbidden();
    $this->actingAs($feedbackOnly)->get(route('public-complaints.cases.show', $this->complaint))->assertForbidden();

    // A case is only found under its own module's address.
    $this->actingAs($complaintOnly)->get(route('public-complaints.cases.show', $this->tip))->assertNotFound();
});

it('searches a module case list by reference and filters it to overdue cases', function (): void {
    $user = ($this->grant)(['service_feedback.complaints.view']);
    $list = fn (array $query = []) => $this->actingAs($user)->get(route('public-complaints.cases.index', $query))->assertOk();

    $list(['q' => strtolower((string) $this->complaint->reference_number)])
        ->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('entries.data.0.id', $this->complaint->id)->where('filters.q', strtolower((string) $this->complaint->reference_number)));
    $list(['q' => 'NO-SUCH-REF'])->assertInertia(fn ($page) => $page->where('entries.total', 0));
    // The Tip-off never shows on the complaint list, even by its own reference.
    $list(['q' => (string) $this->tip->reference_number])->assertInertia(fn ($page) => $page->where('entries.total', 0));

    $list(['overdue' => 1])->assertInertia(fn ($page) => $page->where('entries.total', 0)->where('overdueCount', 0));
    PublicFeedbackAssignment::query()->create([
        'submission_id' => $this->complaint->id, 'handler_type' => 'user', 'handler_id' => (string) $user->id,
        'handler_name_snapshot' => 'Handler', 'level' => 1, 'due_at' => now()->subDay(), 'assigned_at' => now()->subDays(5),
    ]);
    $list(['overdue' => 1])->assertInertia(fn ($page) => $page->where('entries.total', 1)->where('overdueCount', 1)->where('entries.data.0.overdue', true));
});

it('leads the old shared addresses to the module page', function (): void {
    $user = ($this->grant)(['service_feedback.tip_offs.view', 'service_feedback.complaints.view']);

    $this->actingAs($user)->get('/service-complaints/dashboard')->assertRedirect(route('public-complaints.dashboard'));
    $this->actingAs($user)->get('/service-suggestions/dashboard')->assertRedirect(route('tip-offs.dashboard'));
    $this->actingAs($user)->get('/service-complaints?status=closed')->assertRedirect(route('public-complaints.cases.index', ['status' => 'closed']));
    $this->actingAs($user)->get('/service-submissions/unrouted?submission_type=suggestion')->assertRedirect(route('tip-offs.unrouted'));
    $this->actingAs($user)->get('/service-submissions/complaint/'.$this->complaint->id)->assertRedirect(route('public-complaints.cases.show', $this->complaint));
    $this->actingAs($user)->get('/service-submissions/suggestion/'.$this->tip->id)->assertRedirect(route('tip-offs.cases.show', $this->tip));
});
