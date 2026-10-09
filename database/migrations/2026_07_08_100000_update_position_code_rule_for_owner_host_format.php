<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair the default job position code rule so codes are composed from the
 * owner organization code plus, when the unit operates inside a host
 * organization, the host organization code: OWNER/SEQ or OWNER/HOST/SEQ.
 *
 * Only rules still on the untouched seeded default ({PREFIX}-{SEQUENCE}) are
 * updated — admin-customized formats are left alone.
 */
return new class extends Migration
{
    private const OLD_FORMAT = '{PREFIX}-{SEQUENCE}';

    private const NEW_FORMAT = '{OWNER_ORG_CODE}/{HOST_ORG_CODE}/{SEQUENCE}';

    public function up(): void
    {
        DB::table('code_rules')
            ->where('entity_type', 'position')
            ->where('format', self::OLD_FORMAT)
            ->update([
                'format' => self::NEW_FORMAT,
                'separator' => '/',
                'sequence_length' => 2,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('code_rules')
            ->where('entity_type', 'position')
            ->where('format', self::NEW_FORMAT)
            ->update([
                'format' => self::OLD_FORMAT,
                'separator' => '-',
                'sequence_length' => 4,
                'updated_at' => now(),
            ]);
    }
};
