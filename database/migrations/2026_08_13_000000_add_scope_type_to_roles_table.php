<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('scope_type', 16)->default('scoped')->index();
        });

        DB::table('roles')
            ->whereIn('name', [
                'Super Admin',
                'System Admin',
                'City Admin',
                'Public Service Bureau Admin',
                'Security Settings Manager',
            ])
            ->update(['scope_type' => 'global']);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['scope_type']);
            $table->dropColumn('scope_type');
        });
    }
};
