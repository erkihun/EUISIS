<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Decision D-1: national_id_hash becomes a keyed HMAC. Rewrites the existing
 * unkeyed hashes so lookups and the unique index keep working. Idempotent;
 * no plaintext is stored or logged. Rows are decrypted with APP_KEY, so this
 * must run with the environment's real APP_KEY.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Artisan::call('security:rehash-national-ids');
    }

    public function down(): void
    {
        // The unkeyed hash is deliberately not restored.
    }
};
