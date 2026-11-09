<?php

declare(strict_types=1);

// migration-safety: reviewed — data only: each both-types routing rule becomes one rule per module; nothing is deleted.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tip-offs and complaints are separate modules with their own routing-rule
 * pages (/tip-off-admin, /complaint-admin). A rule written for both types
 * (submission_type NULL) becomes the Tip-off rule it already was, plus the
 * same rule copied for complaints. A rule narrowed to a Tip-off type only
 * ever matched Tip-offs, so it becomes a Tip-off rule only. Assignment
 * history keeps pointing at the original row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('public_feedback_routing_rules')->whereNull('submission_type')->get();
            foreach ($rows as $row) {
                DB::table('public_feedback_routing_rules')->where('id', $row->id)->update(['submission_type' => 'suggestion']);
                if ($row->tip_type !== null) {
                    continue;
                }
                $copy = (array) $row;
                $copy['id'] = (string) Str::uuid();
                $copy['submission_type'] = 'complaint';
                $copy['created_at'] = now();
                $copy['updated_at'] = now();
                DB::table('public_feedback_routing_rules')->insert($copy);
            }
        });
    }

    public function down(): void
    {
        // Not merged back: after the split each module's rules may have been edited on their own.
    }
};
