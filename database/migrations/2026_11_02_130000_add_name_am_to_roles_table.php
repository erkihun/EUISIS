<?php

declare(strict_types=1);

use App\Support\Rbac\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An Amharic name for every role, shown beside the English one. The default
 * roles take theirs from DefaultRoleMatrix (filled in here and on every
 * reseed); custom roles get one on the role form.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'name_am')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->string('name_am')->nullable()->after('name');
            });
        }
        app(RbacSynchronizer::class)->ensureRoles(createMissing: false);
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'name_am')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropColumn('name_am');
            });
        }
    }
};
