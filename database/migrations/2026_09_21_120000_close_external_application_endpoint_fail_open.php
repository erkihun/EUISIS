<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the endpoint-assignment fail-open (Phase-2 SEC2-001).
 *
 * `ExternalApplication::allowsEndpoint()` treated an application with NO
 * endpoint assignments as unrestricted, so a newly registered integration
 * could call every endpoint its token scopes permitted until an administrator
 * remembered to narrow it. That grace was meant only for integrations that
 * predate endpoint assignment.
 *
 * This makes the grace explicit instead of implicit:
 *
 *   - every application that exists right now and has no assignments keeps
 *     working exactly as before (`unrestricted_endpoints = true`),
 *   - every application created from now on is deny-by-default and must have
 *     its endpoints assigned before it can call anything.
 *
 * No live integration changes behaviour on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_applications', function (Blueprint $table): void {
            $table->boolean('unrestricted_endpoints')
                ->default(false)
                ->after('allowed_scopes')
                ->comment('Legacy grace: call any endpoint when none are assigned. New applications are deny-by-default.');
        });

        // Backfill only the applications that are relying on the fallback today.
        $legacyIds = DB::table('external_applications')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('external_application_endpoints')
                    ->whereColumn('external_application_endpoints.external_application_id', 'external_applications.id');
            })
            ->pluck('id');

        if ($legacyIds->isNotEmpty()) {
            DB::table('external_applications')
                ->whereIn('id', $legacyIds)
                ->update(['unrestricted_endpoints' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('external_applications', function (Blueprint $table): void {
            $table->dropColumn('unrestricted_endpoints');
        });
    }
};
