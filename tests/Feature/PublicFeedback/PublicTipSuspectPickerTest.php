<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Tests\Support\PublicFeedbackScenario;

/*
 * Owner request 2026-11-08: on an institution QR the Tip-off's "person
 * concerned" asks no sub-city / woreda (the QR names the institution); the
 * work unit is chosen from the institution's units and the person from that
 * unit's staff only. The server checks both against the scanned institution.
 */

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = PublicFeedbackScenario::employeeTarget('TSP');
    $this->s->unit->forceFill(['is_public_feedback_selectable' => true])->save();
    $this->token = app(OrganizationFeedbackQrService::class)->ensureActive($this->s->organization, User::factory()->create())->public_token;
    $this->other = PublicFeedbackScenario::employeeTarget('TSX');
    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

it('lists only the chosen unit staff of the scanned institution, by name and post', function (): void {
    $url = '/feedback/'.$this->token.'/staff';
    $this->getJson($url.'?unit='.$this->s->unit->id)->assertOk()
        ->assertJsonPath('items.0.id', $this->s->employee->id)
        ->assertJsonPath('items.0.name', $this->s->employee->full_name)
        ->assertJsonMissingPath('items.0.employee_number');
    $this->getJson($url.'?unit='.$this->s->unit->id.'&q=zzzz')->assertOk()->assertJsonCount(0, 'items');
    // Another institution's unit, or no unit, gives nothing.
    $this->getJson($url.'?unit='.$this->other->unit->id)->assertOk()->assertJsonCount(0, 'items');
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'items');
    $this->getJson('/feedback/unknown-token/staff?unit='.$this->s->unit->id)->assertOk()->assertJsonCount(0, 'items');
});

it('stores the chosen unit and person with the institution names, and refuses ones from elsewhere', function (): void {
    $post = fn (array $tip) => $this->post('/feedback/'.$this->token.'/suggestion', pfComplete('suggestion', [
        'message' => 'Asked for money at the counter.', 'tip_type' => 'CORRUPTION',
        'organization_unit_id' => $this->s->unit->id, 'unit_selection_mode' => 'SELECTED',
        'tip_form' => $tip,
    ]));

    $post(['suspect_unit_id' => $this->other->unit->id, 'suspect_unit' => 'Elsewhere'])->assertSessionHasErrors('tip_form.suspect_unit_id');
    $post(['suspect_unit_id' => $this->s->unit->id, 'suspect_employee_id' => $this->other->employee->id, 'suspect_name' => 'Someone'])->assertSessionHasErrors('tip_form.suspect_employee_id');
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);

    $post(['suspect_unit_id' => $this->s->unit->id, 'suspect_unit' => 'typed by the visitor', 'suspect_employee_id' => $this->s->employee->id, 'suspect_name' => 'typed'])->assertSessionHasNoErrors();
    $details = EmployeeServiceFeedback::query()->sole()->tip_details;
    expect($details['suspect_unit_id'])->toBe($this->s->unit->id)
        ->and($details['suspect_employee_id'])->toBe($this->s->employee->id)
        ->and($details['suspect_name'])->toBe($this->s->employee->full_name)
        ->and($details['suspect_unit'])->toBe($this->s->unit->name_en)
        ->and($details)->not->toHaveKey('suspect_location');
});

it('keeps a name typed without choosing from the list', function (): void {
    $this->post('/feedback/'.$this->token.'/suggestion', pfComplete('suggestion', [
        'message' => 'Asked for money.', 'tip_type' => 'CORRUPTION', 'organization_unit_id' => $this->s->unit->id, 'unit_selection_mode' => 'SELECTED',
        'tip_form' => ['suspect_unit_id' => $this->s->unit->id, 'suspect_name' => 'A guard at the gate'],
    ]))->assertSessionHasNoErrors();
    expect(EmployeeServiceFeedback::query()->sole()->tip_details['suspect_name'])->toBe('A guard at the gate');
});

it('lists every active unit of the institution for the person concerned, not only those offered for routing', function (): void {
    // Owner question 2026-11-08: the picker showed only the units marked for the public's routing choice.
    $this->s->unit->forceFill(['is_public_feedback_selectable' => false])->save();

    $this->get('/feedback/'.$this->token.'/suggestion')->assertOk()
        ->assertInertia(fn ($page) => $page->where('suspectUnits', fn ($units) => collect($units)->pluck('id')->contains($this->s->unit->id))
            ->where('offices', fn ($units) => collect($units)->pluck('id')->contains($this->s->unit->id)));
    $this->getJson('/feedback/'.$this->token.'/staff?unit='.$this->s->unit->id)->assertOk()->assertJsonPath('items.0.id', $this->s->employee->id);
    // Another institution's unit is still refused.
    $this->getJson('/feedback/'.$this->token.'/staff?unit='.$this->other->unit->id)->assertOk()->assertJsonCount(0, 'items');
});
