<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regulation 201/2018 Art. 20: the accused admits or denies each charge. The
 * charge row holds the current plea; each response version now also keeps
 * the pleas it gave, so a later response never erases an earlier admission
 * or denial from the record. Additive and nullable (earlier responses have none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disciplinary_responses', function (Blueprint $table): void {
            $table->json('pleas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('disciplinary_responses', function (Blueprint $table): void {
            $table->dropColumn('pleas');
        });
    }
};
