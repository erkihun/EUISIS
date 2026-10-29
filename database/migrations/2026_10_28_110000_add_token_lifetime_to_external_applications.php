<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TD-16: a per-application lifetime for API tokens. Null keeps the default
 * (SANCTUM_TOKEN_EXPIRATION_MINUTES, 8 hours); a system integration such as
 * court-ms or the cafeteria platform gets days, rotated before they run out.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_applications', 'token_lifetime_days')) {
            Schema::table('external_applications', function (Blueprint $table): void {
                $table->unsignedSmallInteger('token_lifetime_days')->nullable()->after('rate_limit_per_minute');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_applications', 'token_lifetime_days')) {
            Schema::table('external_applications', fn (Blueprint $table) => $table->dropColumn('token_lifetime_days'));
        }
    }
};
