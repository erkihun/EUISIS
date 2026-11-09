<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hierarchy_versions', 'archive_snapshot')) {
            return;
        }

        Schema::table('hierarchy_versions', function (Blueprint $table): void {
            $table->json('archive_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hierarchy_versions', function (Blueprint $table): void {
            $table->dropColumn('archive_snapshot');
        });
    }
};
