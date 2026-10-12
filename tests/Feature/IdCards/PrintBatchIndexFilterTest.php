<?php

declare(strict_types=1);

use App\Models\CardPrintBatch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function printBatchIndexAdmin(): User
{
    Permission::findOrCreate('id-cards.createPrintBatch', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

it('filters the print batches index by search and status like every other list', function (): void {
    $user = printBatchIndexAdmin();
    CardPrintBatch::query()->create(['batch_number' => 'PB-ALPHA', 'status' => 'draft', 'created_by' => $user->id]);
    CardPrintBatch::query()->create(['batch_number' => 'PB-BETA', 'status' => 'completed', 'created_by' => $user->id]);

    $this->actingAs($user)
        ->get(route('print-batches.index', ['search' => 'alpha']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PrintBatches/Index')
            ->has('batches.data', 1)
            ->where('batches.data.0.batch_number', 'PB-ALPHA')
            ->where('filters.search', 'alpha')
        );

    $this->actingAs($user)
        ->get(route('print-batches.index', ['status' => 'completed']))
        ->assertInertia(fn ($page) => $page
            ->has('batches.data', 1)
            ->where('batches.data.0.batch_number', 'PB-BETA')
        );
});
