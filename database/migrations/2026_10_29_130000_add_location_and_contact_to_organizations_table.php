<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's short name, official contact and location. The GPS point
 * and check-in radius pre-fill the geofence of field work sent to the
 * organization; contact and address go to letters, the public site and the
 * master-data API other systems read.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'short_name_en', 'short_name_am', 'official_email', 'website', 'po_box',
        'address_en', 'address_am', 'latitude', 'longitude', 'geofence_radius_m',
    ];

    public function up(): void
    {
        $add = fn (string $column): bool => ! Schema::hasColumn('organizations', $column);

        Schema::table('organizations', function (Blueprint $table) use ($add): void {
            if ($add('short_name_en')) {
                $table->string('short_name_en', 50)->nullable();
            }
            if ($add('short_name_am')) {
                $table->string('short_name_am', 50)->nullable();
            }
            if ($add('official_email')) {
                $table->string('official_email')->nullable();
            }
            if ($add('website')) {
                $table->string('website')->nullable();
            }
            if ($add('po_box')) {
                $table->string('po_box', 32)->nullable();
            }
            if ($add('address_en')) {
                $table->string('address_en', 500)->nullable();
            }
            if ($add('address_am')) {
                $table->string('address_am', 500)->nullable();
            }
            if ($add('latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }
            if ($add('longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }
            if ($add('geofence_radius_m')) {
                $table->unsignedInteger('geofence_radius_m')->nullable();
            }
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn('organizations', $column)));

        if ($present !== []) {
            Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
