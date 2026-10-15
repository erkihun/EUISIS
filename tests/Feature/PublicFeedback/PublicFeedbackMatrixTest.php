<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §15: one explicit end-to-end proof per target × type combination.
 * For each: the row carries the resolved target and source, the right type,
 * the right routing outcome, the history, a receipt, and only the users the
 * permissions and scope allow can open it.
 */

const PFM_HANDLER = ['service_feedback.view', 'service_feedback.review', 'service_feedback.complaints.view', 'service_feedback.complaints.review',
    'service_feedback.view_employee_target', 'service_feedback.view_organization_target', 'service_feedback.resolve', 'service_feedback.close'];

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');

    foreach ([...PFM_HANDLER, 'service_feedback.manage_routing'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Handler', 'web')->syncPermissions(PFM_HANDLER);

    $this->a = PublicFeedbackScenario::employeeTarget('MXA');
    $this->b = PublicFeedbackScenario::employeeTarget('MXB');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;

    $scoped = function (PublicFeedbackScenario $s): User {
        $user = User::factory()->create();
        $user->assignRole('Handler');
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $s->organization->id, 'scope_type' => OrganizationScopeType::Self]);

        return $user;
    };
    $this->handler = $scoped($this->a);
    $this->foreign = $scoped($this->b);

    // One rule for institution A sends every suggestion and complaint to the handler.
    PublicFeedbackRoutingRule::query()->create([
        'organization_id' => $this->a->organization->id, 'handler_type' => 'user', 'handler_id' => (string) $this->handler->id,
        'priority' => 10, 'is_active' => true, 'created_by' => $this->superAdmin->id,
    ]);
});

/**
 * @return array{url: string, payload: array<string, mixed>, target: PublicFeedbackTargetType, source: PublicFeedbackSourceType}
 */
function matrixCase(string $target, string $type): array
{
    $employee = $target === 'employee';
    $base = $employee ? '/service-feedback/'.test()->a->token->token : '/feedback/'.test()->orgToken;
    // Feedback and Complaint come from a named visitor; the Tip-off is anonymous and names its type.
    $identity = ['client_name' => 'Matrix Visitor', 'client_contact' => '+251911223344'];
    $payload = match ($type) {
        'feedback' => ['rating' => 4, 'comment' => 'Matrix feedback '.$target] + $identity + ($employee ? ['position_service_id' => test()->a->positionService->id] : []),
        'suggestion' => ['message' => 'Matrix tip-off '.$target, 'tip_type' => 'IMPROPER_PRACTICE'],
        'complaint' => ['message' => 'Matrix complaint '.$target, 'incident_location' => 'Counter 2'] + $identity,
    };

    return [
        'url' => $base.'/'.$type,
        'payload' => $payload,
        'target' => $employee ? PublicFeedbackTargetType::Employee : PublicFeedbackTargetType::Organization,
        'source' => $employee ? PublicFeedbackSourceType::EmployeeCardQr : PublicFeedbackSourceType::OrganizationFeedbackQr,
    ];
}

$combinations = [];
foreach (['employee', 'organization'] as $target) {
    foreach (['feedback', 'suggestion', 'complaint'] as $type) {
        $combinations["{$target} × {$type}"] = [$target, $type];
    }
}

it('works end to end for', function (string $target, string $type) {
    $case = matrixCase($target, $type);

    $this->post($case['url'], $case['payload'])->assertRedirect($case['url']);
    $this->get($case['url'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('submitted', true)
        ->where('receipt.type', $type)
        ->where('receipt.target_type', $target)
        ->where('target.target_type', $target));

    $row = EmployeeServiceFeedback::query()->sole();
    $kind = ServiceFeedbackType::from($type);

    // Target, source and type come from the resolver, never the browser.
    expect($row->type)->toBe($kind)
        ->and($row->target_type)->toBe($case['target'])
        ->and($row->source_type)->toBe($case['source'])
        ->and($row->employee_id)->toBe($target === 'employee' ? $this->a->employee->id : null)
        ->and($row->organization_target_id)->toBe($target === 'organization' ? $this->a->organization->id : null)
        ->and($row->organization_id)->toBe($this->a->organization->id)
        ->and($row->target_name_snapshot)->not->toBeNull()
        ->and($row->submitted_at)->not->toBeNull();

    // Routing: ratings stay lightweight; suggestions and complaints go to the rule's handler.
    if ($kind === ServiceFeedbackType::Feedback) {
        expect($row->status)->toBe(ServiceFeedbackStatus::Pending)
            ->and($row->currentAssignment)->toBeNull()
            ->and($row->statusHistory()->pluck('to_status')->all())->toBe(['pending'])
            ->and($row->reference_number)->toBeNull()
            ->and($row->rating)->toBe(4);
    } else {
        expect($row->status)->toBe(ServiceFeedbackStatus::Assigned)
            ->and($row->currentAssignment?->handler_id)->toBe((string) $this->handler->id)
            ->and($row->statusHistory()->pluck('to_status')->all())->toBe(['pending', 'assigned'])
            ->and($row->rating)->toBeNull();
        expect($row->reference_number)->toStartWith($kind === ServiceFeedbackType::Complaint ? 'PSC-' : 'PTO-');
    }

    // Permissions and scope: the institution's handler opens it; a foreign-scope handler does not.
    $detail = $kind === ServiceFeedbackType::Feedback
        ? route('service-feedback.admin.show', $row)
        : route('service-submissions.show', ['type' => $type, 'submission' => $row]);
    $inbox = match ($kind) {
        ServiceFeedbackType::Feedback => route('service-feedback.admin.index'),
        ServiceFeedbackType::Suggestion => route('service-submissions.suggestions'),
        ServiceFeedbackType::Complaint => route('service-submissions.complaints'),
    };
    $this->actingAs($this->handler)->get($detail)->assertOk();
    $this->actingAs($this->handler)->get($inbox)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where($kind === ServiceFeedbackType::Feedback ? 'feedback.total' : 'entries.total', 1));
    $this->actingAs($this->foreign)->get($detail)->assertForbidden();
    $this->actingAs($this->foreign)->get($inbox)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where($kind === ServiceFeedbackType::Feedback ? 'feedback.total' : 'entries.total', 0));

    // Nothing in the grievance, discipline or appeal domains.
    expect(DB::table('grievances')->count())->toBe(0)
        ->and(DB::table('administrative_tribunal_cases')->count())->toBe(0);
})->with($combinations);
