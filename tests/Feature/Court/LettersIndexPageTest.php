<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Letter;
use Modules\Court\Domain\Models\LetterTemplate;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function lettersUser(array $permissions): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Letters '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

test('the letters list carries the figures, the status filter and each letter with its permissions', function (): void {
    $author = lettersUser(['letters.view', 'letters.approve', 'letters.delete']);
    $template = LetterTemplate::query()->create(['title' => 'Summons', 'category' => 'Summons', 'body' => '<p>Body</p>']);
    $approved = Letter::query()->create(['letter_template_id' => $template->id, 'user_id' => $author->id, 'subject' => 'Approved letter', 'reference_number' => 'REF-'.Str::random(5), 'body' => '<p>Body</p>', 'approval_status' => 'approved', 'approved_by_name' => 'Registrar', 'send_to_applicant' => true, 'send_to_respondent' => false]);
    $pending = Letter::query()->create(['letter_template_id' => $template->id, 'user_id' => $author->id, 'subject' => 'Pending letter', 'reference_number' => 'REF-'.Str::random(5), 'body' => '<p>Body</p>', 'approval_status' => 'pending', 'send_to_applicant' => false, 'send_to_respondent' => true]);

    $this->actingAs($author)->get(route('court.letters.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Letters/Index')
            ->where('stats.total', 2)
            ->where('stats.approved', 1)
            ->where('stats.top_category', 'Summons')
            ->where('can.approve', true)
            ->where('can.delete', true)
            ->where('can.create', false)
            ->where('letters.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $approved->id && $row['approved_by_name'] === 'Registrar' && $row['author_name'] === $author->name)));

    $this->actingAs($author)->get(route('court.letters.index', ['status' => 'pending']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'pending')
            ->where('letters.total', 1)
            ->where('letters.data.0.id', $pending->id));
});
