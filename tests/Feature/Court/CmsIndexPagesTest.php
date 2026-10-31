<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\AboutPage;
use Modules\Court\Domain\Models\Announcement;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\TermsAndCondition;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Inertia\Testing\AssertableInertia;

function cmsAdmin(): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $user;
}

test('the announcements list shows each announcement with its state', function (): void {
    $admin = cmsAdmin();
    $announcement = Announcement::query()->create(['title' => 'Court closed on Friday', 'content' => '<p>Holiday.</p>', 'status' => 'active']);

    $this->actingAs($admin)->get(route('court.announcements.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Announcements/Index')
            ->where('announcements.data.0.id', $announcement->id)
            ->where('announcements.data.0.status', 'active'));
});

test('the terms list shows each text with its publication state', function (): void {
    $admin = cmsAdmin();
    $term = TermsAndCondition::query()->create(['title' => 'Filing terms', 'slug' => 'filing-terms', 'body' => '<p>Read before filing.</p>', 'is_published' => true, 'published_at' => now()]);

    $this->actingAs($admin)->get(route('court.terms.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Terms/Index')
            ->where('terms.data.0.id', $term->id)
            ->where('terms.data.0.is_published', true)
            ->where('terms.data.0.excerpt', 'Read before filing.'));
});

test('the About pages list shows each page with its slug and state', function (): void {
    $admin = cmsAdmin();
    $about = AboutPage::query()->create(['title' => 'About the tribunal', 'slug' => 'about-the-tribunal', 'body' => '<p>Who we are.</p>', 'is_published' => false, 'created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);

    $this->actingAs($admin)->get(route('court.about.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/About/Index')
            ->where('pages.data.0.id', $about->id)
            ->where('pages.data.0.slug', 'about-the-tribunal')
            ->where('pages.data.0.is_published', false));
});
