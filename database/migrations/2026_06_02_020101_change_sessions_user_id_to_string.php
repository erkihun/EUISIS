<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sessions table was created with foreignId('user_id') → BIGINT UNSIGNED.
 * Multiple guards (web, provider) store sessions here; the provider guard uses
 * UUID primary keys. A UUID cannot fit in a BIGINT, causing a 500 on every
 * provider-portal page. Change the column to VARCHAR(36) so all guards coexist.
 *
 * Uses portable Schema Builder syntax (no raw MySQL ALTER TABLE) so this
 * migration also runs cleanly against SQLite in the test environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->string('user_id', 36)->nullable()->change();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->index('user_id');
        });
    }
};
