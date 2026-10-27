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
            $table->uuid('base_version_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('legal_basis')->nullable();
            $table->foreign('base_version_id')->references('id')->on('hierarchy_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hierarchy_versions', function (Blueprint $table): void {
            $table->dropForeign(['base_version_id']);
            $table->dropColumn(['base_version_id', 'created_by', 'reviewed_by', 'reviewed_at', 'legal_basis']);
        });
    }
};
