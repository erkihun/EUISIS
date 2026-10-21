<?php

declare(strict_types=1);

use App\Actions\Employees\RegisterEmployeeAction;
use App\Enums\EmployeeStatus;
use App\Jobs\Communication\SyncEmployeeCommunicationMemberships;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * A ShouldBeUnique job takes its lock when it is dispatched, even with
 * ->afterCommit(). With the database cache store the lock is an INSERT into
 * cache_locks on the application's own connection; when the key already
 * exists the INSERT fails, and on PostgreSQL that aborts the surrounding
 * transaction (SQLSTATE 25P02). Registering an employee dispatched the
 * membership sync twice inside one transaction, so every registration and
 * the demo seeder failed on PostgreSQL. The sync is now dispatched after
 * the transaction commits, so the lock is taken outside it.
 */

beforeEach(function (): void {
    config(['cache.default' => 'database']);
});

it('registers an employee when unique-job locks live in the database cache', function (): void {
    Queue::fake();
    $type = OrganizationType::query()->create(['code' => 'SYNC-T', 'name_en' => 'Sync type']);
    $org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SYNC-ORG', 'name_en' => 'Sync org', 'status' => 'active']);
    $register = fn (string $number): Employee => app(RegisterEmployeeAction::class)->execute(
        ['employee_number' => $number, 'first_name' => 'Sync', 'last_name' => $number, 'full_name' => "Sync {$number}", 'status' => EmployeeStatus::Active],
        ['organization_id' => $org->id, 'effective_from' => now()->toDateString()],
        User::factory()->create(),
    );

    $first = $register('SYNC-1');
    $second = $register('SYNC-2');

    expect($first->fresh()->current_assignment_id)->not->toBeNull()
        ->and($second->fresh()->current_assignment_id)->not->toBeNull();
    // Unique per employee: the assignment and the employee save collapse into one sync each.
    Queue::assertPushed(SyncEmployeeCommunicationMemberships::class, 2);
    Queue::assertPushed(SyncEmployeeCommunicationMemberships::class, fn ($job) => $job->employeeId === (string) $first->id);
    Queue::assertPushed(SyncEmployeeCommunicationMemberships::class, fn ($job) => $job->employeeId === (string) $second->id);
});

it('does not queue the sync when the transaction rolls back', function (): void {
    Queue::fake();
    $type = OrganizationType::query()->create(['code' => 'SYNC-R', 'name_en' => 'Rollback type']);
    $org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SYNC-RB', 'name_en' => 'Rollback org', 'status' => 'active']);

    try {
        DB::transaction(function () use ($org): void {
            app(RegisterEmployeeAction::class)->execute(
                ['employee_number' => 'SYNC-RB-1', 'first_name' => 'Roll', 'last_name' => 'Back', 'full_name' => 'Roll Back', 'status' => EmployeeStatus::Active],
                ['organization_id' => $org->id, 'effective_from' => now()->toDateString()],
                User::factory()->create(),
            );

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
        // expected
    }

    Queue::assertNotPushed(SyncEmployeeCommunicationMemberships::class);
    expect(DB::table('cache_locks')->count())->toBe(0);
});
