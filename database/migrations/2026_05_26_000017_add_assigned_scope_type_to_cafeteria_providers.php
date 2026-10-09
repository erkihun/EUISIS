<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            if (! Schema::hasColumn('cafeteria_providers', 'assigned_scope_type')) {
                $table->string('assigned_scope_type', 20)
                    ->default('self')
                    ->comment('self=exact org match; subtree=org or any descendant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cafeteria_providers', function (Blueprint $table): void {
            $table->dropColumn('assigned_scope_type');
        });
    }
};
