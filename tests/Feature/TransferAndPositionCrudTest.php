<?php

declare(strict_types=1);

/**
 * Transfer tests have been moved to TransferModuleTest.php
 * This file retains position CRUD tests only.
 */

use App\Models\Position;
use App\Models\User;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    foreach ([
        'positions.viewAny', 'positions.view', 'positions.create',
        'positions.update', 'positions.archive', 'positions.restore',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

it('requires authentication for the positions index', function (): void {
    $this->get(route('positions.index'))
        ->assertRedirect(route('login'));
});

it('blocks positions index without permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('positions.index'))->assertForbidden();
});

it('allows positions index with permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('positions.viewAny');

    $this->actingAs($user)->get(route('positions.index'))->assertOk();
});

it('exposes the new position fields and localized standard-name labels', function (): void {
    $createPage = file_get_contents(resource_path('js/Pages/Positions/Create.tsx'));
    $editPage = file_get_contents(resource_path('js/Pages/Positions/Edit.tsx'));
    $indexPage = file_get_contents(resource_path('js/Pages/Positions/Index.tsx'));
    $showPage = file_get_contents(resource_path('js/Pages/Positions/Show.tsx'));
    $english = file_get_contents(resource_path('js/i18n/en/positions.ts'));
    $amharic = file_get_contents(resource_path('js/i18n/am/positions.ts'));

    expect($createPage)->toContain('old_code', 'bpr_name', 'positions.englishTitle', 'positions.amharicTitle')
        ->and($editPage)->toContain('old_code', 'bpr_name', 'positions.englishTitle', 'positions.amharicTitle')
        ->and($indexPage)->toContain('positions.oldCode', 'positions.bprName', 'positions.standardName')
        ->and($showPage)->toContain('positions.oldCode', 'positions.bprName', 'positions.standardName', 'LocalizedDateDisplay')
        ->and($english)->toContain("standardName: 'Standard Name'", "oldCode: 'Old Code'", "bprName: 'BPR Name'")
        ->and($amharic)->toContain('standardName:', 'oldCode:', 'bprName:');
});
