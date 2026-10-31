<?php

declare(strict_types=1);

/*
 * The hearing list's filters are query-string input: a malformed or
 * impossible date, or an array in place of a value, is ignored instead of
 * reaching the query (MySQL rejects an invalid DATE literal with a 500).
 */

require_once __DIR__.'/JudicialWorkHelpers.php';

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

test('a malformed hearing date filter is ignored instead of failing', function (string $date): void {
    $viewer = jwStaff(['cases.view']);
    $case = jwCase();
    DB::table('court_case_hearings')->insert([
        'case_id' => $case->id, 'hearing_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
        'type' => 'Mention', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('court.admin.hearings.index').'?hearing_date='.rawurlencode($date))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Hearings/Index')
            ->where('filters.hearing_date', '')
            ->where('hearings.total', 1)
            ->etc());
})->with([
    'not a date' => 'abc',
    'impossible month' => '2026-13-45',
    'impossible day' => '2026-02-30',
    'quote' => "2026-01-01' OR '1'='1",
    'other format' => '01/02/2026',
]);

test('array filters are ignored and a valid date still filters', function (): void {
    $viewer = jwStaff(['cases.view']);
    $case = jwCase();
    $day = now()->addDays(2);
    DB::table('court_case_hearings')->insert([
        ['case_id' => $case->id, 'hearing_at' => $day->copy()->setTime(9, 0)->format('Y-m-d H:i:s'), 'type' => 'Mention', 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $case->id, 'hearing_at' => $day->copy()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'), 'type' => 'Ruling', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($viewer)
        ->get(route('court.admin.hearings.index').'?hearing_date[]=x&case_number[]=y')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', ['case_number' => '', 'hearing_date' => ''])
            ->where('hearings.total', 2)
            ->etc());

    $this->actingAs($viewer)
        ->get(route('court.admin.hearings.index', ['hearing_date' => $day->toDateString()]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.hearing_date', $day->toDateString())
            ->where('hearings.total', 1)
            ->where('hearings.data.0.type', 'Mention')
            ->etc());
});
