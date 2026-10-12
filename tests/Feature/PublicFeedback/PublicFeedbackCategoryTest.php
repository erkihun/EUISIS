<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Categories classify a submission. They apply by submission type and by
 * target type, are city-wide or scoped to one institution, and are never
 * deleted once a submission refers to them (brief §31–33).
 */

beforeEach(function (): void {
    app()->setLocale('en');

    foreach (['service_feedback.manage_categories', 'service_feedback.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions(['service_feedback.manage_categories', 'service_feedback.view']);

    $this->a = PublicFeedbackScenario::employeeTarget('OQC');
    $this->b = PublicFeedbackScenario::employeeTarget('OQC2');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->orgAdmin = User::factory()->create();
    $this->orgAdmin->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $this->orgAdmin->id,
        'organization_id' => $this->a->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
    RateLimiter::clear('sf-submit:'.$this->orgToken.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    $this->cityWide = PublicFeedbackCategory::query()->create([
        'code' => 'WAITING', 'name_en' => 'Waiting time', 'name_am' => 'የመጠበቂያ ጊዜ',
        'submission_types' => ['feedback', 'complaint'], 'target_types' => ['employee', 'organization'],
        'organization_id' => null, 'is_active' => true, 'sort_order' => 1,
    ]);
    $this->counterA = PublicFeedbackCategory::query()->create([
        'code' => 'COUNTER-A', 'name_en' => 'Licensing counter', 'name_am' => 'የፈቃድ መስኮት',
        'submission_types' => ['complaint'], 'target_types' => ['organization'],
        'organization_id' => $this->a->organization->id, 'is_active' => true, 'sort_order' => 2,
    ]);
    $this->onlyB = PublicFeedbackCategory::query()->create([
        'code' => 'ONLY-B', 'name_en' => 'B only', 'submission_types' => ['complaint', 'suggestion', 'feedback'],
        'target_types' => ['employee', 'organization'], 'organization_id' => $this->b->organization->id, 'is_active' => true, 'sort_order' => 3,
    ]);
    $this->retired = PublicFeedbackCategory::query()->create([
        'code' => 'OLD', 'name_en' => 'Old category', 'submission_types' => ['complaint'],
        'target_types' => ['employee', 'organization'], 'organization_id' => null, 'is_active' => false, 'sort_order' => 4,
    ]);
});

function pfcNames(array $categories): array
{
    return array_values(array_map(fn (array $category): string => $category['name'], $categories));
}

it('offers only active categories that apply to the type and the target', function (): void {
    $this->get('/service-feedback/'.$this->a->token->token.'/suggestion')
        ->assertInertia(fn (Assert $page) => $page->where('categories', []));

    $this->withHeader('X-Locale', 'am')
        ->get('/service-feedback/'.$this->a->token->token.'/complaint')
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => pfcNames($categories->toArray()) === ['የመጠበቂያ ጊዜ']));

    // The application locale persists between requests of one test; ask for English explicitly.
    $this->withHeader('X-Locale', 'en')
        ->get('/feedback/'.$this->orgToken.'/complaint')
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => pfcNames($categories->toArray()) === ['Waiting time', 'Licensing counter']));
});

it('stores the chosen category on the submission', function (): void {
    $this->post('/feedback/'.$this->orgToken.'/complaint', [
        'message' => 'The licensing counter closed early.',
        'category_id' => $this->counterA->id,
    ])->assertRedirect();

    expect(EmployeeServiceFeedback::query()->sole()->category_id)->toBe($this->counterA->id);
});

it('refuses a category that does not apply to the submission', function (): void {
    // Organization-only category on an employee QR.
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'x', 'category_id' => $this->counterA->id])
        ->assertSessionHasErrors('category_id');
    // Another institution's category.
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'y', 'category_id' => $this->onlyB->id])
        ->assertSessionHasErrors('category_id');
    // A retired category.
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'z', 'category_id' => $this->retired->id])
        ->assertSessionHasErrors('category_id');
    // Wrong submission type.
    $this->post('/feedback/'.$this->orgToken.'/suggestion', ['message' => 'w', 'category_id' => $this->cityWide->id])
        ->assertSessionHasErrors('category_id');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('lets a city-level user manage city-wide categories and a scoped admin only their institution\'s', function (): void {
    $payload = ['code' => 'NEW', 'name_en' => 'New category', 'name_am' => 'አዲስ ምድብ', 'submission_types' => ['feedback'], 'target_types' => ['employee'], 'is_active' => true, 'sort_order' => 9];

    $this->actingAs($this->superAdmin)->post(route('service-feedback.categories.store'), array_merge($payload, ['organization_id' => null]))->assertRedirect();
    expect(PublicFeedbackCategory::query()->where('code', 'NEW')->whereNull('organization_id')->exists())->toBeTrue();

    $this->actingAs($this->orgAdmin)->post(route('service-feedback.categories.store'), array_merge($payload, ['code' => 'NEW-CITY', 'organization_id' => null]))->assertForbidden();
    $this->actingAs($this->orgAdmin)->post(route('service-feedback.categories.store'), array_merge($payload, ['code' => 'NEW-B', 'organization_id' => $this->b->organization->id]))->assertForbidden();
    $this->actingAs($this->orgAdmin)->post(route('service-feedback.categories.store'), array_merge($payload, ['code' => 'NEW-A', 'organization_id' => $this->a->organization->id]))->assertRedirect();
    expect(PublicFeedbackCategory::query()->where('code', 'NEW-A')->value('organization_id'))->toBe($this->a->organization->id);

    $this->actingAs($this->orgAdmin)->put(route('service-feedback.categories.update', $this->cityWide), array_merge($payload, ['organization_id' => null, 'code' => 'WAITING']))->assertForbidden();
    $this->actingAs($this->orgAdmin)->put(route('service-feedback.categories.update', $this->counterA), array_merge($payload, ['organization_id' => $this->a->organization->id, 'code' => 'COUNTER-A', 'name_en' => 'Renamed counter']))->assertRedirect();
    expect($this->counterA->fresh()->name_en)->toBe('Renamed counter');
});

it('never deletes a referenced category; it is retired instead', function (): void {
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'Referenced.', 'category_id' => $this->counterA->id])->assertRedirect();

    $this->actingAs($this->superAdmin)->delete(route('service-feedback.categories.destroy', $this->counterA))->assertRedirect();
    expect($this->counterA->fresh())->not->toBeNull()
        ->and($this->counterA->fresh()->is_active)->toBeFalse()
        ->and(EmployeeServiceFeedback::query()->sole()->category_id)->toBe($this->counterA->id);

    $this->actingAs($this->superAdmin)->delete(route('service-feedback.categories.destroy', $this->onlyB))->assertRedirect();
    expect(PublicFeedbackCategory::query()->find($this->onlyB->id))->toBeNull();
});

it('lists categories within the user scope on the management page', function (): void {
    $this->actingAs($this->orgAdmin)
        ->get(route('service-feedback.categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/Categories/Index')
            ->where('categories', fn ($rows) => collect($rows->toArray())->pluck('code')->sort()->values()->all() === ['COUNTER-A', 'OLD', 'WAITING']));

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.categories.index'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($rows) => count($rows->toArray()) === 4));

    $plain = User::factory()->create();
    $this->actingAs($plain)->get(route('service-feedback.categories.index'))->assertForbidden();
});
