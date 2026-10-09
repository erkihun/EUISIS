<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds deterministic hash columns alongside the encrypted national_id values.
 *
 * Why a hash column?
 * Once `national_id` is stored encrypted, every row's ciphertext is unique even
 * for identical plaintext, so `unique:` validation rules and direct lookups stop
 * working. A SHA-256 hash gives us a deterministic, searchable surrogate that
 * does not reveal the plaintext.
 *
 * Hash columns are not added for `phone_number` because uniqueness is not
 * enforced on phone numbers anywhere in the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'national_id_hash')) {
                $table->string('national_id_hash', 64)->nullable();
                $table->index('national_id_hash', 'users_national_id_hash_index');
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'national_id_hash')) {
                $table->string('national_id_hash', 64)->nullable();
                $table->index('national_id_hash', 'employees_national_id_hash_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_national_id_hash_index');
            $table->dropColumn('national_id_hash');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_national_id_hash_index');
            $table->dropColumn('national_id_hash');
        });
    }
};
