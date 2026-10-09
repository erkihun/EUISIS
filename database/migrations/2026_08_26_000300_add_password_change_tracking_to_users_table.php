<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track whether a user must change their password before continuing.
 *
 * An account created by an administrator carries a password that administrator
 * knows. Until the holder replaces it, the credential is effectively shared, so
 * `must_change_password` gates every protected route until they do.
 *
 * Existing users are deliberately left at `false`. Flipping the flag on for
 * everyone would lock out the entire user base at the next login, which is a
 * migration deciding an operational policy it has no business deciding.
 *
 * `last_login_at` already exists on this table and is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->index();
            }

            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'first_login_at')) {
                $table->timestamp('first_login_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['must_change_password']);
            $table->dropColumn(['must_change_password', 'password_changed_at', 'first_login_at']);
        });
    }
};
