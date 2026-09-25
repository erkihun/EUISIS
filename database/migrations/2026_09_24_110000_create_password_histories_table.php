<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Previous password HASHES for every password-authenticated account type
 * (users, provider users, ...), so a new password can be refused when it
 * matches one of the last N. Never plaintext, never reversible.
 *
 * Polymorphic by morph class; `authenticatable_id` is a string because the
 * account tables use both integer and UUID keys. Sensitive authentication
 * data: no UI, no API, no export (see docs/password-security-policy.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('authenticatable_type', 150);
            $table->string('authenticatable_id', 64);
            $table->string('password_hash', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['authenticatable_type', 'authenticatable_id', 'id'], 'password_histories_owner_index');
        });

        /*
         * Raise a stored minimum below the new floor so the settings screen
         * shows the value that is actually enforced (the policy clamps it
         * regardless).
         */
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('group', 'security')
                ->where('key', 'password_min_length')
                ->get(['id', 'value'])
                ->each(function (object $row): void {
                    if ((int) $row->value < 15) {
                        DB::table('system_settings')->where('id', $row->id)->update(['value' => '15']);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};
