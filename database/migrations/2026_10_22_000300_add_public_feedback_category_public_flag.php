<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An issue category may be kept for internal classification without being
     * offered on the public form. Every existing category stays public, as it
     * was; archiving (is_active = false) still hides it everywhere.
     */
    public function up(): void
    {
        Schema::table('public_feedback_categories', function (Blueprint $table): void {
            $table->boolean('is_public_selectable')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('public_feedback_categories', function (Blueprint $table): void {
            $table->dropColumn('is_public_selectable');
        });
    }
};
