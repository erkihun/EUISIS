<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hierarchy_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('hierarchy_versions', 'notes')) {
                $table->text('notes')->nullable()->after('source_document');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hierarchy_versions', function (Blueprint $table): void {
            if (Schema::hasColumn('hierarchy_versions', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
