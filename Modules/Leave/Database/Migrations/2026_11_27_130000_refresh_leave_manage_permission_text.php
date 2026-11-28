<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/*
 * leave.manage no longer lets an officer enter leave for an employee: a
 * request is always made by the employee, signed in with their own account
 * (owner rule, 2026-11-27). The permission keeps its name and its holders;
 * only its label and description change, re-read here from the catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RbacSynchronizer::class)->syncCatalog();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Text only: nothing to undo.
    }
};
