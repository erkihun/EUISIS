<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization scope becomes explicit (ADR 0008): an account or API client
 * reaches the whole city only with scope_type = citywide, an organization and
 * everything under it with subtree, or that organization alone with self. No
 * scope_type means no organizational data at all; an empty organization no
 * longer means "the whole city".
 *
 * Backfill keeps every existing grant as it was. Until now an empty scope
 * organization was the recorded way to choose "Whole city", so those rows
 * become citywide; rows with an organization become subtree, the only kind
 * the Core had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('scope_type', 16)->nullable()->after('scope_organization_id');
        });
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->string('scope_type', 16)->nullable()->after('owner_organization_id');
        });

        foreach (['users' => 'scope_organization_id', 'api_clients' => 'owner_organization_id'] as $table => $column) {
            DB::table($table)->whereNull($column)->update(['scope_type' => 'citywide']);
            DB::table($table)->whereNotNull($column)->update(['scope_type' => 'subtree']);
        }
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->dropColumn('scope_type');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('scope_type');
        });
    }
};
