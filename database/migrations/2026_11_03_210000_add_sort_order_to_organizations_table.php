<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The order chosen by hand on the organizations list; blank rows sort after the ordered ones. */
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'sort_order')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
